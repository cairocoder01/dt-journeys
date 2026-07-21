<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Class Disciple_Tools_Journey_Stages_Post_Type
 *
 * Registers the `journey_stages` post type: a single step within a journey.
 * Stages are a utility post type — hidden from the main D.T navigation and only
 * managed through the Journeys admin UI in the context of a parent journey.
 */
class Disciple_Tools_Journey_Stages_Post_Type extends DT_Module_Base {

    public $post_type = 'journey_stages';
    public $module = 'journey_stages_base';
    public $single_name = 'Journey Stage';
    public $plural_name = 'Journey Stages';
    public static function post_type(){
        return 'journey_stages';
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

        //setup tiles and fields
        add_filter( 'dt_details_additional_tiles', [ $this, 'dt_details_additional_tiles' ], 10, 2 );
        add_filter( 'dt_custom_fields_settings', [ $this, 'dt_custom_fields_settings' ], 10, 2 );
        add_filter( 'dt_get_post_type_settings', [ $this, 'dt_get_post_type_settings' ], 20, 2 );

        // hide this utility post type from the main D.T navigation
        add_filter( 'desktop_navbar_menu_options', [ $this, 'desktop_navbar_menu_options' ], 30, 1 );
        add_filter( 'dt_nav_add_post_menu', [ $this, 'dt_nav_add_post_menu' ], 30, 1 );
    }

    public function after_setup_theme(){
        $this->single_name = __( 'Journey Stage', 'dt-journeys' );
        $this->plural_name = __( 'Journey Stages', 'dt-journeys' );

        if ( class_exists( 'Disciple_Tools_Post_Type_Template' ) ) {
            new Disciple_Tools_Post_Type_Template( $this->post_type, $this->single_name, $this->plural_name );
        }
    }

    /**
     * Add the Resources tile, grouping attachments and links.
     */
    public function dt_details_additional_tiles( $tiles, $post_type = '' ){
        if ( $post_type === $this->post_type ){
            $tiles['resources'] = [
                'label' => __( 'Resources', 'dt-journeys' ),
            ];
        }
        return $tiles;
    }

    /**
     * Set the singular and plural translations for this post type's settings.
     */
    public function dt_get_post_type_settings( $settings, $post_type ){
        if ( $post_type === $this->post_type ){
            $settings['label_singular'] = __( 'Journey Stage', 'dt-journeys' );
            $settings['label_plural'] = __( 'Journey Stages', 'dt-journeys' );
        }
        return $settings;
    }

