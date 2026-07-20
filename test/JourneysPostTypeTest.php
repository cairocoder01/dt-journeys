<?php

/**
 * Phase 1 — Definition data model tests.
 *
 * Verifies the journeys + journey_stages post types, their fields, the
 * journeys_to_stages connection, and stage_order sorting.
 */
class JourneysPostTypeTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        // Act as an administrator so connection/activity writes have a user context.
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );
    }

    public function test_post_types_are_registered() {
        $post_types = DT_Posts::get_post_types();
        $this->assertContains( 'journeys', $post_types );
        $this->assertContains( 'journey_stages', $post_types );
    }

    public function test_journey_fields_exist() {
        $fields = DT_Posts::get_post_field_settings( 'journeys', false );
        foreach ( [ 'journey_category', 'journey_roles', 'is_sequential', 'display_type', 'stages', 'next_journey' ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "journeys missing field: $key" );
        }
        $this->assertSame( 'tags', $fields['journey_category']['type'] );
        $this->assertSame( 'key_select', $fields['display_type']['type'] );
        $this->assertSame( 'connection', $fields['stages']['type'] );
        $this->assertSame( 'journey_stages', $fields['stages']['post_type'] );
        $this->assertSame( 'journeys_to_stages', $fields['stages']['p2p_key'] );

        // display_type seeded options
        $this->assertArrayHasKey( 'timeline', $fields['display_type']['default'] );
        $this->assertArrayHasKey( 'list', $fields['display_type']['default'] );
        $this->assertArrayHasKey( 'grid', $fields['display_type']['default'] );

        // location fields are not relevant to a journey template
        $this->assertArrayNotHasKey( 'location_grid', $fields );
        $this->assertArrayNotHasKey( 'location_grid_meta', $fields );
    }

    public function test_journey_stage_fields_exist() {
        $fields = DT_Posts::get_post_field_settings( 'journey_stages', false );
        foreach ( [ 'description', 'instructions', 'attachments', 'related_fields', 'success_action_label', 'stage_order', 'journey' ] as $key ) {
            $this->assertArrayHasKey( $key, $fields, "journey_stages missing field: $key" );
        }
        $this->assertSame( 'connection', $fields['journey']['type'] );
        $this->assertSame( 'journeys', $fields['journey']['post_type'] );
        $this->assertSame( 'journeys_to_stages', $fields['journey']['p2p_key'] );
        $this->assertSame( 'number', $fields['stage_order']['type'] );

        // location fields are not relevant to a stage
        $this->assertArrayNotHasKey( 'location_grid', $fields );
        $this->assertArrayNotHasKey( 'location_grid_meta', $fields );
    }

    public function test_related_fields_option_registry_round_trips_a_selected_value() {
        // DT_Posts silently drops any multi_select value not present in the
        // field's 'default' option registry on read -- an empty registry
        // would make every selection vanish. Assert it's populated and that a
        // real contact field key survives create -> get.
        $fields = DT_Posts::get_post_field_settings( 'journey_stages', false );
        $this->assertNotEmpty( $fields['related_fields']['default'], 'related_fields must have a non-empty option registry' );
        $this->assertArrayHasKey( 'overall_status', $fields['related_fields']['default'] );

        $stage = DT_Posts::create_post( 'journey_stages', [
            'name'           => 'Stage with related field',
            'related_fields' => [ 'values' => [ [ 'value' => 'overall_status' ] ] ],
        ], true, false );
        $this->assertNotWPError( $stage );

        $fetched = DT_Posts::get_post( 'journey_stages', $stage['ID'], false, false );
        $this->assertContains( 'overall_status', $fetched['related_fields'] );
    }

    public function test_manage_journeys_capability_registered() {
        $capabilities = apply_filters( 'dt_capabilities', [] );
        $this->assertArrayHasKey( 'manage_journeys', $capabilities );
    }

    public function test_non_admin_can_view_journey_created_by_another_user() {
        // Journeys are shared ministry-model templates: a regular user must be
        // able to view one they didn't personally create (e.g. to attach it to
        // their own contact), even though editing/deleting stays restricted.
        $journey = DT_Posts::create_post( 'journeys', [ 'name' => 'Shared Journey' ], true, false );
        $this->assertNotWPError( $journey );

        $multiplier_id = $this->factory()->user->create( [ 'role' => 'multiplier' ] );
        wp_set_current_user( $multiplier_id );

        $fetched = DT_Posts::get_post( 'journeys', $journey['ID'] );
        $this->assertNotWPError( $fetched );
        $this->assertSame( $journey['ID'], $fetched['ID'] );
    }

    public function test_create_journey_with_ordered_stages() {
        // Create the journey definition.
        $journey = DT_Posts::create_post( 'journeys', [
            'name'             => 'Test Journey',
            'journey_category' => [ 'values' => [ [ 'value' => 'discipleship' ] ] ],
            'display_type'     => 'timeline',
            'is_sequential'    => true,
        ], true, false );
        $this->assertNotWPError( $journey );
        $journey_id = $journey['ID'];

        // Create three stages, deliberately out of order.
        $stage_c = DT_Posts::create_post( 'journey_stages', [ 'name' => 'Stage C', 'stage_order' => 3 ], true, false );
        $stage_a = DT_Posts::create_post( 'journey_stages', [ 'name' => 'Stage A', 'stage_order' => 1 ], true, false );
        $stage_b = DT_Posts::create_post( 'journey_stages', [ 'name' => 'Stage B', 'stage_order' => 2 ], true, false );
        foreach ( [ $stage_a, $stage_b, $stage_c ] as $stage ) {
            $this->assertNotWPError( $stage );
        }

        // Connect the stages to the journey (in scrambled order).
        $updated = DT_Posts::update_post( 'journeys', $journey_id, [
            'stages' => [
                'values' => [
                    [ 'value' => $stage_c['ID'] ],
                    [ 'value' => $stage_a['ID'] ],
                    [ 'value' => $stage_b['ID'] ],
                ],
            ],
        ], true, false );
        $this->assertNotWPError( $updated );

        // Fetch the journey fresh (bypass cache) and assert the connection + order.
        $fetched = DT_Posts::get_post( 'journeys', $journey_id, false, false );
        $this->assertNotWPError( $fetched );
        $this->assertCount( 3, $fetched['stages'], 'journey should have 3 connected stages' );

        $ordered_ids = array_map( function ( $s ) {
            return $s['ID'];
        }, $fetched['stages'] );
        $this->assertSame(
            [ $stage_a['ID'], $stage_b['ID'], $stage_c['ID'] ],
            $ordered_ids,
            'stages should be sorted by stage_order'
        );

        // The reverse connection resolves back to the journey.
        $fetched_stage = DT_Posts::get_post( 'journey_stages', $stage_a['ID'], false, false );
        $this->assertNotWPError( $fetched_stage );
        $this->assertCount( 1, $fetched_stage['journey'] );
        $this->assertSame( $journey_id, $fetched_stage['journey'][0]['ID'] );
    }
}
