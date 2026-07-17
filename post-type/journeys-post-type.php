<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Class Disciple_Tools_Journeys_Post_Type
 *
 * Registers the `journeys` post type: the template/definition for a journey.
 * A journey owns an ordered set of `journey_stages` through the
 * `journeys_to_stages` connection.
 */
class Disciple_Tools_Journeys_Post_Type extends DT_Module_Base {

    public $post_type = 'journeys';
    public $module = 'journeys_base';
    public $single_name = 'Journey';
    public $plural_name = 'Journeys';
    public static function post_type(){
        return 'journeys';
    }

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        parent::__construct();
        if ( !self::check_enabled_and_prerequisites() ){
            return;
        }

        //setup post type
        add_action( 'after_setup_theme', [ $this, 'after_setup_theme' ], 100 );
        add_filter( 'dt_set_roles_and_permissions', [ $this, 'dt_set_roles_and_permissions' ], 20, 1 );
        add_filter( 'dt_capabilities', [ $this, 'dt_capabilities' ], 20, 1 );

        //setup tiles and fields
        add_filter( 'dt_details_additional_tiles', [ $this, 'dt_details_additional_tiles' ], 10, 2 );
        add_filter( 'dt_custom_fields_settings', [ $this, 'dt_custom_fields_settings' ], 10, 2 );
        add_filter( 'dt_get_post_type_settings', [ $this, 'dt_get_post_type_settings' ], 20, 2 );