    /**
     * Grant access to the journey_stages post type.
     *
     * Access mirrors the journeys post type: anyone who can access contacts
     * can view stages (they're fetched as part of a journey on the record
     * tile), but full CRUD is gated behind `manage_journeys` -- the same
     * capability that gates the parent journeys post type.
     */
    public function dt_set_roles_and_permissions( $expected_roles ){

        foreach ( $expected_roles as $role => $role_value ){
            if ( !empty( $expected_roles[$role]['permissions']['access_contacts'] ) ){
                $expected_roles[$role]['permissions']['access_' . $this->post_type ] = true;
                $expected_roles[$role]['permissions']['view_any_' . $this->post_type ] = true;
            }
        }

        foreach ( $expected_roles as $role => $role_value ){
            if ( !empty( $expected_roles[$role]['permissions']['manage_dt'] ) ){
                $expected_roles[$role]['permissions']['manage_journeys'] = true;
            }
            if ( !empty( $expected_roles[$role]['permissions']['manage_journeys'] ) ){
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

        return $expected_roles;
    }

    /**
     * Define the fields for the journey_stages post type.
     *
     * @link https://github.com/DiscipleTools/Documentation/blob/master/Theme-Core/fields.md
     */
    public function dt_custom_fields_settings( $fields, $post_type ){
        if ( $post_type !== $this->post_type ){
            return $fields;
        }

        // A stage has no geographic location; drop the base location fields.
        unset( $fields['location_grid'], $fields['location_grid_meta'] );

        if ( isset( $fields['name'] ) ){
            $fields['name']['tile'] = 'status';
            $fields['name']['font-icon'] = 'mdi mdi-timeline-check';
            $fields['name']['icon'] = null;
        }

        $fields['description'] = [
            'name'        => __( 'Description', 'dt-journeys' ),
            'description' => __( 'A short description of this stage.', 'dt-journeys' ),
            'type'        => 'text',
            'default'     => '',
            'tile'        => 'details',
            'font-icon'   => 'mdi mdi-text',
        ];

        $fields['instructions'] = [
            'name'        => __( 'Instructions', 'dt-journeys' ),
            'description' => __( 'Long-form instructions or course material for this stage. Supports rich text.', 'dt-journeys' ),
            'type'        => 'textarea',
            'default'     => '',
            'tile'        => 'details',
            'font-icon'   => 'mdi mdi-clipboard-text-outline',
        ];

        // Repeating link/label: PDFs, images, or other resource material by URL.
        $fields['attachments'] = [
            'name'        => __( 'Attachments', 'dt-journeys' ),
            'description' => __( 'Links to PDFs, images, or other resource material.', 'dt-journeys' ),
            'type'        => 'file_upload',
            'default'     => [
                'resource' => [
                    'label' => __( 'Resource', 'dt-journeys' ),
                ],
            ],
            'tile'        => 'resources',
            'font-icon'   => 'mdi mdi-attachment',
        ];
        $fields['links'] = [
            'name'        => __( 'Links', 'dt-journeys' ),
            'description' => __( 'Links to online resource material', 'dt-journeys' ),
            'type'        => 'link',
            'default'     => [
                'resource' => [
                    'label' => __( 'Resource', 'dt-journeys' ),
                ],
            ],
            'tile'        => 'resources',
            'font-icon'   => 'mdi mdi-open-in-new',
        ];

        // DT field keys (on contacts/groups) relevant to edit at this stage.
        // The picker is provided by the Journeys admin UI; values are stored as
        // arbitrary field-key strings. The option registry must list every
        // selectable key -- DT_Posts silently drops any multi_select value not
        // present in 'default' on read, so an empty registry here would make
        // stored selections vanish.
        $fields['related_fields'] = [
            'name'        => __( 'Related Fields', 'dt-journeys' ),
            'description' => __( 'Record fields that are relevant to complete at this stage.', 'dt-journeys' ),
            'type'        => 'multi_select',
            'default'     => $this->get_related_field_options(),
            'tile'        => 'details',
            'icon'        => get_template_directory_uri() . '/dt-assets/images/list.svg',
        ];

        $fields['success_action_label'] = [
            'name'        => __( 'Success Action Label', 'dt-journeys' ),
            'description' => __( 'Optionally rename the "Complete" action for this stage.', 'dt-journeys' ),
            'type'        => 'text',
            'default'     => '',
            'tile'        => 'details',
            'font-icon'   => 'mdi mdi-marker-check',
        ];

        $fields['stage_order'] = [
            'name'        => __( 'Order', 'dt-journeys' ),
            'description' => __( 'The order of this stage within its journey.', 'dt-journeys' ),
            'type'        => 'number',
            'default'     => 0,
            'tile'        => 'details',
            'font-icon'   => 'mdi mdi-sort-numeric-ascending',
        ];

        // Reverse of journeys.stages — the journey this stage belongs to.
        $fields['journey'] = [
            'name'          => __( 'Journey', 'dt-journeys' ),
            'description'   => __( 'The journey this stage belongs to.', 'dt-journeys' ),
            'type'          => 'connection',
            'post_type'     => 'journeys',
            'p2p_direction' => 'to',
            'p2p_key'       => 'journeys_to_stages',
            'tile'          => 'status',
            'font-icon'     => 'mdi mdi-star-four-points-outline',
        ];

        return $fields;
    }

    /**
     * Build the multi_select options for `related_fields` from every field on
     * contacts and groups that `render_field_for_display()` knows how to
     * render inline (its own allow-list of field types), excluding
     * hidden/private fields.
     *
     * @return array
     */
    private function get_related_field_options(){
        $renderable_types = apply_filters( 'dt_render_field_for_display_allowed_types', [
            'boolean',
            'key_select',
            'multi_select',
            'date',
            'datetime',
            'text',
            'textarea',
            'number',
            'link',
            'connection',
            'location',
            'location_meta',
            'communication_channel',
            'tags',
            'user_select',
            'file_upload',
        ] );

        $options = [];
        foreach ( [ 'contacts', 'groups' ] as $post_type ){
            $fields = DT_Posts::get_post_field_settings( $post_type );
            foreach ( $fields as $field_key => $field ){
                if ( empty( $field['type'] ) || !in_array( $field['type'], $renderable_types, true ) ){
                    continue;
                }
                if ( !empty( $field['hidden'] ) || !empty( $field['private'] ) ){
                    continue;
                }
                // contacts/groups can share a field key; keep the first label seen.
                if ( !isset( $options[$field_key] ) ){
                    $options[$field_key] = [ 'label' => ( $field['name'] ?? $field_key ) . ' (' . $post_type . ')' ];
                }
            }
        }
        return $options;
    }

    /**
     * Remove journey_stages from the main desktop navigation. Stages are only
     * managed inside the Journeys admin UI.
     */
    public function desktop_navbar_menu_options( $tabs ){
        unset( $tabs[$this->post_type] );
        return $tabs;
    }

    /**
     * Remove the "New Journey Stage" entry from the create-new menu.
     */
    public function dt_nav_add_post_menu( $links ){
        foreach ( $links as $index => $link ){
            if ( isset( $link['link'] ) && strpos( $link['link'], '/' . $this->post_type . '/new' ) !== false ){
                unset( $links[$index] );
            }
        }
        return array_values( $links );
    }
}
