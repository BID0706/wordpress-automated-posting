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

        // 2. API key authentication (for external/cron callers)
        $stored_key  = ILLE_PG_Settings::get_api_key();
        $provided    = $request->get_header( 'X-API-Key' ) ?: $request->get_param( 'api_key' );

        if ( empty( $stored_key ) ) {
            return new WP_Error( 'not_configured', 'Endpoint API key is not configured.', [ 'status' => 503 ] );
        }

        if ( ! $provided || ! hash_equals( $stored_key, (string) $provided ) ) {
            return new WP_Error( 'unauthorized', 'Invalid or missing API key.', [ 'status' => 401 ] );
        }

        return true;
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $allowed_params = ILLE_PG_Settings::get_allowed_params();

        $args = [];

        if ( in_array( 'topic', $allowed_params, true ) ) {
            $args['topic'] = sanitize_text_field( $request->get_param( 'topic' ) ?: '' );
        }

        if ( in_array( 'focus_keyword', $allowed_params, true ) ) {
            $args['focus_keyword'] = sanitize_text_field( $request->get_param( 'focus_keyword' ) ?: '' );
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
