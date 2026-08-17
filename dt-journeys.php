<?php
/**
 * Plugin Name: Disciple.Tools - Journeys
 * Plugin URI: https://github.com/cairocoder01/dt-journeys
 * Description: Disciple.Tools - Journeys is intended to help developers and integrator jumpstart their extension of the Disciple.Tools system.
 * Text Domain: dt-journeys
 * Domain Path: /languages
 * Version:  0.1.0
 * Author URI: https://github.com/DiscipleTools
 * GitHub Plugin URI: https://github.com/cairocoder01/dt-journeys
 * Requires at least: 4.7.0
 * (Requires 4.7+ because of the integration of the REST API at 4.7 and the security requirements of this milestone version.)
 * Tested up to: 5.6
 *
 * @package Disciple_Tools
 * @link    https://github.com/DiscipleTools
 * @license GPL-2.0 or later
 *          https://www.gnu.org/licenses/gpl-2.0.html
 */

/**
 * Disciple.Tools - Journeys
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Gets the instance of the `Dt_Journeys` class.
 *
 * @since  0.1
 * @access public
 * @return object|bool
 */
function disciple_tools_journeys() {
    $dt_journeys_required_dt_theme_version = '1.19';
    $wp_theme = wp_get_theme();
    $version = $wp_theme->version;

    /*
     * Check if the Disciple.Tools theme is loaded and is the latest required version
     */
    $is_theme_dt = class_exists( 'Disciple_Tools' );
    if ( $is_theme_dt && version_compare( $version, $dt_journeys_required_dt_theme_version, '<' ) ) {
        add_action( 'admin_notices', 'dt_journeys_hook_admin_notice' );
        add_action( 'wp_ajax_dismissed_notice_handler', 'dt_hook_ajax_notice_handler' );
        return false;
    }
    if ( !$is_theme_dt ){
        return false;
    }
    /**
     * Load useful function from the theme
     */
    if ( !defined( 'DT_FUNCTIONS_READY' ) ){
        require_once get_template_directory() . '/dt-core/global-functions.php';
    }

    return Dt_Journeys::instance();
}
add_action( 'after_setup_theme', 'disciple_tools_journeys', 20 );

//register the D.T Plugin
add_filter( 'dt_plugins', function ( $plugins ){
    $plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version', 'Plugin Name' => 'Plugin Name' ], false );
    $plugins['dt-journeys'] = [
        'plugin_url' => trailingslashit( plugin_dir_url( __FILE__ ) ),
        'version' => $plugin_data['Version'] ?? null,
        'name' => $plugin_data['Plugin Name'] ?? null,
    ];
    return $plugins;
});

/**
 * Singleton class for setting up the plugin.
 *
 * @since  0.1
 * @access public
 */
class Dt_Journeys {

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        $is_rest = dt_is_rest();
        /**
         * @todo Decide if you want to use the REST API example
         * To remove: delete this following line and remove the folder named /rest-api
         */
        if ( $is_rest && strpos( dt_get_url_path(), 'dt-journeys' ) !== false ) {
            require_once( 'rest-api/rest-api.php' ); // adds starter rest api class
        }

        /**
         * @todo Decide if you want to create a new post type
         * To remove: delete the line below and remove the folder named /post-type
         */
        require_once( 'post-type/loader.php' ); // add starter post type extension to Disciple.Tools system

        // Progress service: tracks a record's progress through a journey (post meta).
        require_once( 'progress/loader.php' );

        /**
         * @todo Decide if you want to create a custom site-to-site link
         * To remove: delete the line below and remove the folder named /site-link
         */
        require_once( 'site-link/custom-site-to-site-links.php' ); // add site to site link class and capabilities

        /**
         * @todo Decide if you want to add new charts to the metrics section
         * To remove: delete the line below and remove the folder named /charts
         */
        if ( strpos( dt_get_url_path(), 'metrics' ) !== false || ( $is_rest && strpos( dt_get_url_path(), 'dt-journeys-metrics' ) !== false ) ){
            require_once( 'charts/charts-loader.php' );  // add custom charts to the metrics area
        }

