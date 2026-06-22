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

        // Simple string fields
        $string_keys = [
            ILLE_PG_Settings::KEY_GEMINI_KEY,
            ILLE_PG_Settings::KEY_OPENAI_KEY,
            ILLE_PG_Settings::KEY_XAI_KEY,
            ILLE_PG_Settings::KEY_ACTIVE_MODEL,
            ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT,
            ILLE_PG_Settings::KEY_POST_PROMPT,
            ILLE_PG_Settings::KEY_IMAGE_PROMPT,
        ];

        $endpoint_changed = isset( $fields[ ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT ] )
            && $fields[ ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT ] !== get_option( ILLE_PG_Settings::KEY_CUSTOM_ENDPOINT, '' );

        foreach ( $string_keys as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                update_option( $key, sanitize_textarea_field( $fields[ $key ] ) );
            }
        }

        // Schedule a rewrite flush on the next full page load so rest_api_init
        // re-registers routes with the new slug before flushing.
        if ( $endpoint_changed ) {
            update_option( 'ille_pg_needs_flush', '1' );
        }

        // Allowed roles (array)
        if ( isset( $fields[ ILLE_PG_Settings::KEY_ALLOWED_ROLES ] ) ) {
            $roles = array_map( 'sanitize_key', (array) $fields[ ILLE_PG_Settings::KEY_ALLOWED_ROLES ] );
            update_option( ILLE_PG_Settings::KEY_ALLOWED_ROLES, $roles );
        }

        // Allowed params (array)
        if ( isset( $fields[ ILLE_PG_Settings::KEY_ALLOWED_PARAMS ] ) ) {
            $params = array_map( 'sanitize_key', (array) $fields[ ILLE_PG_Settings::KEY_ALLOWED_PARAMS ] );
            update_option( ILLE_PG_Settings::KEY_ALLOWED_PARAMS, $params );
        }

        // Schedules
        if ( isset( $fields[ ILLE_PG_Settings::KEY_SCHEDULES ] ) ) {
            $raw       = (array) $fields[ ILLE_PG_Settings::KEY_SCHEDULES ];
            $schedules = [];
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

        $new_key = wp_generate_password( 32, false );
        update_option( ILLE_PG_Settings::KEY_API_KEY, $new_key );

        wp_send_json_success( [ 'api_key' => $new_key ] );
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
