<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

if ( ! class_exists( 'DT_Posts' ) ) {
    dt_write_log( 'Disciple.Tools System not loaded. Cannot load Journeys progress service.' );
    return;
}

require_once 'class-progress.php';

/**
 * Register the "Journeys" comment section on contacts/groups so stage notes
 * (added via DT_Journeys_Progress::set_stage_status()) are filterable.
 */
add_filter( 'dt_comments_additional_sections', function ( $sections, $post_type ) {
    if ( in_array( $post_type, [ 'contacts', 'groups' ], true ) ) {
        $sections[] = [
            'key'   => DT_Journeys_Progress::COMMENT_TYPE,
            'label' => __( 'Journeys', 'dt-journeys' ),
        ];
    }
    return $sections;
}, 20, 2 );
