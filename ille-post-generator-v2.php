<?php
/**
 * Plugin Name: ILLE Post Generator V2
 * Plugin URI:  https://ille.com.ng
 * Description: Generates SEO-optimized posts via admin UI or REST endpoint with supervised/unsupervised workflows.
 * Version:     1.6.0
 * Author:      ILLE
 * License:     GPL-2.0+
 * Text Domain: ille-pg
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ILLE_PG_VERSION',  '1.6.0' );
define( 'ILLE_PG_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ILLE_PG_URL',      plugin_dir_url( __FILE__ ) );
define( 'ILLE_PG_BASENAME', plugin_basename( __FILE__ ) );

require_once ILLE_PG_DIR . 'includes/class-crypto.php';
require_once ILLE_PG_DIR . 'includes/class-throttle.php';
require_once ILLE_PG_DIR . 'includes/class-settings.php';
require_once ILLE_PG_DIR . 'includes/class-logger.php';
require_once ILLE_PG_DIR . 'includes/class-ai-generator.php';
require_once ILLE_PG_DIR . 'includes/class-post-creator.php';
require_once ILLE_PG_DIR . 'includes/class-scheduler.php';
require_once ILLE_PG_DIR . 'includes/class-oauth.php';
require_once ILLE_PG_DIR . 'includes/class-rest-api.php';
require_once ILLE_PG_DIR . 'includes/class-mcp.php';
require_once ILLE_PG_DIR . 'includes/class-admin.php';

function ille_pg_init() {
    if ( ILLE_PG_Settings::get_oauth_mode() === 'built_in' ) {
        new ILLE_PG_OAuth();
    }
    new ILLE_PG_REST_API();
    new ILLE_PG_MCP();
    new ILLE_PG_Scheduler();
    if ( is_admin() ) {
        new ILLE_PG_Admin();
    }
}
add_action( 'plugins_loaded', 'ille_pg_init' );

// One-time migration: encrypt secrets (API keys + provider keys) stored as
// plaintext before at-rest encryption was introduced. Runs once in admin.
add_action( 'admin_init', 'ille_pg_maybe_migrate_secrets' );
function ille_pg_maybe_migrate_secrets() {
    if ( get_option( 'ille_pg_secret_migration' ) === '1' ) {
        return;
    }
    ILLE_PG_Settings::migrate_encrypt_api_keys();
    ILLE_PG_Settings::migrate_encrypt_provider_keys();
    update_option( 'ille_pg_secret_migration', '1' );
}

// Async image generation cron callback
add_action( 'ille_pg_image_async', [ 'ILLE_PG_AI_Generator', 'handle_async_image' ], 10, 3 );

register_activation_hook( __FILE__, 'ille_pg_activate' );
function ille_pg_activate() {
    // Note: API keys are per-user (user meta), generated on demand — no global
    // key option is created here. A stray reference to a non-existent
    // KEY_API_KEY constant used to fatal this hook before the cron schedules
    // were registered, which silently broke all scheduled posts (issue #12).
    ILLE_PG_Scheduler::register_cron_schedules();
    flush_rewrite_rules(); // ensure .well-known rewrite rule activates immediately
}

register_deactivation_hook( __FILE__, 'ille_pg_deactivate' );
function ille_pg_deactivate() {
    ILLE_PG_Scheduler::clear_all_cron_events();
}
