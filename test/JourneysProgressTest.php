<?php

/**
 * Phase 2 — Progress service layer (meta) tests.
 *
 * Verifies DT_Journeys_Progress: status transitions, the sequential
 * completion cascade (remaining stages -> skipped), non-sequential
 * completion, the dt_journeys_active marker meta, and activity-log entries.
 */
class JourneysProgressTest extends TestCase {

    private $contact_id;

    public function setUp(): void {
        parent::setUp();
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );

        $contact = DT_Posts::create_post( 'contacts', [ 'name' => 'Test Contact' ], true, false );
        $this->assertNotWPError( $contact );
        $this->contact_id = $contact['ID'];
    }

    private function create_journey( bool $is_sequential, int $stage_count = 3 ) {
        $journey = DT_Posts::create_post( 'journeys', [
            'name'          => 'Test Journey',
            'is_sequential' => $is_sequential,
        ], true, false );
        $this->assertNotWPError( $journey );

        $stage_ids = [];
        foreach ( range( 1, $stage_count ) as $i ) {
            $stage = DT_Posts::create_post( 'journey_stages', [
                'name'        => "Stage $i",
                'stage_order' => $i,
            ], true, false );
            $this->assertNotWPError( $stage );
            $stage_ids[] = $stage['ID'];
        }

        DT_Posts::update_post( 'journeys', $journey['ID'], [
            'stages' => [
                'values' => array_map( function ( $id ) {
                    return [ 'value' => $id ];
                }, $stage_ids ),
            ],
        ], true, false );

        return [ $journey['ID'], $stage_ids ];
    }

    private function count_activity( int $post_id, string $action ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->dt_activity_log WHERE object_id = %d AND action = %s",
            $post_id,
            $action
        ) );
    }

    public function test_start_journey_initializes_stages_and_marker() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );

        $progress = DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );
        $this->assertNotWPError( $progress );
        $this->assertSame( 'active', $progress['status'] );
        $this->assertCount( 3, $progress['stages'] );
        foreach ( $stage_ids as $stage_id ) {
            $this->assertSame( 'not_started', $progress['stages'][ (string) $stage_id ]['status'] );
        }

        $marker = get_post_meta( $this->contact_id, DT_Journeys_Progress::ACTIVE_MARKER_KEY );
        $this->assertContains( (string) $journey_id, array_map( 'strval', $marker ) );

        $this->assertSame( 1, $this->count_activity( $this->contact_id, 'journeys_started' ) );
    }

    public function test_set_stage_status_records_note_and_activity() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );

        $progress = DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'complete', 'Finished the first stage' );
        $this->assertNotWPError( $progress );
        $this->assertSame( 'complete', $progress['stages'][ (string) $stage_ids[0] ]['status'] );
        $this->assertSame( 'Finished the first stage', $progress['stages'][ (string) $stage_ids[0] ]['note'] );

        $this->assertSame( 1, $this->count_activity( $this->contact_id, 'journeys_stage_status' ) );

        $comments = get_comments( [ 'post_id' => $this->contact_id, 'type' => DT_Journeys_Progress::COMMENT_TYPE ] );
        $this->assertCount( 1, $comments );
        $this->assertSame( 'Finished the first stage', $comments[0]->comment_content );
    }

    public function test_invalid_stage_status_returns_error() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        $result = DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'bogus' );
        $this->assertWPError( $result );
    }

    public function test_completing_all_stages_auto_completes_journey() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );

        foreach ( $stage_ids as $stage_id ) {
            DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_id, 'complete' );
        }

        $progress = DT_Journeys_Progress::get_progress( 'contacts', $this->contact_id, $journey_id );
        $this->assertSame( 'completed', $progress['status'] );
        $this->assertNotNull( $progress['completed_date'] );

        // journey is no longer active
        $marker = get_post_meta( $this->contact_id, DT_Journeys_Progress::ACTIVE_MARKER_KEY );
        $this->assertNotContains( (string) $journey_id, array_map( 'strval', $marker ) );

        $this->assertSame( 1, $this->count_activity( $this->contact_id, 'journeys_completed' ) );
    }

    public function test_sequential_forced_completion_skips_remaining_stages() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );
        DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'complete' );

        $progress = DT_Journeys_Progress::complete_journey( 'contacts', $this->contact_id, $journey_id, true );
        $this->assertNotWPError( $progress );
        $this->assertSame( 'completed', $progress['status'] );

        $this->assertSame( 'complete', $progress['stages'][ (string) $stage_ids[0] ]['status'] );
        $this->assertSame( 'skipped', $progress['stages'][ (string) $stage_ids[1] ]['status'] );
        $this->assertSame( 'skipped', $progress['stages'][ (string) $stage_ids[2] ]['status'] );
    }

    public function test_non_sequential_forced_completion_leaves_remaining_stages() {
        list( $journey_id, $stage_ids ) = $this->create_journey( false );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );
        DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'complete' );

        $progress = DT_Journeys_Progress::complete_journey( 'contacts', $this->contact_id, $journey_id, true );
        $this->assertNotWPError( $progress );
        $this->assertSame( 'completed', $progress['status'] );

        // non-sequential: untouched stages remain as they were, not forced to skipped
        $this->assertSame( 'not_started', $progress['stages'][ (string) $stage_ids[1] ]['status'] );
        $this->assertSame( 'not_started', $progress['stages'][ (string) $stage_ids[2] ]['status'] );
    }

    public function test_complete_journey_without_start_returns_error() {
        list( $journey_id ) = $this->create_journey( true );
        $result = DT_Journeys_Progress::complete_journey( 'contacts', $this->contact_id, $journey_id );
        $this->assertWPError( $result );
    }

    public function test_stage_added_after_start_is_backfilled_as_not_started() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true, 1 );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );

        // Add a second stage to the journey after progress has already started.
        $new_stage = DT_Posts::create_post( 'journey_stages', [ 'name' => 'Late Stage', 'stage_order' => 2 ], true, false );
        $this->assertNotWPError( $new_stage );
        DT_Posts::update_post( 'journeys', $journey_id, [
            'stages' => [ 'values' => [ [ 'value' => $new_stage['ID'] ] ] ],
        ], true, false );

        $progress = DT_Journeys_Progress::get_progress( 'contacts', $this->contact_id, $journey_id );
        $this->assertArrayHasKey( (string) $new_stage['ID'], $progress['stages'], 'the new stage should be backfilled into progress' );
        $this->assertSame( 'not_started', $progress['stages'][ (string) $new_stage['ID'] ]['status'] );

        // The backfill should also be persisted, not just returned in-memory.
        $raw = get_post_meta( $this->contact_id, DT_Journeys_Progress::META_KEY, true );
        $this->assertArrayHasKey( (string) $new_stage['ID'], $raw[ (string) $journey_id ]['stages'] );

        // Completing only the original stage should not auto-complete the journey.
        DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'complete' );
        $progress = DT_Journeys_Progress::get_progress( 'contacts', $this->contact_id, $journey_id );
        $this->assertSame( 'active', $progress['status'] );

        // Completing the backfilled stage too finishes the journey.
        $progress = DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $new_stage['ID'], 'complete' );
        $this->assertSame( 'completed', $progress['status'] );
    }

    public function test_completed_journey_is_not_backfilled() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true, 1 );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );
        DT_Journeys_Progress::complete_journey( 'contacts', $this->contact_id, $journey_id, true );

        $new_stage = DT_Posts::create_post( 'journey_stages', [ 'name' => 'Late Stage', 'stage_order' => 2 ], true, false );
        DT_Posts::update_post( 'journeys', $journey_id, [
            'stages' => [ 'values' => [ [ 'value' => $new_stage['ID'] ] ] ],
        ], true, false );

        $progress = DT_Journeys_Progress::get_progress( 'contacts', $this->contact_id, $journey_id );
        $this->assertArrayNotHasKey( (string) $new_stage['ID'], $progress['stages'], 'a completed journey should not be perturbed by later definition changes' );
    }
}
