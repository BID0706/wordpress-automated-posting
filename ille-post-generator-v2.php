<?php
/**
 * Plugin Name: ILLE Post Generator V2
 * Plugin URI:  https://ille.com.ng
 * Description: Generates SEO-optimized posts via admin UI or REST endpoint with supervised/unsupervised workflows.
 * Version:     1.0.1
 * Author:      ILLE
 * License:     GPL-2.0+
 * Text Domain: ille-pg
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ILLE_PG_VERSION',  '1.2.0' );
define( 'ILLE_PG_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ILLE_PG_URL',      plugin_dir_url( __FILE__ ) );
define( 'ILLE_PG_BASENAME', plugin_basename( __FILE__ ) );

require_once ILLE_PG_DIR . 'includes/class-settings.php';
require_once ILLE_PG_DIR . 'includes/class-logger.php';
require_once ILLE_PG_DIR . 'includes/class-ai-generator.php';
require_once ILLE_PG_DIR . 'includes/class-post-creator.php';
require_once ILLE_PG_DIR . 'includes/class-scheduler.php';
require_once ILLE_PG_DIR . 'includes/class-rest-api.php';
require_once ILLE_PG_DIR . 'includes/class-mcp.php';
require_once ILLE_PG_DIR . 'includes/class-admin.php';

function ille_pg_init() {
    new ILLE_PG_REST_API();
    new ILLE_PG_MCP();
    new ILLE_PG_Scheduler();
    if ( is_admin() ) {
        new ILLE_PG_Admin();
    }
}
add_action( 'plugins_loaded', 'ille_pg_init' );

// Async image generation cron callback
add_action( 'ille_pg_image_async', [ 'ILLE_PG_AI_Generator', 'handle_async_image' ], 10, 3 );

register_activation_hook( __FILE__, 'ille_pg_activate' );
function ille_pg_activate() {
    if ( ! get_option( ILLE_PG_Settings::KEY_API_KEY ) ) {
        update_option( ILLE_PG_Settings::KEY_API_KEY, wp_generate_password( 32, false ) );
    }
    ILLE_PG_Scheduler::register_cron_schedules();
}

register_deactivation_hook( __FILE__, 'ille_pg_deactivate' );
function ille_pg_deactivate() {
    ILLE_PG_Scheduler::clear_all_cron_events();
}
