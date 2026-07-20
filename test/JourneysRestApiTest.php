<?php

/**
 * Phase 3 — REST API tests (dt-journeys/v1).
 *
 * Dispatches real WP_REST_Request objects against a freshly-built
 * WP_REST_Server so route registration, permission callbacks, and payload
 * shape are all exercised the way an actual HTTP request would hit them.
 */
class JourneysRestApiTest extends TestCase {

    /** @var WP_REST_Server */
    private $server;
    private $contact_id;

    public function setUp(): void {
        parent::setUp();

        if ( !class_exists( 'Dt_Journeys_Endpoints' ) ) {
            require_once dirname( __DIR__ ) . '/rest-api/rest-api.php';
        }

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action( 'rest_api_init', $this->server );
        // Belt-and-suspenders: WP_UnitTestCase's per-test hook backup/restore can
        // drop the singleton's original `rest_api_init` registration, so
        // register our routes on the fresh server directly too.
        Dt_Journeys_Endpoints::instance()->add_api_routes();

        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );

        $contact = DT_Posts::create_post( 'contacts', [ 'name' => 'Test Contact' ], true, false );
        $this->assertNotWPError( $contact );
        $this->contact_id = $contact['ID'];
    }

    private function create_journey( bool $is_sequential, int $stage_count = 2, array $extra_fields = [] ) {
        $journey = DT_Posts::create_post( 'journeys', array_merge( [
            'name'          => 'Test Journey',
            'is_sequential' => $is_sequential,
        ], $extra_fields ), true, false );
        $this->assertNotWPError( $journey );

        $stage_ids = [];
        foreach ( range( 1, $stage_count ) as $i ) {
            $stage = DT_Posts::create_post( 'journey_stages', [
                'name'        => "Stage $i",
                'description' => "Stage $i description",
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

    private function dispatch( string $method, string $route, array $params = [] ) {
        $request = new WP_REST_Request( $method, $route );
        if ( $method === 'GET' ) {
            $request->set_query_params( $params );
        } else {
            $request->set_body_params( $params );
        }
        return $this->server->dispatch( $request );
    }

    public function test_get_record_returns_attached_journey_with_progress() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );
        DT_Journeys_Progress::set_stage_status( 'contacts', $this->contact_id, $journey_id, $stage_ids[0], 'complete' );

        $response = $this->dispatch( 'GET', "/dt-journeys/v1/record/contacts/{$this->contact_id}" );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertCount( 1, $data['journeys'] );
        $journey = $data['journeys'][0];
        $this->assertSame( $journey_id, $journey['ID'] );
        $this->assertSame( 'active', $journey['status'] );
        $this->assertCount( 2, $journey['stages'] );
        $this->assertSame( 'complete', $journey['stages'][0]['status'] );
        $this->assertSame( 'not_started', $journey['stages'][1]['status'] );

        // The 'stages' connection field only embeds a lightweight preview
        // (ID, post_title, ...) -- the endpoint must fetch each stage's full
        // record so its own fields (name, description, ...) aren't blank.
        $this->assertSame( 'Stage 1', $journey['stages'][0]['name'] );
        $this->assertSame( 'Stage 1 description', $journey['stages'][0]['description'] );
        $this->assertSame( 'Stage 2', $journey['stages'][1]['name'] );
    }

    public function test_get_record_includes_group_rollup_for_contacts() {
        $group = DT_Posts::create_post( 'groups', [
            'title'   => 'Test Group',
            'members' => [ 'values' => [ [ 'value' => $this->contact_id ] ] ],
        ], true, false );
        $this->assertNotWPError( $group );

        list( $journey_id ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'groups', $group['ID'], $journey_id );

        $response = $this->dispatch( 'GET', "/dt-journeys/v1/record/contacts/{$this->contact_id}" );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertCount( 1, $data['group_journeys'] );
        $this->assertSame( $group['ID'], $data['group_journeys'][0]['group_id'] );
        $this->assertSame( $journey_id, $data['group_journeys'][0]['journeys'][0]['ID'] );
    }

    public function test_get_available_excludes_active_journey() {
        list( $active_id ) = $this->create_journey( true );
        list( $inactive_id ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $active_id );

        $response = $this->dispatch( 'GET', "/dt-journeys/v1/available/contacts/{$this->contact_id}" );
        $this->assertSame( 200, $response->get_status() );

        $ids = array_column( $response->get_data()['journeys'], 'ID' );
        $this->assertNotContains( $active_id, $ids );
        $this->assertContains( $inactive_id, $ids );
    }

    public function test_get_available_filters_by_journey_roles_for_non_admin() {
        list( $matching_id ) = $this->create_journey( true, 2, [
            'journey_roles' => [ 'values' => [ [ 'value' => 'multiplier' ] ] ],
        ] );
        list( $non_matching_id ) = $this->create_journey( true, 2, [
            'journey_roles' => [ 'values' => [ [ 'value' => 'administrator' ] ] ],
        ] );

        // multiplier is DT's standard base user role: access_contacts, but no manage_dt,
        // so the role filter actually applies (an admin would bypass it entirely).
        $user_id = $this->factory()->user->create( [ 'role' => 'multiplier' ] );
        DT_Posts::update_post( 'contacts', $this->contact_id, [ 'assigned_to' => $user_id ], true, false );
        wp_set_current_user( $user_id );

        $response = $this->dispatch( 'GET', "/dt-journeys/v1/available/contacts/{$this->contact_id}" );
        $this->assertSame( 200, $response->get_status() );

        $ids = array_column( $response->get_data()['journeys'], 'ID' );
        $this->assertContains( $matching_id, $ids );
        $this->assertNotContains( $non_matching_id, $ids );
    }

    public function test_start_journey_via_rest() {
        list( $journey_id ) = $this->create_journey( true );

        $response = $this->dispatch( 'POST', "/dt-journeys/v1/start/contacts/{$this->contact_id}", [
            'journey_id' => $journey_id,
        ] );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'active', $response->get_data()['status'] );

        $progress = DT_Journeys_Progress::get_progress( 'contacts', $this->contact_id, $journey_id );
        $this->assertSame( 'active', $progress['status'] );
    }

    public function test_set_stage_status_via_rest() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );

        $response = $this->dispatch( 'POST', "/dt-journeys/v1/stage-status/contacts/{$this->contact_id}", [
            'journey_id' => $journey_id,
            'stage_id'   => $stage_ids[0],
            'status'     => 'complete',
            'note'       => 'Done via REST',
        ] );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'complete', $data['stages'][0]['status'] );
        $this->assertSame( 'Done via REST', $data['stages'][0]['note'] );
    }

    public function test_complete_journey_via_rest_cascades_sequential_skip() {
        list( $journey_id, $stage_ids ) = $this->create_journey( true, 3 );
        DT_Journeys_Progress::start_journey( 'contacts', $this->contact_id, $journey_id );

        $response = $this->dispatch( 'POST', "/dt-journeys/v1/complete/contacts/{$this->contact_id}", [
            'journey_id' => $journey_id,
            'force'      => true,
        ] );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'completed', $data['status'] );
        foreach ( $data['stages'] as $stage ) {
            $this->assertSame( 'skipped', $stage['status'] );
        }
    }

    public function test_get_stage_fields_returns_rendered_field_html() {
        $response = $this->dispatch( 'GET', "/dt-journeys/v1/stage-fields/contacts/{$this->contact_id}", [
            'field_keys' => [ 'overall_status' ],
        ] );
        $this->assertSame( 200, $response->get_status() );

        $fields = $response->get_data()['fields'];
        $this->assertArrayHasKey( 'overall_status', $fields );
        $this->assertStringContainsString( 'overall_status', $fields['overall_status'] );
    }

    public function test_endpoints_deny_access_to_unauthorized_user() {
        $user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );

        $response = $this->dispatch( 'GET', "/dt-journeys/v1/record/contacts/{$this->contact_id}" );
        $this->assertSame( 403, $response->get_status() );
    }
}
