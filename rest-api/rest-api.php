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
        // Resource first, action last -- matches the shape of the theme's own
        // dt-posts/v2/{post_type}/{id}/{sub-resource} endpoints.
        $record = '(?P<post_type>contacts|groups)/(?P<post_id>\d+)';

        register_rest_route( $namespace, "/$record", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_record' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $namespace, "/$record/available", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_available' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $namespace, "/$record/stage-fields", [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_stage_fields' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/$record/start", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'start_journey' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/$record/stage-status", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'set_stage_status' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/$record/complete", [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'complete_journey' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route( $namespace, "/$record/remove", [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'remove_journey' ],
            'permission_callback' => [ $this, 'can_update' ],
        ] );

        register_rest_route(
            $namespace, '/journeys', [
                [
                    'methods'  => 'GET',
                    'callback' => [ $this, 'get_journeys_endpoint' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );

        register_rest_route(
            $namespace, '/journeys/(?P<id>\d+)', [
                [
                    'methods'  => 'DELETE',
                    'callback' => [ $this, 'delete_journey_endpoint' ],
                    'permission_callback' => '__return_true',
                ]
            ]
        );

        register_rest_route(
            $namespace, '/journeys/(?P<id>\d+)/duplicate', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'duplicate_journey_endpoint' ],
                    'permission_callback' => '__return_true',
                ]
            ]
        );
    }

    public function get_journeys_endpoint( WP_REST_Request $request ) {
        $params = $request->get_params();

        $raw_journeys = self::get_journeys( $params );

        return [
            'journeys'       => $raw_journeys['posts'],
            'total_journeys' => $raw_journeys['total']
        ];
    }

    public function delete_journey_endpoint( WP_REST_Request $request ) {
        $journey_id = isset( $request['id'] ) ? $request['id'] : null;

        if ( !$journey_id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid journey ID' ], 400 );
        }

        self::delete_journey( $journey_id );
        return new WP_REST_Response( [ 'message' => 'Journey deleted successfully' ], 200 );
    }

    public function duplicate_journey_endpoint( WP_REST_Request $request ) {
        $journey_id = isset( $request['id'] ) ? $request['id'] : null;

        if ( !$journey_id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid journey ID' ], 400 );
        }

        $new_journey_id = self::duplicate_journey( $journey_id );
        return new WP_REST_Response( [ 'journey_id' => $new_journey_id ], 200 );
    }

    public function get_journeys( $params = [] ) {
        $search_parameters = [];
        foreach ( $params as $key => $value ) {
            if ( $key === 'sort' || $key === 'text' || $key === 'limit' ) {
                $search_parameters[$key] = $value;
            } else {
                $search_parameters['fields'][][$key] = explode( ',', $value );
            }
        }
        $journeys = DT_Posts::list_posts( 'journeys', $search_parameters );
        return $journeys;
    }

    public function delete_journey( $journey_id ) {
        $journey = DT_Posts::get_post( 'journeys', $journey_id );
        foreach ( $journey['stages'] as $stage ) {
            $wp_post = DT_Posts::get_post( 'journey_stages', $stage['ID'] );

            $filtered = array_filter($wp_post['journey'], function( $value ) use ( $journey_id ) {
                return $value['ID'] != $journey_id;
            });

            if ( empty( $filtered ) ) {
                DT_Posts::delete_post( 'journey_stages', $stage['ID'] );
            }
        }
        DT_Posts::delete_post( 'journeys', $journey_id );
    }

    public function duplicate_journey( $original_id ) {
        $wp_post = get_post( $original_id );
        if ( ! $wp_post ) {
            return new WP_Error( 'not_found', 'Original post not found.' );
        }

        $new_post_args = array(
            'post_title'   => $wp_post->post_title . ' (Copy)',
            'post_type'    => $wp_post->post_type,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        );

        $new_post_id = wp_insert_post( $new_post_args );
        if ( is_wp_error( $new_post_id ) ) {
            return $new_post_id;
        }

        $original_post = DT_Posts::get_post( 'journeys', $original_id );

        $field_settings = DT_Posts::get_post_field_settings( $wp_post->post_type );

        $update_args = array();

        foreach ( $field_settings as $field_key => $field_config ) {

            // Look for connection field types because they don't copy like standard fields
            if ( isset( $field_config['type'] ) && $field_config['type'] === 'connection' ) {

                if ( ! empty( $original_post[ $field_key ] ) ) {

                    $update_args[ $field_key ] = array(
                        'values'       => array(),
                        'force_values' => true,
                    );

                    foreach ( $original_post[ $field_key ] as $connection ) {
                        if ( $field_key === 'stages' ) {
                            $new_stage_id = self::duplicate_stage( $connection['ID'] );

                            if ( $new_stage_id ) {
                                $update_args[ $field_key ]['values'][] = array(
                                    'value' => $new_stage_id
                                );
                            }
                        } else {
                            $update_args[ $field_key ]['values'][] = array(
                                'value' => $connection['ID']
                            );
                        }
                    }
                }
            }
        }

        if ( ! empty( $update_args ) ) {
            DT_Posts::update_post( $wp_post->post_type, $new_post_id, $update_args, false, false );
        }

        $post_meta = get_post_custom( $original_id );
        foreach ( $post_meta as $key => $values ) {
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $new_post_id, $key, maybe_unserialize( $value ) );
            }
        }

        return $new_post_id;
    }

    public function duplicate_stage( $original_stage_id ) {
        $wp_post = get_post( $original_stage_id );
        if ( ! $wp_post ) {
            return false;
        }

        // 1. Create the new Stage
        $new_post_args = array(
            'post_title'   => $wp_post->post_title,
            'post_type'    => $wp_post->post_type,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        );

        $new_stage_id = wp_insert_post( $new_post_args );
        if ( is_wp_error( $new_stage_id ) ) {
            return false;
        }

        // 2. Copy the standard metadata for the Stage
        $post_meta = get_post_custom( $original_stage_id );
        foreach ( $post_meta as $key => $values ) {
            // Skip hidden/system meta keys
            if ( strpos( $key, '_' ) === 0 ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $new_stage_id, $key, maybe_unserialize( $value ) );
            }
        }

        // Return the brand new ID so the Journey can link to it
        return $new_stage_id;
    }

    public function can_view( WP_REST_Request $request ) {
        return DT_Posts::can_view( $request->get_param( 'post_type' ), (int) $request->get_param( 'post_id' ) );
    }

    public function can_update( WP_REST_Request $request ) {
        return DT_Posts::can_update( $request->get_param( 'post_type' ), (int) $request->get_param( 'post_id' ) );
    }

    /**
     * GET /{post_type}/{post_id}
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
     * GET /{post_type}/{post_id}/available
     * Journeys with no progress yet on this record (active OR completed --
     * once finished, the record's existing entry is how you go back to it,
     * not a fresh restart), filtered by journey_roles against the current
     * user's roles (Dispatcher/admin see every journey).
     */
    public function get_available( WP_REST_Request $request ) {
        $post_type = $request->get_param( 'post_type' );
        $post_id = (int) $request->get_param( 'post_id' );

        $progress = DT_Journeys_Progress::get_progress( $post_type, $post_id );
        $existing_ids = array_map( 'intval', array_keys( $progress ) );

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
            if ( in_array( (int) $journey_id, $existing_ids, true ) ) {
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
                'subtitle'      => $journey['subtitle'] ?? '',
                'category'      => $journey['journey_category'] ?? [],
                'is_sequential' => !empty( $journey['is_sequential'] ),
                'display_type'  => $journey['display_type']['key'] ?? 'timeline',
                'stage_count'   => count( $journey['stages'] ?? [] ),
                'order'         => (int) ( $journey['journey_order'] ?? 0 ),
                'category_group' => $this->category_group_key( $journey['journey_category'] ?? [] ),
            ];
        }

        // Journeys are grouped by their exact set of categories (so separate
        // "sets" of journeys can each reuse the same order numbers without
        // colliding), categorized sets before the uncategorized one, each
        // alphabetical by its category combination, then by order/name within.
        usort( $available, function ( $a, $b ) {
            $a_uncategorized = $a['category_group'] === '';
            $b_uncategorized = $b['category_group'] === '';
            if ( $a_uncategorized !== $b_uncategorized ) {
                return $a_uncategorized <=> $b_uncategorized;
            }
            return strcasecmp( $a['category_group'], $b['category_group'] )
                ?: ( $a['order'] <=> $b['order'] )
                ?: strcasecmp( $a['name'], $b['name'] );
        } );

        $available = array_map( function ( $journey ) {
            unset( $journey['category_group'] );
            return $journey;
        }, $available );

        return [ 'journeys' => $available ];
    }

    /**
     * A stable grouping key for a journey's set of categories -- its category
     * values, sorted and joined, so journeys sharing the exact same category
     * combination are grouped (and ordered by journey_order) together.
     */
    private function category_group_key( array $categories ) {
        $values = array_map( function ( $cat ) {
            return is_array( $cat ) ? (string) ( $cat['value'] ?? '' ) : (string) $cat;
        }, $categories );
        $values = array_filter( $values, function ( $value ) {
            return $value !== '';
        } );
        sort( $values, SORT_STRING | SORT_FLAG_CASE );
        return implode( '|', $values );
    }

    /**
     * GET /{post_type}/{post_id}/stage-fields?field_keys[]=...
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
            // This pop-out is a quick-edit convenience, not the record's main
            // edit form -- a field required there shouldn't block or flash a
            // validation error here. `required` is read fresh from whatever
            // settings are passed in, so overriding it on this shallow copy
            // doesn't touch the field's real required-ness anywhere else.
            $display_settings = $field_settings;
            $display_settings[ $field_key ]['required'] = false;

            ob_start();
            render_field_for_display( $field_key, $display_settings, $post, true );
            $html[ $field_key ] = ob_get_clean();
        }

        return [ 'fields' => $html ];
    }

    /**
     * POST /{post_type}/{post_id}/start { journey_id }
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
     * POST /{post_type}/{post_id}/stage-status { journey_id, stage_id, status, note }
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
     * POST /{post_type}/{post_id}/complete { journey_id, force }
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
     * DELETE /{post_type}/{post_id}/remove { journey_id }
     * Removes a journey instance -- its whole progress entry -- from the
     * record, e.g. to undo starting the wrong one. Distinct from `complete`.
     */
    public function remove_journey( WP_REST_Request $request ) {
        $journey_id = (int) $request->get_param( 'journey_id' );
        if ( !$journey_id ) {
            return new WP_Error( 'journeys_progress', 'journey_id is required', [ 'status' => 400 ] );
        }

        $result = DT_Journeys_Progress::remove_journey(
            $request->get_param( 'post_type' ),
            (int) $request->get_param( 'post_id' ),
            $journey_id
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [ 'removed' => true ];
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
                'attachments'          => $this->format_stage_attachments( $stage['attachments'] ?? [] ),
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
     * Resolve each `file_upload` entry's storage key to a downloadable URL,
     * for the stage pop-out's read-only attachments list (the field's own
     * `<dt-file-upload>` widget is an upload/manage UI, not a fit here).
     */
    private function format_stage_attachments( array $files ) {
        $formatted = [];
        foreach ( $files as $file ) {
            if ( !is_array( $file ) || empty( $file['key'] ) ) {
                continue;
            }
            $formatted[] = [
                'name' => $file['name'] ?? basename( $file['key'] ),
                'url'  => DT_Storage_API::is_enabled() ? DT_Storage_API::get_file_url( $file['key'] ) : '',
            ];
        }
        return $formatted;
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
