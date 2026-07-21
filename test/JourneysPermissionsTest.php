<?php

/**
 * Journeys permission model tests.
 *
 * Journey definitions are shared ministry-model templates: any user who can
 * access contacts should be able to view/list them (to browse and attach one
 * to a record), but full CRUD is reserved for admins and whoever holds the
 * delegatable `journeys_admin` support role.
 */
class JourneysPermissionsTest extends TestCase {

    private function assert_view_only_access( int $user_id ) {
        $this->assertTrue( user_can( $user_id, 'access_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'view_any_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'access_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'view_any_journey_stages' ) );

        $this->assertFalse( user_can( $user_id, 'create_journeys' ) );
        $this->assertFalse( user_can( $user_id, 'update_journeys' ) );
        $this->assertFalse( user_can( $user_id, 'delete_any_journeys' ) );
        $this->assertFalse( user_can( $user_id, 'manage_journeys' ) );
    }

    private function assert_full_access( int $user_id ) {
        $this->assertTrue( user_can( $user_id, 'manage_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'access_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'create_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'update_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'view_any_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'update_any_journeys' ) );
        $this->assertTrue( user_can( $user_id, 'delete_any_journeys' ) );

        $this->assertTrue( user_can( $user_id, 'access_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'create_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'update_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'view_any_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'update_any_journey_stages' ) );
        $this->assertTrue( user_can( $user_id, 'delete_any_journey_stages' ) );
    }

    public function test_multiplier_has_view_only_access() {
        $user_id = $this->factory()->user->create( [ 'role' => 'multiplier' ] );
        $this->assert_view_only_access( $user_id );
    }

    public function test_dispatcher_has_view_only_access() {
        $user_id = $this->factory()->user->create( [ 'role' => 'dispatcher' ] );
        $this->assert_view_only_access( $user_id );
    }

    public function test_administrator_has_full_access_by_default() {
        $user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assert_full_access( $user_id );
        // Administrator keeps its explicit delete_any grant regardless of the
        // manage_journeys chain.
        $this->assertTrue( user_can( $user_id, 'delete_any_journeys' ) );
    }

    public function test_journeys_admin_role_is_registered_as_a_support_role() {
        $roles = Disciple_Tools_Roles::get_dt_roles_and_permissions( false );
        $this->assertArrayHasKey( 'journeys_admin', $roles );
        $this->assertContains( 'support', $roles['journeys_admin']['type'] );
        $this->assertTrue( $roles['journeys_admin']['permissions']['manage_journeys'] );
    }

    public function test_journeys_admin_role_delegates_full_access_to_a_standard_user() {
        // A plain multiplier only has view access...
        $user_id = $this->factory()->user->create( [ 'role' => 'multiplier' ] );
        $this->assert_view_only_access( $user_id );

        // ...but layering the journeys_admin support role on top (WP's native
        // multi-role support, same mechanism as any other DT support role)
        // grants full Journeys CRUD without making them a site admin.
        $user = get_userdata( $user_id );
        $user->add_role( 'journeys_admin' );

        $this->assert_full_access( $user_id );
        $this->assertFalse( user_can( $user_id, 'manage_dt' ), 'delegation should not grant site-wide admin rights' );
    }
}