        // sort connected stages by their stage_order field
        add_filter( 'dt_after_get_post_fields_filter', [ $this, 'dt_after_get_post_fields_filter' ], 10, 2 );
    }

    public function after_setup_theme(){
        $this->single_name = __( 'Journey', 'dt-journeys' );
        $this->plural_name = __( 'Journeys', 'dt-journeys' );

        if ( class_exists( 'Disciple_Tools_Post_Type_Template' ) ) {
            new Disciple_Tools_Post_Type_Template( $this->post_type, $this->single_name, $this->plural_name );
        }
    }
    
    /**
     * Add the Resources tile, grouping attachments and links.
     */
    public function dt_details_additional_tiles( $tiles, $post_type = '' ){
        if ( $post_type === $this->post_type ){
            $tiles['stages'] = [
                'label' => __( 'Stages', 'dt-journeys' ),
            ];
        }
        return $tiles;
    }

    /**
     * Set the singular and plural translations for this post type's settings.
     */
    public function dt_get_post_type_settings( $settings, $post_type ){
        if ( $post_type === $this->post_type ){
            $settings['label_singular'] = __( 'Journey', 'dt-journeys' );
            $settings['label_plural'] = __( 'Journeys', 'dt-journeys' );
        }
        return $settings;
    }

    /**
     * Grant access to the journeys post type.
     *
     * Anyone who can access contacts can access and manage journey definitions.
     * Admin roles (manage_dt) additionally receive view/update/delete of any journey.
     *
     * @link https://github.com/DiscipleTools/Documentation/blob/master/Theme-Core/roles-permissions.md
     */
    public function dt_set_roles_and_permissions( $expected_roles ){

        // if a user can access contacts they can also access & manage journeys
        foreach ( $expected_roles as $role => $role_value ){
            if ( !empty( $expected_roles[$role]['permissions']['access_contacts'] ) ){
                $expected_roles[$role]['permissions']['access_' . $this->post_type ] = true;
                $expected_roles[$role]['permissions']['create_' . $this->post_type] = true;
                $expected_roles[$role]['permissions']['update_' . $this->post_type] = true;
            }
        }

        // admin roles get full access to every journey
        foreach ( $expected_roles as $role => $role_value ){
            if ( !empty( $expected_roles[$role]['permissions']['manage_dt'] ) ){
                $expected_roles[$role]['permissions']['access_' . $this->post_type ] = true;
                $expected_roles[$role]['permissions']['create_' . $this->post_type] = true;
                $expected_roles[$role]['permissions']['update_' . $this->post_type] = true;
                $expected_roles[$role]['permissions']['view_any_' . $this->post_type ] = true;
                $expected_roles[$role]['permissions']['update_any_' . $this->post_type ] = true;
                $expected_roles[$role]['permissions']['delete_any_' . $this->post_type ] = true;
            }
        }

        if ( isset( $expected_roles['administrator'] ) ){
            $expected_roles['administrator']['permissions']['delete_any_' . $this->post_type ] = true;
        }

        // manage_journeys gates the Journeys admin UI (definition builder). Grant
        // it to admin roles by default; it can be delegated to non-admin roles.
        foreach ( $expected_roles as $role => $role_value ){
            if ( !empty( $expected_roles[$role]['permissions']['manage_dt'] ) ){
                $expected_roles[$role]['permissions']['manage_journeys'] = true;
            }
        }

        return $expected_roles;
    }

    /**
     * Register the manage_journeys capability so it is grantable to any role
     * from the D.T roles admin UI.
     */
    public function dt_capabilities( $capabilities ){
        $capabilities['manage_journeys'] = [
            'source'      => __( 'Journeys', 'dt-journeys' ),
            'label'       => __( 'Manage Journeys', 'dt-journeys' ),
            'description' => __( 'Build and edit journey definitions and their stages.', 'dt-journeys' ),
        ];
        return $capabilities;
    }

    /**
     * Define the fields for the journeys post type.
     *
     * @link https://github.com/DiscipleTools/Documentation/blob/master/Theme-Core/fields.md
     */
    public function dt_custom_fields_settings( $fields, $post_type ){
        if ( $post_type !== $this->post_type ){
            return $fields;
        }

        // A journey template has no geographic location; drop the base location fields.
        unset( $fields['location_grid'], $fields['location_grid_meta'] );

        if ( isset( $fields['name'] ) ){
            $fields['name']['tile'] = 'status';
            $fields['name']['font-icon'] = 'mdi mdi-star-four-points-outline';
            $fields['name']['icon'] = null;
        }
        // Grouping by ministry model (T4T, Zúme, DMM, …). Tags so categories can be
        // added dynamically as needed rather than from a fixed list.
        $fields['journey_category'] = [
            'name'        => __( 'Category', 'dt-journeys' ),
            'description' => __( 'Group journeys by ministry model (e.g. T4T, Zúme, DMM). Add categories as needed.', 'dt-journeys' ),
            'type'        => 'tags',
            'default'     => [],
            'tile'          => 'status',
            'in_create_form' => true,
            'font-icon'          => 'mdi mdi-shape-plus',
            'show_in_table' => 15,
        ];

        // Which DT roles this journey applies to. Options are the current DT roles.
        $fields['journey_roles'] = [
            'name'        => __( 'Applies to Roles', 'dt-journeys' ),
            'description' => __( 'Which roles this journey applies to. Dispatcher/admin always have access.', 'dt-journeys' ),
            'type'        => 'multi_select',
            'default'     => $this->get_role_options(),
            'tile'        => 'details',
            'font-icon'   => 'mdi mdi-account-key',
        ];

        // Timeline vs. list/grid behaviour.
        $fields['is_sequential'] = [
            'name'        => __( 'Sequential', 'dt-journeys' ),
            'description' => __( 'Sequential journeys display as a timeline; non-sequential as a list or grid.', 'dt-journeys' ),
            'type'        => 'boolean',
            'default'     => true,
            'tile'        => 'details',
            'font-icon'        => 'mdi mdi-order-numeric-ascending',
        ];

        $fields['display_type'] = [
            'name'        => __( 'Display Type', 'dt-journeys' ),
            'description' => __( 'How to display this journey on a record.', 'dt-journeys' ),
            'type'        => 'key_select',
            'default'     => [
                'timeline' => [ 'label' => __( 'Timeline', 'dt-journeys' ) ],
                'list'     => [ 'label' => __( 'List', 'dt-journeys' ) ],
                'grid'     => [ 'label' => __( 'Grid', 'dt-journeys' ) ],
            ],
            'default_color' => '#366184',
            'tile'          => 'details',
            'font-icon'     => 'mdi mdi-view-dashboard',
        ];

        // The ordered set of stages that belong to this journey.
        $fields['stages'] = [
            'name'          => __( 'Stages', 'dt-journeys' ),
            'description'   => __( 'The stages that make up this journey.', 'dt-journeys' ),
            'type'          => 'connection',
            'post_type'     => 'journey_stages',
            'p2p_direction' => 'from',
            'p2p_key'       => 'journeys_to_stages',
            'tile'          => 'stages',
            'font-icon'     => 'mdi mdi-timeline-check',
        ];

        // "When this ends, start that" — an optional pointer to the next journey.
        $fields['next_journey'] = [
            'name'          => __( 'Next Journey', 'dt-journeys' ),
            'description'   => __( 'When this journey ends, suggest starting this journey next.', 'dt-journeys' ),
            'type'          => 'connection',
            'post_type'     => 'journeys',
            'p2p_direction' => 'to',
            'p2p_key'       => 'journeys_to_journeys',
            'tile'          => 'details',
            'font-icon'     => 'mdi mdi-skip-next',
        ];
        $fields['previous_journeys'] = [
            'name'          => __( 'Previous Journeys', 'dt-journeys' ),
            'description'   => __( 'Journeys that lead into this journey.', 'dt-journeys' ),
            'type'          => 'connection',
            'post_type'     => 'journeys',
            'p2p_direction' => 'from',
            'p2p_key'       => 'journeys_to_journeys',
            'tile'          => 'details',
            'font-icon'     => 'mdi mdi-skip-previous',
        ];

        return $fields;
    }

    /**
     * Build the multi_select options for the journey_roles field from the
     * currently registered DT roles.
     *
     * @return array
     */
    private function get_role_options(){
        $options = [];
        if ( class_exists( 'Disciple_Tools_Roles' ) ){
            $roles = Disciple_Tools_Roles::get_dt_roles_and_permissions();
            foreach ( $roles as $role_key => $role ){
                $options[$role_key] = [ 'label' => $role['label'] ?? $role_key ];
            }
        }
        return $options;
    }

    /**
     * Sort a journey's connected stages by their stage_order value.
     *
     * DT does not sort p2p connections, so we order the `stages` connection here
     * and attach each stage's stage_order for convenience.
     */
    public function dt_after_get_post_fields_filter( $fields, $post_type ){
        if ( $post_type !== $this->post_type || empty( $fields['stages'] ) || !is_array( $fields['stages'] ) ){
            return $fields;
        }

        global $wpdb;
        $stage_ids = array_filter( array_map( function ( $stage ){
            return isset( $stage['ID'] ) ? (int) $stage['ID'] : 0;
        }, $fields['stages'] ) );

        if ( empty( $stage_ids ) ){
            return $fields;
        }

        $ids_sql = dt_array_to_sql( $stage_ids );
        //phpcs:disable
        //WordPress.WP.PreparedSQL.NotPrepared
        $orders = $wpdb->get_results( "
            SELECT post_id, meta_value
            FROM $wpdb->postmeta
            WHERE meta_key = 'stage_order'
            AND post_id IN ( $ids_sql )
        ", ARRAY_A );
        //phpcs:enable

        $order_by_id = [];
        foreach ( $orders as $row ){
            $order_by_id[ (int) $row['post_id'] ] = (int) $row['meta_value'];
        }

        foreach ( $fields['stages'] as &$stage ){
            $stage['stage_order'] = $order_by_id[$stage['ID']] ?? 0;
        }
        unset( $stage );

        usort( $fields['stages'], function ( $a, $b ){
            return ( $a['stage_order'] ?? 0 ) <=> ( $b['stage_order'] ?? 0 );
        } );

        return $fields;
    }
}
