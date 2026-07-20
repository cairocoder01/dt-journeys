<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * REST endpoints under `dt-journeys/v1`.
 *
 * Every route operates on a specific `contacts`/`groups` record and defers to
 * `DT_Posts::can_view()`/`can_update()` for permissions, matching the pattern
 * used by other DT plugins (e.g. dt-family-groups).
 */
class Dt_Journeys_Endpoints {

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    }

    public function add_api_routes() {
        $namespace = 'dt-journeys/v1';
        $record = '(?P<post_type>contacts|groups)/(?P<post_id>\d+)';

        register_rest_route( $namespace, "/record/$record", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_record' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $namespace, "/available/$record", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_available' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $namespace, "/stage-fields/$record", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_stage_fields' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/start/$record", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'start_journey' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/stage-status/$record", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'set_stage_status' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/complete/$record", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'complete_journey' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );
    }

    public function can_view( WP_REST_Request $request ) {
        return DT_Posts::can_view( $request->get_param( 'post_type' ), (int) $request->get_param( 'post_id' ) );
    }

    public function can_update( WP_REST_Request $request ) {
        return DT_Posts::can_update( $request->get_param( 'post_type' ), (int) $request->get_param( 'post_id' ) );
    }

    /**
     * GET /record/{post_type}/{post_id}
     * A record's attached journeys + progress. For contacts, also a
     * read-only roll-up of journeys active on the contact's groups.
     */
    public function get_record( WP_REST_Request $request ) {
        $post_type = $request->get_param( 'post_type' );
        $post_id = (int) $request->get_param( 'post_id' );

        $post = DT_Posts::get_post( $post_type, $post_id );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $progress = DT_Journeys_Progress::get_progress( $post_type, $post_id );
        $journeys = [];
        foreach ( $progress as $journey_id => $entry ) {
            $formatted = $this->format_journey( (int) $journey_id, $entry );
            if ( $formatted ) {
                $journeys[] = $formatted;
            }
        }

        $response = [ 'journeys' => $journeys ];

        if ( $post_type === 'contacts' ) {
            $response['group_journeys'] = $this->get_group_rollup( $post );
        }

        return $response;
    }

    /**
     * GET /available/{post_type}/{post_id}
     * Journeys not currently active on this record, filtered by journey_roles
     * against the current user's roles (Dispatcher/admin see every journey).
     */
    public function get_available( WP_REST_Request $request ) {
        $post_type = $request->get_param( 'post_type' );
        $post_id = (int) $request->get_param( 'post_id' );

        $progress = DT_Journeys_Progress::get_progress( $post_type, $post_id );
        $active_ids = [];
        foreach ( $progress as $journey_id => $entry ) {
            if ( ( $entry['status'] ?? '' ) === 'active' ) {
                $active_ids[] = (int) $journey_id;
            }
        }

        $sees_all = current_user_can( 'manage_dt' );
        $user_roles = wp_get_current_user()->roles ?? [];

        $available = [];
        $journey_ids = get_posts( [
            'post_type'   => 'journeys',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'numberposts' => -1,
        ] );

        foreach ( $journey_ids as $journey_id ) {
            if ( in_array( (int) $journey_id, $active_ids, true ) ) {
                continue;
            }

            $journey = DT_Posts::get_post( 'journeys', $journey_id );
            if ( is_wp_error( $journey ) ) {
                continue;
            }

            $applies_to = array_map( function ( $role ) {
                return $role['key'] ?? $role;
            }, $journey['journey_roles'] ?? [] );

            if ( !$sees_all && !empty( $applies_to ) && !array_intersect( $applies_to, $user_roles ) ) {
                continue;
            }

            $available[] = [
                'ID'            => $journey['ID'],
                'name'          => $journey['name'] ?? $journey['title'] ?? '',
                'category'      => $journey['journey_category'] ?? [],
                'is_sequential' => !empty( $journey['is_sequential'] ),
                'display_type'  => $journey['display_type']['key'] ?? 'timeline',
                'stage_count'   => count( $journey['stages'] ?? [] ),
            ];
        }

        return [ 'journeys' => $available ];
    }

    /**
     * GET /stage-fields/{post_type}/{post_id}?field_keys[]=...
     * Pre-rendered field HTML (via the theme's own render_field_for_display())
     * for the requested field keys, for the stage pop-out's inline editor.
     */
    public function get_stage_fields( WP_REST_Request $request ) {
        $post_type = $request->get_param( 'post_type' );
        $post_id = (int) $request->get_param( 'post_id' );
        $field_keys = (array) $request->get_param( 'field_keys' );

        $post = DT_Posts::get_post( $post_type, $post_id );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $field_settings = DT_Posts::get_post_field_settings( $post_type );

        $html = [];
        foreach ( $field_keys as $field_key ) {
            $field_key = sanitize_key( $field_key );
            if ( !isset( $field_settings[ $field_key ] ) ) {
                continue;
            }
            ob_start();
            render_field_for_display( $field_key, $field_settings, $post, true );
            $html[ $field_key ] = ob_get_clean();
        }

        return [ 'fields' => $html ];
    }

    /**
     * POST /start/{post_type}/{post_id} { journey_id }
     */
    public function start_journey( WP_REST_Request $request ) {
        $journey_id = (int) $request->get_param( 'journey_id' );
        if ( !$journey_id ) {
            return new WP_Error( 'journeys_progress', 'journey_id is required', [ 'status' => 400 ] );
        }

        $progress = DT_Journeys_Progress::start_journey(
            $request->get_param( 'post_type' ),
            (int) $request->get_param( 'post_id' ),
            $journey_id
        );
        if ( is_wp_error( $progress ) ) {
            return $progress;
        }

        return $this->format_journey( $journey_id, $progress );
    }

    /**
     * POST /stage-status/{post_type}/{post_id} { journey_id, stage_id, status, note }
     */
    public function set_stage_status( WP_REST_Request $request ) {
        $journey_id = (int) $request->get_param( 'journey_id' );
        $stage_id = (int) $request->get_param( 'stage_id' );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $note = wp_kses_post( (string) ( $request->get_param( 'note' ) ?? '' ) );

        if ( !$journey_id || !$stage_id || !$status ) {
            return new WP_Error( 'journeys_progress', 'journey_id, stage_id, and status are required', [ 'status' => 400 ] );
        }

        $progress = DT_Journeys_Progress::set_stage_status(
            $request->get_param( 'post_type' ),
            (int) $request->get_param( 'post_id' ),
            $journey_id,
            $stage_id,
            $status,
            $note
        );
        if ( is_wp_error( $progress ) ) {
            return $progress;
        }

        return $this->format_journey( $journey_id, $progress );
    }

    /**
     * POST /complete/{post_type}/{post_id} { journey_id, force }
     */
    public function complete_journey( WP_REST_Request $request ) {
        $journey_id = (int) $request->get_param( 'journey_id' );
        if ( !$journey_id ) {
            return new WP_Error( 'journeys_progress', 'journey_id is required', [ 'status' => 400 ] );
        }

        $progress = DT_Journeys_Progress::complete_journey(
            $request->get_param( 'post_type' ),
            (int) $request->get_param( 'post_id' ),
            $journey_id,
            (bool) $request->get_param( 'force' )
        );
        if ( is_wp_error( $progress ) ) {
            return $progress;
        }

        return $this->format_journey( $journey_id, $progress );
    }

    /**
     * Merge a stored progress entry with its journey/stage definitions into
     * the shape the tile UI renders.
     */
    private function format_journey( int $journey_id, array $progress_entry ) {
        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        if ( is_wp_error( $journey ) ) {
            return null;
        }

        $stages = [];
        foreach ( $journey['stages'] ?? [] as $connected_stage ) {
            // The 'stages' connection field only embeds a lightweight preview
            // (ID, post_title, ...) — fetch the full record for its own fields.
            $stage = DT_Posts::get_post( 'journey_stages', $connected_stage['ID'] );
            if ( is_wp_error( $stage ) ) {
                continue;
            }

            $stage_progress = $progress_entry['stages'][ (string) $stage['ID'] ] ?? [
                'status' => 'not_started',
                'date'   => null,
                'note'   => '',
            ];

            $stages[] = [
                'ID'                   => $stage['ID'],
                'name'                 => $stage['name'] ?? $stage['title'] ?? '',
                'description'          => $stage['description'] ?? '',
                'instructions'         => $stage['instructions'] ?? '',
                'links'                => $stage['links'] ?? [],
                'attachments'          => $stage['attachments'] ?? [],
                'related_fields'       => $stage['related_fields'] ?? [],
                'success_action_label' => $stage['success_action_label'] ?? '',
                'status'               => $stage_progress['status'],
                'date'                 => $stage_progress['date'],
                'note'                 => $stage_progress['note'],
            ];
        }

        return [
            'ID'             => $journey_id,
            'name'           => $journey['name'] ?? $journey['title'] ?? '',
            'is_sequential'  => !empty( $journey['is_sequential'] ),
            'display_type'   => $journey['display_type']['key'] ?? 'timeline',
            'next_journey'   => $journey['next_journey'][0]['ID'] ?? null,
            'started'        => $progress_entry['started'] ?? null,
            'status'         => $progress_entry['status'] ?? 'active',
            'completed_date' => $progress_entry['completed_date'] ?? null,
            'stages'         => $stages,
        ];
    }

    /**
     * Read-only roll-up of journeys active on the groups a contact belongs to.
     */
    private function get_group_rollup( array $contact ) {
        $rollup = [];
        foreach ( $contact['groups'] ?? [] as $group ) {
            $group_id = (int) ( $group['ID'] ?? 0 );
            if ( !$group_id || !DT_Posts::can_view( 'groups', $group_id ) ) {
                continue;
            }

            $progress = DT_Journeys_Progress::get_progress( 'groups', $group_id );
            $group_journeys = [];
            foreach ( $progress as $journey_id => $entry ) {
                if ( ( $entry['status'] ?? '' ) !== 'active' ) {
                    continue;
                }
                $formatted = $this->format_journey( (int) $journey_id, $entry );
                if ( $formatted ) {
                    $group_journeys[] = $formatted;
                }
            }

            if ( !empty( $group_journeys ) ) {
                $rollup[] = [
                    'group_id'   => $group_id,
                    'group_name' => $group['post_title'] ?? '',
                    'journeys'   => $group_journeys,
                ];
            }
        }
        return $rollup;
    }
}
Dt_Journeys_Endpoints::instance();
