<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * Class Dt_Journeys_Tile
 *
 * Registers a "Journeys" tile on the contact/group detail page. The tile
 * itself is a thin PHP shell (container + "Add a Journey" button); all data
 * is fetched and rendered client-side from `dt-journeys/v1` (see
 * `tile/journeys-tile.js`), mirroring the theme's tile + REST pattern.
 */
class Dt_Journeys_Tile {

    const POST_TYPES = [ 'contacts', 'groups' ];

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_filter( 'dt_details_additional_tiles', [ $this, 'dt_details_additional_tiles' ], 10, 2 );
        add_action( 'dt_details_additional_section', [ $this, 'dt_add_section' ], 30, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 99 );
    }

    public function dt_details_additional_tiles( $tiles, $post_type = '' ) {
        if ( in_array( $post_type, self::POST_TYPES, true ) ) {
            $tiles['journeys'] = [
                'label'       => __( 'Journeys', 'dt-journeys' ),
                'description' => __( 'Track this record\'s progress through discipleship journeys.', 'dt-journeys' ),
            ];
        }
        return $tiles;
    }

    public function enqueue_scripts() {
        if ( !is_singular( self::POST_TYPES ) ) {
            return;
        }
        $post_type = get_post_type();

        wp_enqueue_style(
            'dt-journeys-tile',
            plugin_dir_url( dirname( __FILE__ ) ) . 'tile/journeys-tile.css',
            [],
            filemtime( dirname( __FILE__ ) . '/journeys-tile.css' )
        );

        wp_enqueue_script(
            'dt-journeys-tile',
            plugin_dir_url( dirname( __FILE__ ) ) . 'tile/journeys-tile.js',
            [],
            filemtime( dirname( __FILE__ ) . '/journeys-tile.js' ),
            true
        );

        wp_localize_script( 'dt-journeys-tile', 'dtJourneys', [
            'rest_url'  => esc_url_raw( rest_url() ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'post_id'   => get_the_ID(),
            'post_type' => $post_type,
            'i18n'      => [
                'loading'          => __( 'Loading journeys…', 'dt-journeys' ),
                'error'            => __( 'Could not load journeys.', 'dt-journeys' ),
                'no_journeys'      => __( 'No journeys started yet.', 'dt-journeys' ),
                'add_journey'      => __( 'Add a Journey', 'dt-journeys' ),
                'no_available'     => __( 'No journeys available to add.', 'dt-journeys' ),
                'start'            => __( 'Start', 'dt-journeys' ),
                'not_started'      => __( 'Not Started', 'dt-journeys' ),
                'started'          => __( 'Started', 'dt-journeys' ),
                'paused'           => __( 'Paused', 'dt-journeys' ),
                'complete'         => __( 'Complete', 'dt-journeys' ),
                'skip'             => __( 'Skip', 'dt-journeys' ),
                'stalled'          => __( 'Stalled', 'dt-journeys' ),
                'mark_complete'    => __( 'Mark Journey Complete', 'dt-journeys' ),
                'started_on'       => __( 'Started', 'dt-journeys' ),
                'completed_on'     => __( 'Completed', 'dt-journeys' ),
                'note_placeholder' => __( 'Add a note (optional)…', 'dt-journeys' ),
                'save_note'        => __( 'Save', 'dt-journeys' ),
                'close'            => __( 'Close', 'dt-journeys' ),
                'confirm_complete' => __( 'Mark this journey complete? Any remaining steps will be skipped.', 'dt-journeys' ),
                'start_next'       => __( 'Start the next journey: %s?', 'dt-journeys' ),
                'group_journeys'   => __( 'Journeys Active in Groups', 'dt-journeys' ),
                'instructions'     => __( 'Instructions', 'dt-journeys' ),
                'attachments'      => __( 'Attachments', 'dt-journeys' ),
                'related_fields'   => __( 'Related Fields', 'dt-journeys' ),
            ],
        ] );
    }

    public function dt_add_section( $section, $post_type ) {
        if ( $section !== 'journeys' || !in_array( $post_type, self::POST_TYPES, true ) ) {
            return;
        }
        ?>
        <div class="cell small-12">
            <div id="dt-journeys-container" class="dt-journeys-tile">
                <p class="msg"><?php esc_html_e( 'Loading journeys…', 'dt-journeys' ); ?></p>
            </div>
            <button id="dt-journeys-add-btn" type="button" class="button">
                <?php esc_html_e( 'Add a Journey', 'dt-journeys' ); ?>
            </button>
        </div>
        <?php
    }
}
Dt_Journeys_Tile::instance();
