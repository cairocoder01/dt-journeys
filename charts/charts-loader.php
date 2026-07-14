<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

class Dt_Journeys_Charts
{
    private static $_instance = null;
    public static function instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct(){

        require_once( 'one-page-chart-template.php' );
        new Dt_Journeys_Chart_Template();

        /**
         * @todo add other charts like the pattern above here
         */
    } // End __construct
}
Dt_Journeys_Charts::instance();
