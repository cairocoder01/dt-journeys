<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Test that DT_Module_Base has loaded
 */
if ( ! class_exists( 'DT_Module_Base' ) ) {
    dt_write_log( 'Disciple.Tools System not loaded. Cannot load custom post type.' );
    return;
}

/**
 * Register the Journeys post type modules.
 */
add_filter( 'dt_post_type_modules', function( $modules ){

    $modules['journeys_base'] = [
        'name' => __( 'Journeys', 'dt-journeys' ),
        'enabled' => true,
        'locked' => true,
        'prerequisites' => [ 'contacts_base' ],
        'post_type' => 'journeys',
        'description' => __( 'Journey definitions (templates).', 'dt-journeys' )
    ];

    $modules['journey_stages_base'] = [
        'name' => __( 'Journey Stages', 'dt-journeys' ),
        'enabled' => true,
        'locked' => true,
        'prerequisites' => [ 'journeys_base' ],
        'post_type' => 'journey_stages',
        'description' => __( 'Stages that make up a journey.', 'dt-journeys' )
    ];

    return $modules;
}, 20, 1 );

require_once 'journeys-post-type.php';
Disciple_Tools_Journeys_Post_Type::instance();

require_once 'journey-stages-post-type.php';
Disciple_Tools_Journey_Stages_Post_Type::instance();
