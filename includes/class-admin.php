<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_Admin {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ille_pg_generate',       [ $this, 'ajax_generate' ] );
        add_action( 'wp_ajax_ille_pg_save_settings',  [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_ille_pg_regenerate_key', [ $this, 'ajax_regenerate_key' ] );
        add_action( 'wp_ajax_ille_pg_test_endpoint',  [ $this, 'ajax_test_endpoint' ] );
        add_action( 'wp_ajax_ille_pg_log_export',     [ $this, 'ajax_log_export' ] );
        add_action( 'wp_ajax_ille_pg_log_truncate',   [ $this, 'ajax_log_truncate' ] );
        add_action( 'wp_ajax_ille_pg_log_delete',     [ $this, 'ajax_log_delete' ] );
        add_action( 'admin_init', [ $this, 'maybe_flush_rewrite_rules' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function register_menu() {
        add_menu_page(
            'Post Generator',
            'Post Generator',
            'edit_posts',
            'ille-pg',
            [ $this, 'render_main_page' ],
            'dashicons-edit-large',
            25
        );

        add_submenu_page(
            'ille-pg',
            'Generate Post',
            'Generate Post',
            'edit_posts',
            'ille-pg',
            [ $this, 'render_main_page' ]
        );

        add_submenu_page(
            'ille-pg',
            'Settings',
            'Settings',
            'manage_options',
            'ille-pg-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_assets( string $hook ) {
        if ( ! in_array( $hook, [ 'toplevel_page_ille-pg', 'post-generator_page_ille-pg-settings' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'ille-pg-admin',
            ILLE_PG_URL . 'admin/assets/admin.css',
            [],
            ILLE_PG_VERSION
        );

        wp_enqueue_media();

        wp_enqueue_script(
            'ille-pg-admin',
            ILLE_PG_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            ILLE_PG_VERSION,
            true
        );

        wp_localize_script( 'ille-pg-admin', 'ILLE_PG', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ille_pg_nonce' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Page renderers
    // -------------------------------------------------------------------------

    public function render_main_page() {
        require ILLE_PG_DIR . 'admin/views/main-page.php';
    }

    public function render_settings_page() {
        require ILLE_PG_DIR . 'admin/views/settings-page.php';
    }

    // -------------------------------------------------------------------------
    // AJAX: Generate Post
    // -------------------------------------------------------------------------

    public function ajax_generate() {
        check_ajax_referer( 'ille_pg_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }

        $args = [
            'topic'          => sanitize_text_field( $_POST['topic']          ?? '' ),
            'focus_keyword'  => sanitize_text_field( $_POST['focus_keyword']  ?? '' ),
            'featured_image' => filter_var( $_POST['featured_image'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'post_status'    => sanitize_key( $_POST['post_status'] ?? 'publish' ),
            'scheduled_date' => sanitize_text_field( $_POST['scheduled_date'] ?? '' ),
            'author_id'      => get_current_user_id(),
        ];

        if ( ! in_array( $args['post_status'], [ 'publish', 'draft' ], true ) ) {
            $args['post_status'] = 'publish';
        }

        $post_id = ILLE_PG_Post_Creator::create( $args );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        wp_send_json_success( [
            'post_id'     => $post_id,
            'post_url'    => get_permalink( $post_id ),
            'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
            'post_status' => get_post_status( $post_id ),
            'title'       => get_the_title( $post_id ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Save Settings
    // -------------------------------------------------------------------------

    public function ajax_save_settings() {
        check_ajax_referer( 'ille_pg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }

        $fields = $_POST['settings'] ?? [];

        // Simple text/password/radio fields
        $string_keys = [
            ILLE_PG_Settings::KEY_GEMINI_KEY,
            ILLE_PG_Settings::KEY_OPENAI_KEY,
            ILLE_PG_Settings::KEY_XAI_KEY,
            ILLE_PG_Settings::KEY_ACTIVE_MODEL,
            ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT,
        ];

        $endpoint_changed = isset( $fields[ ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT ] )
            && $fields[ ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT ] !== get_option( ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT, '' );

        foreach ( $string_keys as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $prev = get_option( $key, '' );
                $new  = sanitize_textarea_field( $fields[ $key ] );
                update_option( $key, $new );
                ILLE_PG_Logger::log_settings_change( $key, $prev, $new );
            }
        }

        // Default image (integer attachment ID)
        if ( isset( $fields[ ILLE_PG_Settings::KEY_DEFAULT_IMAGE ] ) ) {
            $img_id = absint( $fields[ ILLE_PG_Settings::KEY_DEFAULT_IMAGE ] );
            update_option( ILLE_PG_Settings::KEY_DEFAULT_IMAGE, $img_id );
        }

        // Prompt fields — allow safe HTML (sanitize_textarea_field strips tags)
        $prompt_keys = [
            ILLE_PG_Settings::KEY_POST_PROMPT,
            ILLE_PG_Settings::KEY_IMAGE_PROMPT,
        ];
        foreach ( $prompt_keys as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $prev = get_option( $key, '' );
                $new  = wp_kses_post( $fields[ $key ] );
                update_option( $key, $new );
                ILLE_PG_Logger::log_settings_change( $key, $prev, $new );
            }
        }

        // Schedule a rewrite flush on the next full page load so rest_api_init
        // re-registers routes with the new slug before flushing.
        if ( $endpoint_changed ) {
            update_option( 'ille_pg_needs_flush', '1' );
        }

        // Allowed roles (array)
        if ( isset( $fields[ ILLE_PG_Settings::KEY_ALLOWED_ROLES ] ) ) {
            $prev_roles = ILLE_PG_Settings::get_allowed_roles();
            $roles      = array_map( 'sanitize_key', (array) $fields[ ILLE_PG_Settings::KEY_ALLOWED_ROLES ] );
            update_option( ILLE_PG_Settings::KEY_ALLOWED_ROLES, $roles );
            ILLE_PG_Logger::log_settings_change( ILLE_PG_Settings::KEY_ALLOWED_ROLES, implode( ', ', $prev_roles ), implode( ', ', $roles ) );
        }

        // Allowed params (array)
        if ( isset( $fields[ ILLE_PG_Settings::KEY_ALLOWED_PARAMS ] ) ) {
            $prev_params = ILLE_PG_Settings::get_allowed_params();
            $params      = array_map( 'sanitize_key', (array) $fields[ ILLE_PG_Settings::KEY_ALLOWED_PARAMS ] );
            update_option( ILLE_PG_Settings::KEY_ALLOWED_PARAMS, $params );
            ILLE_PG_Logger::log_settings_change( ILLE_PG_Settings::KEY_ALLOWED_PARAMS, implode( ', ', $prev_params ), implode( ', ', $params ) );
        }

        // Schedules
        if ( isset( $fields[ ILLE_PG_Settings::KEY_SCHEDULES ] ) ) {
            $prev_schedules = ILLE_PG_Settings::get_schedules();
            $raw            = (array) $fields[ ILLE_PG_Settings::KEY_SCHEDULES ];
            $schedules      = [];
            foreach ( array_slice( $raw, 0, ILLE_PG_Settings::MAX_SCHEDULES ) as $s ) {
                $schedules[] = [
                    'enabled'     => ! empty( $s['enabled'] ),
                    'label'       => sanitize_text_field( $s['label']       ?? '' ),
                    'days'        => array_map( 'sanitize_key', (array) ( $s['days'] ?? [] ) ),
                    'time'        => sanitize_text_field( $s['time']        ?? '08:00' ),
                    'topic'       => sanitize_text_field( $s['topic']       ?? '' ),
                    'post_status' => in_array( $s['post_status'] ?? '', [ 'publish', 'draft' ], true )
                                        ? $s['post_status']
                                        : 'publish',
                ];
            }
            update_option( ILLE_PG_Settings::KEY_SCHEDULES, $schedules );
            ILLE_PG_Scheduler::sync_schedules();

            // Log each schedule that actually changed
            foreach ( $schedules as $i => $new_s ) {
                $old_s = $prev_schedules[ $i ] ?? [];
                $label = $new_s['label'] ?: ( 'Schedule ' . ( $i + 1 ) );
                if ( $old_s !== $new_s ) {
                    ILLE_PG_Logger::log_settings_change(
                        ILLE_PG_Settings::KEY_SCHEDULES . '[' . $i . '] ' . $label,
                        $this->format_schedule( $old_s ),
                        $this->format_schedule( $new_s )
                    );
                }
            }
        }

        wp_send_json_success( [
            'message'          => 'Settings saved.',
            'endpoint_changed' => $endpoint_changed,
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Regenerate API Key
    // -------------------------------------------------------------------------

    public function ajax_regenerate_key() {
        check_ajax_referer( 'ille_pg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid user.' ], 400 );
        }

        $had_key = ! empty( ILLE_PG_Settings::get_user_api_key( $user_id ) );
        $new_key = ILLE_PG_Settings::generate_user_api_key( $user_id );

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_API_KEY_ACTION, [
            'action'          => $had_key ? 'regenerated' : 'generated',
            'target_user_id'  => $user_id,
            'target_username' => get_userdata( $user_id )->display_name ?? '',
        ] );

        wp_send_json_success( [
            'api_key' => $new_key,
            'user_id' => $user_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function format_schedule( array $s ): string {
        if ( empty( $s ) ) return '(none)';
        $enabled = empty( $s['enabled'] ) ? 'off' : 'on';
        $days    = implode( '/', $s['days'] ?? [] ) ?: 'daily';
        $time    = $s['time'] ?? '08:00';
        $status  = $s['post_status'] ?? 'publish';
        $topic   = $s['topic'] ? ' | "' . $s['topic'] . '"' : '';
        return "{$enabled} | {$days} @ {$time} | {$status}{$topic}";
    }

    // -------------------------------------------------------------------------
    // AJAX: Log management
    // -------------------------------------------------------------------------

    private function require_admin_log(): void {
        check_ajax_referer( 'ille_pg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
    }

    public function ajax_log_export() {
        $this->require_admin_log();

        $csv      = ILLE_PG_Logger::get_csv();
        $filename = 'ille-pg-audit-' . current_time( 'Y-m-d' ) . '.csv';

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_LOG_EXPORTED, [ 'filename' => $filename ] );

        wp_send_json_success( [
            'csv'      => base64_encode( $csv ),
            'filename' => $filename,
        ] );
    }

    public function ajax_log_truncate() {
        $this->require_admin_log();

        $stats = ILLE_PG_Logger::get_stats();
        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_LOG_TRUNCATED, [ 'entries_cleared' => $stats['count'] ] );
        ILLE_PG_Logger::truncate();

        wp_send_json_success( [ 'message' => 'Log truncated.' ] );
    }

    public function ajax_log_delete() {
        $this->require_admin_log();

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_LOG_DELETED, [] );
        ILLE_PG_Logger::delete();

        wp_send_json_success( [ 'message' => 'Log deleted.' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: Test Endpoint
    // -------------------------------------------------------------------------

    public function ajax_test_endpoint() {
        check_ajax_referer( 'ille_pg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }

        $endpoint_url = ILLE_PG_Settings::get_endpoint_url();

        // Probe the route via the WordPress REST server directly (no HTTP round-trip)
        $server = rest_get_server();
        $routes = $server->get_routes( ILLE_PG_Settings::get_rest_namespace() );
        $route  = '/' . ILLE_PG_Settings::get_rest_namespace() . '/' . ILLE_PG_Settings::get_rest_route();
        $active = isset( $routes[ $route ] );

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_ENDPOINT_TESTED, [
            'route'  => $route,
            'active' => $active,
        ] );

        wp_send_json_success( [
            'active'       => $active,
            'endpoint_url' => $endpoint_url,
            'route'        => $route,
            'status'       => $active ? 'registered' : 'not_found',
        ] );
    }

    // -------------------------------------------------------------------------
    // Flush rewrite rules on first load after a slug change
    // -------------------------------------------------------------------------

    public function maybe_flush_rewrite_rules() {
        if ( get_option( 'ille_pg_needs_flush' ) ) {
            delete_option( 'ille_pg_needs_flush' );
            flush_rewrite_rules();
        }
    }
}