        /**
         * @todo Decide if you want to add a custom tile or settings page tile
         * To remove: delete the lines below and remove the folder named /tile
         */
        require_once( 'tile/custom-tile.php' ); // add custom tile
        if ( 'settings' === dt_get_url_path() && ! $is_rest ) {
            require_once( 'tile/profile-settings-tile.php' ); // add custom settings page tile
        }

        /**
         * @todo Decide if you want to add a custom admin page in the admin area
         * To remove: delete the 3 lines below and remove the folder named /admin
         */
        if ( is_admin() ) {
            require_once( 'admin/admin-menu-and-tabs.php' ); // adds starter admin page and section for plugin
        }

        /**
         * @todo Decide if you want to support localization of your plugin
         * To remove: delete the line below and remove the folder named /languages
         */
        $this->i18n();

        /**
         * @todo Decide if you want to customize links for your plugin in the plugin admin area
         * To remove: delete the lines below and remove the function named "plugin_description_links"
         */
        if ( is_admin() ) { // adds links to the plugin description area in the plugin admin list.
            add_filter( 'plugin_row_meta', [ $this, 'plugin_description_links' ], 10, 4 );
        }

        /**
         * @todo Decide if you want to create default workflows
         * To remove: delete the line below and remove the folder named /workflows
         */
        require_once( 'workflows/workflows.php' );
        add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ], 99 );
        
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_include', [ $this, 'load_journeys_template' ] );
        add_filter( 'dt_nav', function ( $nav ){
            $nav['admin']['settings']['submenu']['journeys'] = [
                'label'  => __( 'Journeys', 'disciple_tools' ),
                'link'   => site_url( '/admin/journeys/' ),
                'hidden' => ( ! current_user_can( 'manage_dt' ) ),
                'icon'   => get_template_directory_uri() . '/dt-assets/images/settings.svg'
            ];
            return $nav;
        });

        add_filter( 'script_loader_tag', [ $this, 'script_loader_tag' ], 10, 3 );

        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    }

    public function add_api_routes() {
        $namespace = 'dt-journeys/v1';

        register_rest_route(
            $namespace, '/get-journeys', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'get_journeys_endpoint' ],
                    'permission_callback' => '__return_true',
                ],
            ]
        );

        register_rest_route(
            $namespace, '/delete-journey', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'delete_journey_endpoint' ],
                    'permission_callback' => '__return_true',
                ]
            ]
        );

        register_rest_route(
            $namespace, '/duplicate-journey', [
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
        $raw_journeys = self::get_journeys($params);

        return [
            'journeys'       => $raw_journeys['posts'],
            'total_journeys' => $raw_journeys['total']
        ];
    }

    public function delete_journey_endpoint( WP_REST_Request $request ) {
        $params = $request->get_params();
        $journey_id = isset($params['journey_id']) ? $params['journey_id'] : null;

        if (!$journey_id) {
            return new WP_REST_Response(['error' => 'Invalid journey ID'], 400);
        }

        self::delete_journey($journey_id);
        return new WP_REST_Response(['message' => 'Journey deleted successfully'], 200);
    }

    public function duplicate_journey_endpoint( WP_REST_Request $request ) {
        $params = $request->get_params();
        $journey_id = isset($params['journey_id']) ? $params['journey_id'] : null;

        if (!$journey_id) {
            return new WP_REST_Response(['error' => 'Invalid journey ID'], 400);
        }

        $new_journey_id = self::duplicate_journey($journey_id);
        return new WP_REST_Response(['journey_id' => $new_journey_id], 200);
    }

    public function add_rewrite_rules() {
        add_rewrite_rule( '^admin/journeys/?$', 'index.php?dt_journeys_page=1', 'top' );
    }

    public function register_query_vars( $query_vars ) {
        $query_vars[] = 'dt_journeys_page';
        return $query_vars;
    }

    public function load_journeys_template( $template ) {
        if ( get_query_var( 'dt_journeys_page' ) == false || get_query_var( 'dt_journeys_page' ) == '' ) {
            return $template;
        }

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_die( __( 'You do not have permission to view this page.', 'disciple_tools' ) );
        }

        $plugin_dir = plugin_dir_path( __FILE__ );
        $custom_template = $plugin_dir . 'templates/template-journeys.php';

        if ( file_exists( $custom_template ) ) {
            return $custom_template;
        }

        return $template;
    }

    public function scripts() {
        if ( get_query_var( 'dt_journeys_page' ) == false || get_query_var( 'dt_journeys_page' ) == '' ) {
            return; 
        }

        wp_enqueue_script('journeys_table', plugin_dir_url(__FILE__) . 'templates/journeys-table.js', ['jquery'], '1.0', true);

        $post_settings = DT_Posts::get_post_settings( 'journeys' );
        $journey_fields = isset( $post_settings['fields'] ) ? $post_settings['fields'] : [];
        
        wp_localize_script( 'journeys_table', 'journeys_table', [
            'translations' => [
                'go' => __( 'Go', 'disciple_tools' ),
                'search' => __( 'Search', 'disciple_tools' ),
                'journeys' => __( 'Journeys', 'disciple_tools' ),
                'showing_x_of_y' => __( 'Showing %1$s of %2$s', 'disciple_tools' ),
                'create_journey' => __( 'New Journey', 'disciple_tools' ),
            ],
            'fields' => $journey_fields,
            'rest_endpoint' => trailingslashit( rest_url( 'dt-journeys/v1/' ) ),
        ] );
    }

    public function script_loader_tag( $tag, $handle, $src ) {
        if ($handle === 'journeys_table') {
            return '<script type="module" src="' . esc_url($src) . '"></script>';
        }
        return $tag;
    }

    /**
     * Filters the array of row meta for each/specific plugin in the Plugins list table.
     * Appends additional links below each/specific plugin on the plugins page.
     */
    public function plugin_description_links( $links_array, $plugin_file_name, $plugin_data, $status ) {
        if ( strpos( $plugin_file_name, basename( __FILE__ ) ) ) {
            // You can still use `array_unshift()` to add links at the beginning.

            $links_array[] = '<a href="https://disciple.tools">Disciple.Tools Community</a>'; // @todo replace with your links.
            // @todo add other links here
        }

        return $links_array;
    }

    /**
     * Method that runs only when the plugin is activated.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public static function activation() {
        // Force Disciple.Tools to re-apply role capabilities on the next load so
        // the journeys/journey_stages access capabilities are granted to existing
        // roles (e.g. admins, multipliers) without waiting for a theme roles bump.
        delete_option( 'dt_roles_number' );

        // Add the routing rules and flush so they work immediately upon activation
        self::instance()->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Method that runs only when the plugin is deactivated.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public static function deactivation() {
        // add functions here that need to happen on deactivation
        delete_option( 'dismissed-dt-journeys' );
        flush_rewrite_rules();
    }

    /**
     * Loads the translation files.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function i18n() {
        $domain = 'dt-journeys';
        load_plugin_textdomain( $domain, false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ). 'languages' );
    }

    /**
     * Magic method to output a string if trying to use the object as a string.
     *
     * @since  0.1
     * @access public
     * @return string
     */
    public function __toString() {
        return 'dt-journeys';
    }

    /**
     * Magic method to keep the object from being cloned.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, 'Whoah, partner!', '0.1' );
    }

    /**
     * Magic method to keep the object from being unserialized.
     *
     * @since  0.1
     * @access public
     * @return void
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, 'Whoah, partner!', '0.1' );
    }

    /**
     * Magic method to prevent a fatal error when calling a method that doesn't exist.
     *
     * @param string $method
     * @param array $args
     * @return null
     * @since  0.1
     * @access public
     */
    public function __call( $method = '', $args = array() ) {
        _doing_it_wrong( 'dt_journeys::' . esc_html( $method ), 'Method does not exist.', '0.1' );
        unset( $method, $args );
        return null;
    }

    public static function get_journeys($params = []) {
        $searchParameters = [];
        foreach ($params['searchParameters'] as $key => $value) {
            if ($key === 'sort' || $key === 'text') {
                $searchParameters[$key] = $value;
            } else if ($key === 'is_sequential' && $value === 0) {
                $searchParameters[$key] = [''];
            } else {
                $searchParameters[$key] = [$value];
            }
        }
        $journeys = DT_Posts::list_posts( 'journeys', $searchParameters );
        return $journeys;
    }

    public static function delete_journey($journey_id) {
        DT_Posts::delete_post( 'journeys', $journey_id );
    }

    function duplicate_journey( $original_id ) {
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
                        $update_args[ $field_key ]['values'][] = array(
                            'value' => $connection['ID']
                        );
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
}


