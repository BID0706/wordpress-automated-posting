<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_REST_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route(
            ILLE_PG_Settings::get_rest_namespace(),
            '/' . ILLE_PG_Settings::get_rest_route(),
            [
                'methods'             => [ 'GET', 'POST' ],
                'callback'            => [ $this, 'handle_request' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ]
        );
    }

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        // 1. Authenticated WordPress session with an allowed role
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            foreach ( ILLE_PG_Settings::get_allowed_roles() as $role ) {
                if ( in_array( $role, (array) $user->roles, true ) ) {
                    return true;
                }
            }
        }

        // 2. Per-user API key authentication
        $provided = $request->get_header( 'X-API-Key' ) ?: $request->get_param( 'api_key' );

        if ( ! $provided ) {
            ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_ENDPOINT_ERROR, [
                'reason' => 'missing_api_key',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            ], ILLE_PG_Logger::TRIGGER_ENDPOINT, 0 );
            return new WP_Error( 'unauthorized', 'Invalid or missing API key.', [ 'status' => 401 ] );
        }

        $user = ILLE_PG_Settings::get_user_by_api_key( (string) $provided );

        if ( ! $user ) {
            ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_ENDPOINT_ERROR, [
                'reason'      => 'invalid_api_key',
                'key_preview' => substr( $provided, 0, 6 ) . '…',
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
            ], ILLE_PG_Logger::TRIGGER_ENDPOINT, 0 );
            return new WP_Error( 'unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
        }

        // Verify user still has an allowed role
        $allowed = false;
        foreach ( ILLE_PG_Settings::get_allowed_roles() as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                $allowed = true;
                break;
            }
        }

        if ( ! $allowed ) {
            ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_ENDPOINT_ERROR, [
                'reason'   => 'role_not_permitted',
                'user_id'  => $user->ID,
                'username' => $user->user_login,
                'roles'    => implode( ', ', (array) $user->roles ),
            ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $user->ID );
            return new WP_Error( 'forbidden', 'Your role is not permitted to use this endpoint.', [ 'status' => 403 ] );
        }

        // Store resolved user ID on the request for handle_request()
        $request->set_param( '_ille_pg_user_id', $user->ID );
        ILLE_PG_Settings::touch_api_key( $user->ID );

        return true;
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed_params = ILLE_PG_Settings::get_allowed_params();

        // Resolve author: browser session takes precedence, then API key user
        $author_id = is_user_logged_in()
            ? get_current_user_id()
            : (int) $request->get_param( '_ille_pg_user_id' );

        $args = [
            'author_id' => $author_id,
            'trigger'   => ILLE_PG_Logger::TRIGGER_ENDPOINT,
        ];

        if ( in_array( 'topic', $allowed_params, true ) ) {
            $args['topic'] = sanitize_text_field( $request->get_param( 'topic' ) ?: '' );
        }

        if ( in_array( 'focus_keyword', $allowed_params, true ) ) {
            $args['focus_keyword'] = ILLE_PG_Post_Creator::sanitize_keyword(
                sanitize_text_field( $request->get_param( 'focus_keyword' ) ?: '' )
            );
        }

        if ( in_array( 'featured_image', $allowed_params, true ) ) {
            $fi = $request->get_param( 'featured_image' );
            $args['featured_image'] = ( $fi === null ) ? true : filter_var( $fi, FILTER_VALIDATE_BOOLEAN );
        } else {
            $args['featured_image'] = true;
        }

        if ( in_array( 'publish', $allowed_params, true ) ) {
            $publish = $request->get_param( 'publish' );
            $args['post_status'] = ( $publish === null || filter_var( $publish, FILTER_VALIDATE_BOOLEAN ) )
                ? 'publish'
                : 'draft';
        } else {
            $args['post_status'] = 'publish';
        }

        $post_id = ILLE_PG_Post_Creator::create( $args );

        if ( is_wp_error( $post_id ) ) {
            ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_ENDPOINT_ERROR, [
                'reason'    => 'post_creation_failed',
                'error'     => $post_id->get_error_message(),
                'topic'     => $args['topic'] ?? '',
                'author_id' => $args['author_id'] ?? 0,
            ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $args['author_id'] ?? 0 );
            return $post_id;
        }

        return rest_ensure_response( [
            'success'     => true,
            'post_id'     => $post_id,
            'post_url'    => get_permalink( $post_id ),
            'post_status' => get_post_status( $post_id ),
            'title'       => get_the_title( $post_id ),
        ] );
    }
}
