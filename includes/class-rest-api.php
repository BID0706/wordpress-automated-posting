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

        register_rest_route(
            ILLE_PG_Settings::get_rest_namespace(),
            '/upload-image',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_image_upload' ],
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

    // -------------------------------------------------------------------------
    // POST /upload-image — multipart file upload (no base64 size limits)
    // -------------------------------------------------------------------------
    // Usage:
    //   curl -X POST https://site.com/wp-json/ille/v2/upload-image \
    //        -H "X-API-Key: YOUR_KEY" \
    //        -F "file=@/path/to/image.jpg" \
    //        -F "alt_text=My image" \
    //        -F "post_id=123"
    // -------------------------------------------------------------------------

    public function handle_image_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $files = $request->get_file_params();

        if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
            $err = $files['file']['error'] ?? 'missing';
            return new WP_REST_Response(
                [ 'error' => "No valid file received (error: {$err}). Send a multipart/form-data POST with a \"file\" field." ],
                400
            );
        }

        $actor_id = is_user_logged_in()
            ? get_current_user_id()
            : (int) $request->get_param( '_ille_pg_user_id' );

        $alt_text = sanitize_text_field( $request->get_param( 'alt_text' ) ?: '' );
        $post_id  = (int) ( $request->get_param( 'post_id' ) ?: 0 );

        // media_handle_upload reads directly from $_FILES['file']
        $att_id = media_handle_upload( 'file', 0, [], [ 'test_form' => false ] );

        if ( is_wp_error( $att_id ) ) {
            return new WP_REST_Response( [ 'error' => $att_id->get_error_message() ], 422 );
        }

        // Image-type enforcement
        if ( ! wp_attachment_is_image( $att_id ) ) {
            wp_delete_attachment( $att_id, true );
            return new WP_REST_Response(
                [ 'error' => 'Uploaded file is not a valid image. Only JPEG, PNG, GIF, and WebP are accepted.' ],
                422
            );
        }

        if ( $alt_text ) {
            update_post_meta( $att_id, '_wp_attachment_image_alt', $alt_text );
        }

        $set_as_featured = false;
        if ( $post_id && get_post( $post_id ) ) {
            set_post_thumbnail( $post_id, $att_id );
            $set_as_featured = true;
        }

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_POST_CREATED, [
            'action'          => 'image_uploaded',
            'attachment_id'   => $att_id,
            'filename'        => $files['file']['name'],
            'set_as_featured' => $set_as_featured,
            'post_id'         => $post_id ?: null,
        ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );

        return rest_ensure_response( [
            'success'         => true,
            'attachment_id'   => $att_id,
            'url'             => wp_get_attachment_url( $att_id ),
            'set_as_featured' => $set_as_featured,
            'post_id'         => $post_id ?: null,
        ] );
    }
}