// Register activation hook.
register_activation_hook( __FILE__, [ 'Dt_Journeys', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'Dt_Journeys', 'deactivation' ] );


if ( ! function_exists( 'dt_journeys_hook_admin_notice' ) ) {
    function dt_journeys_hook_admin_notice() {
        global $dt_journeys_required_dt_theme_version;
        $wp_theme = wp_get_theme();
        $current_version = $wp_theme->version;
        $message = "'Disciple.Tools - Journeys' plugin requires 'Disciple.Tools' theme to work. Please activate 'Disciple.Tools' theme or make sure it is latest version.";
        if ( $wp_theme->get_template() === 'disciple-tools-theme' ){
            $message .= ' ' . sprintf( esc_html( 'Current Disciple.Tools version: %1$s, required version: %2$s' ), esc_html( $current_version ), esc_html( $dt_journeys_required_dt_theme_version ) );
        }
        // Check if it's been dismissed...
        if ( ! get_option( 'dismissed-dt-journeys', false ) ) { ?>
            <div class="notice notice-error notice-dt-journeys is-dismissible" data-notice="dt-journeys">
                <p><?php echo esc_html( $message );?></p>
            </div>
            <script>
                jQuery(function($) {
                    $( document ).on( 'click', '.notice-dt-journeys .notice-dismiss', function () {
                        $.ajax( ajaxurl, {
                            type: 'POST',
                            data: {
                                action: 'dismissed_notice_handler',
                                type: 'dt-journeys',
                                security: '<?php echo esc_html( wp_create_nonce( 'wp_rest_dismiss' ) ) ?>'
                            }
                        })
                    });
                });
            </script>
        <?php }
    }
}

