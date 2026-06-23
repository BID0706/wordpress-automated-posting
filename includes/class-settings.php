<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_Settings {

    // Option keys
    const KEY_ALLOWED_ROLES    = 'ille_pg_allowed_roles';
    const KEY_POST_PROMPT      = 'ille_pg_post_prompt';
    const KEY_IMAGE_PROMPT     = 'ille_pg_image_prompt';
    const KEY_ACTIVE_MODEL     = 'ille_pg_active_model';
    const KEY_GEMINI_KEY       = 'ille_pg_gemini_api_key';
    const KEY_OPENAI_KEY       = 'ille_pg_openai_api_key';
    const KEY_XAI_KEY          = 'ille_pg_xai_api_key';
    const KEY_CUSTOM_ENDPOINT  = 'ille_pg_custom_endpoint';
    const KEY_ALLOWED_PARAMS   = 'ille_pg_allowed_params';
    const KEY_SCHEDULES        = 'ille_pg_schedules';
    const KEY_DEFAULT_IMAGE    = 'ille_pg_default_image';
    const KEY_IMAGE_MODEL      = 'ille_pg_image_model';
    const KEY_POLLINATIONS_KEY = 'ille_pg_pollinations_api_key';

    const MAX_SCHEDULES = 5;

    public static function get( string $key, $default = null ) {
        return get_option( $key, $default );
    }

    public static function set( string $key, $value ): bool {
        return update_option( $key, $value );
    }

    // User meta key for per-user API keys
    const USER_META_API_KEY      = 'ille_pg_api_key';
    const USER_META_API_KEY_LAST = 'ille_pg_api_key_last_used';

    // -------------------------------------------------------------------------
    // Per-user API key helpers
    // -------------------------------------------------------------------------

    public static function get_user_api_key( int $user_id ): string {
        return (string) get_user_meta( $user_id, self::USER_META_API_KEY, true );
    }

    public static function generate_user_api_key( int $user_id ): string {
        $key = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::USER_META_API_KEY, $key );
        delete_user_meta( $user_id, self::USER_META_API_KEY_LAST );
        return $key;
    }

    public static function get_user_by_api_key( string $key ): WP_User|false {
        if ( empty( $key ) ) return false;

        $users = get_users( [
            'meta_key'   => self::USER_META_API_KEY,
            'meta_value' => $key,
            'number'     => 1,
        ] );

        return ! empty( $users ) ? $users[0] : false;
    }

    public static function touch_api_key( int $user_id ): void {
        update_user_meta( $user_id, self::USER_META_API_KEY_LAST, current_time( 'mysql' ) );
    }

    public static function get_users_with_allowed_roles(): array {
        $roles = self::get_allowed_roles();
        if ( empty( $roles ) ) return [];

        return get_users( [ 'role__in' => $roles, 'orderby' => 'display_name' ] );
    }

    public static function get_allowed_roles(): array {
        $roles = self::get( self::KEY_ALLOWED_ROLES, [ 'administrator' ] );
        return is_array( $roles ) ? $roles : [ 'administrator' ];
    }

    public static function get_rest_route(): string {
        $custom = trim( (string) self::get( self::KEY_CUSTOM_ENDPOINT, '' ) );
        if ( $custom ) {
            // Strip leading/trailing slashes, keep only the path segment
            return sanitize_title( trim( $custom, '/' ) );
        }
        return 'generate-post';
    }

    public static function get_rest_namespace(): string {
        return 'ille/v2';
    }

    public static function get_endpoint_url(): string {
        return rest_url( self::get_rest_namespace() . '/' . self::get_rest_route() );
    }

    public static function get_schedules(): array {
        $schedules = self::get( self::KEY_SCHEDULES, [] );
        if ( ! is_array( $schedules ) ) return [];
        return array_slice( $schedules, 0, self::MAX_SCHEDULES );
    }

    public static function get_allowed_params(): array {
        $params = self::get( self::KEY_ALLOWED_PARAMS, [ 'topic', 'publish', 'focus_keyword', 'featured_image' ] );
        return is_array( $params ) ? $params : [];
    }

    public static function get_post_prompt(): string {
        return (string) self::get( self::KEY_POST_PROMPT, self::default_post_prompt() );
    }

    public static function get_image_prompt(): string {
        return (string) self::get( self::KEY_IMAGE_PROMPT, self::default_image_prompt() );
    }

    public static function default_post_prompt(): string {
        return 'You are an expert SEO content writer for ille.com.ng, a Nigerian lifestyle and business blog. Write a fully formatted, engaging blog post about: {topic}. Include a compelling introduction, 3-4 subheadings with substantive content, and a conclusion with a call to action. Minimum 700 words. Return clean HTML using only <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em> tags.';
    }

    public static function default_image_prompt(): string {
        return 'A photorealistic, high-quality image representing: {title}. Professional photography style, natural lighting, no text overlays, no watermarks. Suitable for a Nigerian lifestyle and business blog.';
    }

    public static function get_default_image_id(): int {
        return (int) self::get( self::KEY_DEFAULT_IMAGE, 0 );
    }

    /**
     * Returns the model that will actually be used at runtime.
     * Respects user preference but falls back to any configured model.
     */
    public static function resolve_active_model(): array|WP_Error {
        $preferred = (string) self::get( self::KEY_ACTIVE_MODEL, 'gemini-2.0-flash' );
        $models    = self::get_available_models();

        // Build resolution order: preferred first, then the rest
        $order = array_keys( $models );
        usort( $order, fn( $a ) => $a === $preferred ? -1 : 1 );

        foreach ( $order as $id ) {
            $key = trim( (string) self::get( $models[ $id ]['key_opt'], '' ) );
            if ( $key ) {
                return [ 'id' => $id, 'key' => $key, 'model' => $models[ $id ] ];
            }
        }

        return new WP_Error(
            'no_model_configured',
            'No AI model API key is configured. Go to Settings → AI Models and add a key.'
        );
    }

    // -------------------------------------------------------------------------
    // Image model helpers
    // -------------------------------------------------------------------------

    public static function get_image_model(): string {
        return (string) self::get( self::KEY_IMAGE_MODEL, 'auto' );
    }

    public static function get_available_image_models(): array {
        return [
            'auto'           => 'Auto (match text model preference)',
            'pollinations'   => 'Pollinations.ai',
            'dall-e-3'       => 'DALL·E 3 (uses OpenAI key)',
            'grok-aurora'    => 'Grok Aurora (uses xAI key)',
            'gemini-imagen'  => 'Gemini Imagen (uses Google key)',
        ];
    }

    public static function resolve_image_model(): array {
        $pref = self::get_image_model();

        if ( $pref === 'auto' ) {
            $text = self::resolve_active_model();
            $map  = [
                'gpt-4o-mini'      => 'dall-e-3',
                'grok-3-mini'      => 'grok-aurora',
                'gemini-2.0-flash' => 'gemini-imagen',
            ];
            $pref = ( ! is_wp_error( $text ) && isset( $map[ $text['id'] ] ) )
                ? $map[ $text['id'] ]
                : 'pollinations';
        }

        $key_map = [
            'dall-e-3'      => self::KEY_OPENAI_KEY,
            'grok-aurora'   => self::KEY_XAI_KEY,
            'gemini-imagen' => self::KEY_GEMINI_KEY,
            'pollinations'  => self::KEY_POLLINATIONS_KEY,
        ];

        $key = trim( (string) self::get( $key_map[ $pref ] ?? self::KEY_POLLINATIONS_KEY, '' ) );

        return [ 'id' => $pref, 'key' => $key ];
    }

    public static function get_available_models(): array {
        return [
            'gemini-2.0-flash' => [
                'label'    => 'Google Gemini 2.0 Flash',
                'key_opt'  => self::KEY_GEMINI_KEY,
                'free'     => true,
                'note'     => 'Free tier: 1,500 req/day — <a href="https://aistudio.google.com/app/apikey" target="_blank">Get key</a>',
            ],
            'gpt-4o-mini' => [
                'label'    => 'OpenAI GPT-4o Mini',
                'key_opt'  => self::KEY_OPENAI_KEY,
                'free'     => false,
                'note'     => 'Paid — <a href="https://platform.openai.com/api-keys" target="_blank">Get key</a>',
            ],
            'grok-3-mini' => [
                'label'    => 'xAI Grok 3 Mini',
                'key_opt'  => self::KEY_XAI_KEY,
                'free'     => true,
                'note'     => 'Free credits — <a href="https://console.x.ai/" target="_blank">Get key</a>',
            ],
        ];
    }
}