/**
 * AJAX handler to store the state of dismissible notices.
 */
if ( !function_exists( 'dt_hook_ajax_notice_handler' ) ){
    function dt_hook_ajax_notice_handler(){
        check_ajax_referer( 'wp_rest_dismiss', 'security' );
        if ( isset( $_POST['type'] ) ){
            $type = sanitize_text_field( wp_unslash( $_POST['type'] ) );
            update_option( 'dismissed-' . $type, true );
        }
    }
}

/**
 * Check for plugin updates even when the active theme is not Disciple.Tools
 *
 * Below is the publicly hosted .json file that carries the version information. This file can be hosted
 * anywhere as long as it is publicly accessible. You can download the version file listed below and use it as
 * a template.
 * Also, see the instructions for version updating to understand the steps involved.
 * @see https://github.com/DiscipleTools/disciple-tools-version-control/wiki/How-to-Update-the-Starter-Plugin
 */
add_action( 'plugins_loaded', function (){
    if ( ( is_admin() || wp_doing_cron() ) && !( is_multisite() && class_exists( 'DT_Multisite' ) ) ){
        // Check for plugin updates
        if ( ! class_exists( 'Puc_v4_Factory' ) ) {
            if ( file_exists( get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php' ) ){
                require( get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php' );
            }
        }
        if ( class_exists( 'Puc_v4_Factory' ) ){
            Puc_v4_Factory::buildUpdateChecker(
                'https://raw.githubusercontent.com/cairocoder01/dt-journeys/master/version-control.json',
                __FILE__,
                'dt-journeys'
            );

        }
    }
} );