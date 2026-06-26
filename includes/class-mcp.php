<?php
/**
 * MCP (Model Context Protocol) server built directly into WordPress.
 *
 * Endpoint: POST /wp-json/ille/v2/mcp
 * Transport: MCP Streamable HTTP (spec 2025-03-26) — JSON request/response.
 * Auth: X-API-Key header or ?api_key= query param (same as the generate endpoint).
 *
 * No Node.js or npm required — users point their MCP client at the WordPress URL.
 * Claude Desktop config:
 *   {
 *     "mcpServers": {
 *       "ille-pg": {
 *         "type": "http",
 *         "url": "https://yoursite.com/wp-json/ille/v2/mcp",
 *         "headers": { "X-API-Key": "your-key-here" }
 *       }
 *     }
 *   }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_MCP {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( ILLE_PG_Settings::get_rest_namespace(), '/mcp', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_request' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    // =========================================================================
    // Auth — identical logic to the main generate endpoint
    // =========================================================================

    public function check_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            foreach ( ILLE_PG_Settings::get_allowed_roles() as $role ) {
                if ( in_array( $role, (array) $user->roles, true ) ) {
                    return true;
                }
            }
        }

        $provided = $request->get_header( 'X-API-Key' ) ?: $request->get_param( 'api_key' );

        if ( ! $provided ) {
            return new WP_Error( 'unauthorized', 'Missing X-API-Key.', [ 'status' => 401 ] );
        }

        $user = ILLE_PG_Settings::get_user_by_api_key( (string) $provided );

        if ( ! $user ) {
            return new WP_Error( 'unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
        }

        $allowed = false;
        foreach ( ILLE_PG_Settings::get_allowed_roles() as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                $allowed = true;
                break;
            }
        }

        if ( ! $allowed ) {
            return new WP_Error( 'forbidden', 'Your role is not permitted.', [ 'status' => 403 ] );
        }

        $request->set_param( '_mcp_user_id', $user->ID );
        ILLE_PG_Settings::touch_api_key( $user->ID );

        return true;
    }

    // =========================================================================
    // JSON-RPC dispatcher
    // =========================================================================

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        // GET is used by some clients to establish the SSE stream for server
        // notifications. We don't push notifications, so return capabilities.
        if ( $request->get_method() === 'GET' ) {
            return new WP_REST_Response( [
                'jsonrpc' => '2.0',
                'result'  => [ 'capabilities' => [ 'tools' => new stdClass() ] ],
            ], 200 );
        }

        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return $this->rpc_error( null, -32700, 'Parse error: empty or invalid JSON body.' );
        }

        // Notification — no id, no response expected
        if ( ! array_key_exists( 'id', $body ) ) {
            return new WP_REST_Response( null, 204 );
        }

        $id     = $body['id'];
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        $actor_id = is_user_logged_in()
            ? get_current_user_id()
            : (int) $request->get_param( '_mcp_user_id' );

        switch ( $method ) {

            case 'initialize':
                return $this->rpc_ok( $id, [
                    'protocolVersion' => '2025-03-26',
                    'capabilities'    => [ 'tools' => new stdClass() ],
                    'serverInfo'      => [
                        'name'    => 'ille-pg',
                        'version' => ILLE_PG_VERSION,
                    ],
                ] );

            case 'tools/list':
                return $this->rpc_ok( $id, [ 'tools' => $this->tool_definitions() ] );

            case 'tools/call':
                $name      = $params['name']      ?? '';
                $arguments = $params['arguments'] ?? [];
                return $this->rpc_ok( $id, $this->dispatch_tool( $name, $arguments, $actor_id ) );

            default:
                return $this->rpc_error( $id, -32601, "Method not found: {$method}" );
        }
    }

    // =========================================================================
    // Tool definitions (JSON Schema)
    // =========================================================================

    private function tool_definitions(): array {
        return [

            // ─── Content ───────────────────────────────────────────────────
            [
                'name'        => 'generate_post',
                'description' => 'Trigger the full AI post generation pipeline. The AI writes the title, content, excerpt, and SEO metadata. Optionally generates a featured image. Returns the new post ID and URL.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'topic'          => [ 'type' => 'string',  'description' => 'Topic or title hint for the AI (e.g. "saving money in Nigeria")' ],
                        'focus_keyword'  => [ 'type' => 'string',  'description' => 'Target SEO keyword — 1–2 words recommended' ],
                        'publish'        => [ 'type' => 'boolean', 'description' => 'true = publish immediately; false = save as draft', 'default' => true ],
                        'featured_image' => [ 'type' => 'boolean', 'description' => 'Generate and attach an AI featured image', 'default' => true ],
                    ],
                ],
            ],

            [
                'name'        => 'create_post',
                'description' => 'Publish pre-written content directly to WordPress — use this when you have already drafted the post in the LLM UI. Writes Yoast SEO metadata. Optionally triggers AI featured image generation.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'title', 'content' ],
                    'properties' => [
                        'title'          => [ 'type' => 'string',  'description' => 'Post title' ],
                        'content'        => [ 'type' => 'string',  'description' => 'Post body as HTML (use <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>)' ],
                        'focus_keyword'  => [ 'type' => 'string',  'description' => 'SEO focus keyword for Yoast meta' ],
                        'excerpt'        => [ 'type' => 'string',  'description' => 'Short excerpt / meta description (1–2 sentences)' ],
                        'publish'        => [ 'type' => 'boolean', 'description' => 'true = publish immediately; false = save as draft', 'default' => true ],
                        'featured_image' => [ 'type' => 'boolean', 'description' => 'Trigger async AI featured image generation', 'default' => true ],
                    ],
                ],
            ],

            // ─── Endpoint ──────────────────────────────────────────────────
            [
                'name'        => 'get_endpoint_info',
                'description' => 'Get the live generate-post endpoint URL, namespace, route slug, and allowed request parameters.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            // ─── Auth & API Keys ───────────────────────────────────────────
            [
                'name'        => 'search_api_users',
                'description' => 'Search the paginated list of WordPress users with their API key status. Use this first to look up a user_id before calling refresh_api_key or revoke_api_key. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search' => [ 'type' => 'string',  'description' => 'Filter by display name, username, or email' ],
                        'page'   => [ 'type' => 'integer', 'description' => 'Page number (20 users per page)', 'default' => 1, 'minimum' => 1 ],
                    ],
                ],
            ],

            [
                'name'        => 'refresh_api_key',
                'description' => 'Regenerate the API key for a user — old key is immediately invalidated. Returns the new key. Use search_api_users first to find the user_id. Admins can refresh any key; non-admins can only refresh their own.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'user_id' ],
                    'properties' => [
                        'user_id' => [ 'type' => 'integer', 'description' => 'WordPress user ID — use search_api_users to look this up', 'minimum' => 1 ],
                    ],
                ],
            ],

            [
                'name'        => 'revoke_api_key',
                'description' => 'Revoke (delete) the API key for a user. Use search_api_users first to find the user_id. Admins can revoke any key; non-admins can only revoke their own.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'user_id' ],
                    'properties' => [
                        'user_id' => [ 'type' => 'integer', 'description' => 'WordPress user ID — use search_api_users to look this up', 'minimum' => 1 ],
                    ],
                ],
            ],

            [
                'name'        => 'get_allowed_roles',
                'description' => 'Get the WordPress roles currently allowed to use the post generator endpoint.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            [
                'name'        => 'set_allowed_roles',
                'description' => 'Update the WordPress roles allowed to use the post generator endpoint. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'roles' ],
                    'properties' => [
                        'roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Role slugs to allow (e.g. ["administrator", "editor"])' ],
                    ],
                ],
            ],

            // ─── Models ────────────────────────────────────────────────────
            [
                'name'        => 'get_models',
                'description' => 'Get the active text model, active image model, and the full list of available models — each with model_id, label, tier (free/paid), and key_configured status. Use model_id values when calling set_text_model or set_image_model.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            [
                'name'        => 'set_text_model',
                'description' => 'Change the active AI text model. Requires admin key. Use get_models first to see available model_ids.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'model_id' ],
                    'properties' => [
                        'model_id' => [ 'type' => 'string', 'description' => 'Model ID from get_models (e.g. "gemini-2.0-flash", "gpt-4o-mini", "grok-3-mini")' ],
                    ],
                ],
            ],

            [
                'name'        => 'set_image_model',
                'description' => 'Change the active AI image generation model. Requires admin key. Use get_models first to see available model_ids.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'model_id' ],
                    'properties' => [
                        'model_id' => [ 'type' => 'string', 'description' => 'Image model ID (e.g. "auto", "pollinations", "dall-e-3", "grok-aurora", "gemini-imagen")' ],
                    ],
                ],
            ],

            // ─── Prompts ───────────────────────────────────────────────────
            [
                'name'        => 'get_prompts',
                'description' => 'Get the current post generation prompt and image generation prompt.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            [
                'name'        => 'update_prompt',
                'description' => 'Update the post or image generation prompt. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'type', 'content' ],
                    'properties' => [
                        'type'    => [ 'type' => 'string', 'enum' => [ 'post', 'image' ], 'description' => '"post" for the text generation prompt; "image" for the image prompt' ],
                        'content' => [ 'type' => 'string', 'description' => 'New prompt text. Use {topic} in post prompts; {title} in image prompts.' ],
                    ],
                ],
            ],

            // ─── Schedules ─────────────────────────────────────────────────
            [
                'name'        => 'get_schedules',
                'description' => 'Get all 5 schedule slots — enabled state, days, time, topic, post_status, and next scheduled run time. Requires admin key.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            [
                'name'        => 'update_schedule',
                'description' => 'Update a single schedule slot (index 0–4). Only provided fields are changed. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'index' ],
                    'properties' => [
                        'index'       => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'description' => 'Schedule slot (0–4)' ],
                        'enabled'     => [ 'type' => 'boolean', 'description' => 'Enable or disable this schedule' ],
                        'days'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Days to run: short day names (e.g. ["mon","wed","fri"])' ],
                        'time'        => [ 'type' => 'string', 'description' => 'Time in HH:MM 24-hour format (site timezone)' ],
                        'topic'       => [ 'type' => 'string', 'description' => 'Topic hint for the AI post generator' ],
                        'post_status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'description' => 'Publish immediately or save as draft' ],
                    ],
                ],
            ],

            // ─── Activity Log ──────────────────────────────────────────────
            [
                'name'        => 'get_activity_log',
                'description' => 'Read the JSONL audit log. Filter by event type shortcut or free-text search. Event shortcuts: "posts" (post_created), "errors" (endpoint_error), "auth" (auth attempts), "settings" (settings_changed), "keys" (api_key_action). Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => [ 'type' => 'integer', 'description' => 'Max entries to return (default 50, max 500)', 'default' => 50, 'minimum' => 1, 'maximum' => 500 ],
                        'event'  => [ 'type' => 'string',  'description' => 'Event type filter — shortcut name or raw event name' ],
                        'search' => [ 'type' => 'string',  'description' => 'Free-text search scanned across all fields of each entry' ],
                    ],
                ],
            ],

            [
                'name'        => 'get_user_audit',
                'description' => 'Get all activity log entries for a specific user — posts created, settings changed, auth events, API key actions. Provide user_id or username. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'user_id'  => [ 'type' => 'integer', 'description' => 'WordPress user ID — use search_api_users to find it', 'minimum' => 1 ],
                        'username' => [ 'type' => 'string',  'description' => 'Display name or username to match (case-insensitive partial match)' ],
                    ],
                ],
            ],

            [
                'name'        => 'export_activity_log',
                'description' => 'Export the full activity log as JSONL text (one JSON object per line, oldest first). Requires admin key.',
                'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ],
            ],

            [
                'name'        => 'truncate_activity_log',
                'description' => 'Keep only the most recent N entries; older entries are permanently removed. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'keep' => [ 'type' => 'integer', 'description' => 'Number of entries to keep (default 100)', 'default' => 100, 'minimum' => 1 ],
                    ],
                ],
            ],

            [
                'name'        => 'delete_activity_log',
                'description' => 'Permanently delete the entire activity log file. Cannot be undone. You must pass confirm: true. Requires admin key.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => [ 'confirm' ],
                    'properties' => [
                        'confirm' => [ 'type' => 'boolean', 'enum' => [ true ], 'description' => 'Must be exactly true to confirm deletion' ],
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // Tool dispatcher
    // =========================================================================

    private function dispatch_tool( string $name, array $args, int $actor_id ): array {
        switch ( $name ) {

            // ─── Content ───────────────────────────────────────────────────
            case 'generate_post':      return $this->tool_generate_post( $args, $actor_id );
            case 'create_post':        return $this->tool_create_post( $args, $actor_id );

            // ─── Endpoint ──────────────────────────────────────────────────
            case 'get_endpoint_info':  return $this->tool_get_endpoint_info();

            // ─── Auth & API Keys ───────────────────────────────────────────
            case 'search_api_users':   return $this->tool_search_api_users( $args, $actor_id );
            case 'refresh_api_key':    return $this->tool_refresh_api_key( $args, $actor_id );
            case 'revoke_api_key':     return $this->tool_revoke_api_key( $args, $actor_id );
            case 'get_allowed_roles':  return $this->tool_get_allowed_roles();
            case 'set_allowed_roles':  return $this->tool_set_allowed_roles( $args, $actor_id );

            // ─── Models ────────────────────────────────────────────────────
            case 'get_models':         return $this->tool_get_models();
            case 'set_text_model':     return $this->tool_set_text_model( $args, $actor_id );
            case 'set_image_model':    return $this->tool_set_image_model( $args, $actor_id );

            // ─── Prompts ───────────────────────────────────────────────────
            case 'get_prompts':        return $this->tool_get_prompts();
            case 'update_prompt':      return $this->tool_update_prompt( $args, $actor_id );

            // ─── Schedules ─────────────────────────────────────────────────
            case 'get_schedules':      return $this->tool_get_schedules( $actor_id );
            case 'update_schedule':    return $this->tool_update_schedule( $args, $actor_id );

            // ─── Activity Log ──────────────────────────────────────────────
            case 'get_activity_log':   return $this->tool_get_activity_log( $args, $actor_id );
            case 'get_user_audit':     return $this->tool_get_user_audit( $args, $actor_id );
            case 'export_activity_log':   return $this->tool_export_activity_log( $actor_id );
            case 'truncate_activity_log': return $this->tool_truncate_activity_log( $args, $actor_id );
            case 'delete_activity_log':   return $this->tool_delete_activity_log( $args, $actor_id );

            default:
                return $this->err( "Unknown tool: {$name}" );
        }
    }

    // =========================================================================
    // Content tools
    // =========================================================================

    private function tool_generate_post( array $args, int $actor_id ): array {
        $post_id = ILLE_PG_Post_Creator::create( [
            'topic'          => sanitize_text_field( $args['topic']         ?? '' ),
            'focus_keyword'  => sanitize_text_field( $args['focus_keyword'] ?? '' ),
            'featured_image' => filter_var( $args['featured_image'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'post_status'    => filter_var( $args['publish'] ?? true, FILTER_VALIDATE_BOOLEAN ) ? 'publish' : 'draft',
            'author_id'      => $actor_id,
            'trigger'        => ILLE_PG_Logger::TRIGGER_ENDPOINT,
        ] );

        if ( is_wp_error( $post_id ) ) {
            return $this->err( $post_id->get_error_message() );
        }

        return $this->ok( [
            'post_id'     => $post_id,
            'post_url'    => get_permalink( $post_id ),
            'post_status' => get_post_status( $post_id ),
            'title'       => get_the_title( $post_id ),
        ] );
    }

    private function tool_create_post( array $args, int $actor_id ): array {
        $post_id = ILLE_PG_Post_Creator::create_from_content( [
            'title'          => sanitize_text_field( $args['title']         ?? '' ),
            'content'        => wp_kses_post( $args['content']              ?? '' ),
            'excerpt'        => sanitize_text_field( $args['excerpt']       ?? '' ),
            'focus_keyword'  => sanitize_text_field( $args['focus_keyword'] ?? '' ),
            'featured_image' => filter_var( $args['featured_image'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'post_status'    => filter_var( $args['publish'] ?? true, FILTER_VALIDATE_BOOLEAN ) ? 'publish' : 'draft',
            'author_id'      => $actor_id,
            'trigger'        => ILLE_PG_Logger::TRIGGER_ENDPOINT,
        ] );

        if ( is_wp_error( $post_id ) ) {
            return $this->err( $post_id->get_error_message() );
        }

        return $this->ok( [
            'post_id'     => $post_id,
            'post_url'    => get_permalink( $post_id ),
            'post_status' => get_post_status( $post_id ),
            'title'       => get_the_title( $post_id ),
        ] );
    }

    // =========================================================================
    // Endpoint info
    // =========================================================================

    private function tool_get_endpoint_info(): array {
        return $this->ok( [
            'namespace'      => ILLE_PG_Settings::get_rest_namespace(),
            'route'          => ILLE_PG_Settings::get_rest_route(),
            'endpoint_url'   => ILLE_PG_Settings::get_endpoint_url(),
            'mcp_url'        => rest_url( ILLE_PG_Settings::get_rest_namespace() . '/mcp' ),
            'allowed_params' => ILLE_PG_Settings::get_allowed_params(),
        ] );
    }

    // =========================================================================
    // Auth & API Keys
    // =========================================================================

    private function tool_search_api_users( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $search   = sanitize_text_field( $args['search'] ?? '' );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = 20;

        $query_args = [
            'number'      => $per_page,
            'offset'      => ( $page - 1 ) * $per_page,
            'orderby'     => 'display_name',
            'order'       => 'ASC',
            'count_total' => true,
            'meta_query'  => [
                [
                    'key'     => ILLE_PG_Settings::USER_META_API_KEY,
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ];

        if ( $search ) {
            $query_args['search']         = '*' . $search . '*';
            $query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }

        $q     = new WP_User_Query( $query_args );
        $total = (int) $q->get_total();
        $pages = max( 1, (int) ceil( $total / $per_page ) );

        $rows = [];
        foreach ( $q->get_results() as $user ) {
            $key    = ILLE_PG_Settings::get_user_api_key( $user->ID );
            $last   = get_user_meta( $user->ID, ILLE_PG_Settings::USER_META_API_KEY_LAST, true );
            $rows[] = [
                'user_id'    => $user->ID,
                'name'       => $user->display_name,
                'username'   => $user->user_login,
                'email'      => $user->user_email,
                'roles'      => array_values( (array) $user->roles ),
                'key_exists' => ! empty( $key ),
                'key'        => $key ?: null,
                'last_used'  => $last ?: null,
            ];
        }

        return $this->ok( [ 'users' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages ] );
    }

    private function tool_refresh_api_key( array $args, int $actor_id ): array {
        $target_id = (int) ( $args['user_id'] ?? 0 );

        if ( $actor_id !== $target_id && ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'You can only manage your own API key.' );
        }

        $target = get_userdata( $target_id );
        if ( ! $target ) return $this->err( 'User not found.' );

        $new_key = ILLE_PG_Settings::generate_user_api_key( $target_id );

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_API_KEY_ACTION, [
            'action'      => 'regenerated',
            'target_uid'  => $target_id,
            'target_name' => $target->display_name,
        ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );

        return $this->ok( [ 'user_id' => $target_id, 'key' => $new_key ] );
    }

    private function tool_revoke_api_key( array $args, int $actor_id ): array {
        $target_id = (int) ( $args['user_id'] ?? 0 );

        if ( $actor_id !== $target_id && ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'You can only manage your own API key.' );
        }

        $target = get_userdata( $target_id );
        if ( ! $target ) return $this->err( 'User not found.' );

        if ( ! ILLE_PG_Settings::get_user_api_key( $target_id ) ) {
            return $this->err( "User \"{$target->display_name}\" has no API key to revoke." );
        }

        ILLE_PG_Settings::revoke_user_api_key( $target_id );

        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_API_KEY_ACTION, [
            'action'      => 'revoked',
            'target_uid'  => $target_id,
            'target_name' => $target->display_name,
        ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );

        return $this->ok( [ 'user_id' => $target_id, 'revoked' => true ] );
    }

    private function tool_get_allowed_roles(): array {
        $allowed   = ILLE_PG_Settings::get_allowed_roles();
        $all_roles = wp_roles()->get_names();

        $available = [];
        foreach ( $all_roles as $slug => $label ) {
            $available[] = [
                'role'       => $slug,
                'label'      => translate_user_role( $label ),
                'is_allowed' => in_array( $slug, $allowed, true ),
            ];
        }

        return $this->ok( [
            'allowed_roles'   => $allowed,
            'available_roles' => $available,
        ] );
    }

    private function tool_set_allowed_roles( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $roles = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $args['roles'] ?? [] ) ) ) );
        ILLE_PG_Logger::log_settings_change( ILLE_PG_Settings::KEY_ALLOWED_ROLES, ILLE_PG_Settings::get_allowed_roles(), $roles, ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );
        ILLE_PG_Settings::set( ILLE_PG_Settings::KEY_ALLOWED_ROLES, $roles );

        return $this->ok( [ 'allowed_roles' => $roles ] );
    }

    // =========================================================================
    // Models
    // =========================================================================

    private function tool_get_models(): array {
        $text_models  = ILLE_PG_Settings::get_available_models();
        $image_models = ILLE_PG_Settings::get_available_image_models();
        $active_text  = (string) ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_ACTIVE_MODEL, 'gemini-2.0-flash' );
        $active_image = ILLE_PG_Settings::get_image_model();

        $text_list = [];
        foreach ( $text_models as $id => $m ) {
            $text_list[] = [
                'model_id'       => $id,
                'label'          => $m['label'],
                'tier'           => $m['free'] ? 'free' : 'paid',
                'key_configured' => ! empty( trim( (string) ILLE_PG_Settings::get( $m['key_opt'], '' ) ) ),
                'is_active'      => $id === $active_text,
            ];
        }

        $image_list = [];
        foreach ( $image_models as $id => $label ) {
            $image_list[] = [
                'model_id'  => $id,
                'label'     => $label,
                'is_active' => $id === $active_image,
            ];
        }

        return $this->ok( [
            'active_text_model'      => $active_text,
            'active_image_model'     => $active_image,
            'available_text_models'  => $text_list,
            'available_image_models' => $image_list,
        ] );
    }

    private function tool_set_text_model( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $model_id = sanitize_text_field( $args['model_id'] ?? '' );
        $valid    = array_keys( ILLE_PG_Settings::get_available_models() );

        if ( ! in_array( $model_id, $valid, true ) ) {
            return $this->err( "Unknown model_id: {$model_id}. Call get_models to see valid IDs." );
        }

        ILLE_PG_Logger::log_settings_change( ILLE_PG_Settings::KEY_ACTIVE_MODEL, ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_ACTIVE_MODEL ), $model_id, ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );
        ILLE_PG_Settings::set( ILLE_PG_Settings::KEY_ACTIVE_MODEL, $model_id );

        return $this->ok( [ 'active_text_model' => $model_id ] );
    }

    private function tool_set_image_model( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $model_id = sanitize_text_field( $args['model_id'] ?? '' );
        $valid    = array_keys( ILLE_PG_Settings::get_available_image_models() );

        if ( ! in_array( $model_id, $valid, true ) ) {
            return $this->err( "Unknown image model_id: {$model_id}. Call get_models to see valid IDs." );
        }

        ILLE_PG_Logger::log_settings_change( ILLE_PG_Settings::KEY_IMAGE_MODEL, ILLE_PG_Settings::get_image_model(), $model_id, ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );
        ILLE_PG_Settings::set( ILLE_PG_Settings::KEY_IMAGE_MODEL, $model_id );

        return $this->ok( [ 'active_image_model' => $model_id ] );
    }

    // =========================================================================
    // Prompts
    // =========================================================================

    private function tool_get_prompts(): array {
        return $this->ok( [
            'post_prompt'  => ILLE_PG_Settings::get_post_prompt(),
            'image_prompt' => ILLE_PG_Settings::get_image_prompt(),
        ] );
    }

    private function tool_update_prompt( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $type    = $args['type']    ?? '';
        $content = $args['content'] ?? '';

        if ( ! in_array( $type, [ 'post', 'image' ], true ) ) {
            return $this->err( 'type must be "post" or "image".' );
        }

        $opt_key = $type === 'post' ? ILLE_PG_Settings::KEY_POST_PROMPT : ILLE_PG_Settings::KEY_IMAGE_PROMPT;
        $value   = wp_kses_post( $content );

        ILLE_PG_Logger::log_settings_change( $opt_key, ILLE_PG_Settings::get( $opt_key ), $value, ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );
        ILLE_PG_Settings::set( $opt_key, $value );

        return $this->ok( [ 'type' => $type, 'updated' => true ] );
    }

    // =========================================================================
    // Schedules
    // =========================================================================

    private function tool_get_schedules( int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $schedules = ILLE_PG_Settings::get_schedules();
        $next_runs = ILLE_PG_Scheduler::get_next_runs();

        $result = [];
        for ( $i = 0; $i < ILLE_PG_Settings::MAX_SCHEDULES; $i++ ) {
            $s             = $schedules[ $i ] ?? [];
            $s['index']    = $i;
            $s['next_run'] = $next_runs[ $i ] ?? null;
            $result[]      = $s;
        }

        return $this->ok( [ 'schedules' => $result ] );
    }

    private function tool_update_schedule( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $index = (int) ( $args['index'] ?? -1 );
        if ( $index < 0 || $index >= ILLE_PG_Settings::MAX_SCHEDULES ) {
            return $this->err( 'index must be 0–' . ( ILLE_PG_Settings::MAX_SCHEDULES - 1 ) . '.' );
        }

        $schedules = ILLE_PG_Settings::get_schedules();
        while ( count( $schedules ) <= $index ) $schedules[] = [];
        $slot = $schedules[ $index ] ?? [];

        if ( array_key_exists( 'enabled', $args ) )     $slot['enabled']     = (bool) $args['enabled'];
        if ( array_key_exists( 'days', $args ) )        $slot['days']        = array_map( 'sanitize_text_field', (array) $args['days'] );
        if ( array_key_exists( 'time', $args ) )        $slot['time']        = sanitize_text_field( $args['time'] );
        if ( array_key_exists( 'topic', $args ) )       $slot['topic']       = sanitize_text_field( $args['topic'] );
        if ( array_key_exists( 'post_status', $args ) ) {
            $slot['post_status'] = in_array( $args['post_status'], [ 'publish', 'draft' ], true )
                ? $args['post_status'] : 'publish';
        }

        $schedules[ $index ] = $slot;
        ILLE_PG_Settings::set( ILLE_PG_Settings::KEY_SCHEDULES, array_slice( $schedules, 0, ILLE_PG_Settings::MAX_SCHEDULES ) );
        ILLE_PG_Scheduler::sync_schedules();

        return $this->ok( [ 'index' => $index, 'schedule' => $slot ] );
    }

    // =========================================================================
    // Activity Log
    // =========================================================================

    private function tool_get_activity_log( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $limit  = min( 500, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
        $event  = sanitize_text_field( $args['event']  ?? '' );
        $search = sanitize_text_field( $args['search'] ?? '' );

        $shortcuts = [
            'posts'    => [ ILLE_PG_Logger::EVENT_POST_CREATED ],
            'errors'   => [ ILLE_PG_Logger::EVENT_ENDPOINT_ERROR ],
            'auth'     => [ ILLE_PG_Logger::EVENT_ENDPOINT_ERROR, ILLE_PG_Logger::EVENT_ENDPOINT_TESTED ],
            'settings' => [ ILLE_PG_Logger::EVENT_SETTINGS_CHANGED ],
            'keys'     => [ ILLE_PG_Logger::EVENT_API_KEY_ACTION ],
        ];

        $entries = ILLE_PG_Logger::get_entries( 10000 );

        if ( $event ) {
            $filter_events = $shortcuts[ $event ] ?? [ $event ];
            $entries = array_values( array_filter( $entries, fn( $e ) => in_array( $e['event'] ?? '', $filter_events, true ) ) );
        }

        if ( $search ) {
            $entries = array_values( array_filter( $entries, fn( $e ) => false !== stripos( wp_json_encode( $e ), $search ) ) );
        }

        $entries = array_slice( $entries, 0, $limit );

        return $this->ok( [ 'count' => count( $entries ), 'entries' => $entries ] );
    }

    private function tool_get_user_audit( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
        $username = sanitize_text_field( $args['username'] ?? '' );

        if ( ! $user_id && ! $username ) {
            return $this->err( 'Provide either user_id or username.' );
        }

        $all     = ILLE_PG_Logger::get_entries( 10000 );
        $entries = array_values( array_filter( $all, function ( $e ) use ( $user_id, $username ) {
            if ( $user_id  && (int) ( $e['uid'] ?? 0 ) === $user_id ) return true;
            if ( $username && isset( $e['uname'] ) && false !== stripos( $e['uname'], $username ) ) return true;
            return false;
        } ) );

        return $this->ok( [ 'count' => count( $entries ), 'entries' => $entries ] );
    }

    private function tool_export_activity_log( int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $entries = array_reverse( ILLE_PG_Logger::get_entries( 10000 ) ); // oldest first
        $jsonl   = implode( "\n", array_map( 'wp_json_encode', $entries ) );

        return [ 'content' => [ [ 'type' => 'text', 'text' => $jsonl ] ] ];
    }

    private function tool_truncate_activity_log( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        $keep = max( 1, (int) ( $args['keep'] ?? 100 ) );
        ILLE_PG_Logger::truncate_to_last( $keep );
        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_LOG_TRUNCATED, [ 'kept' => $keep ], ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );

        return $this->ok( [ 'kept' => $keep ] );
    }

    private function tool_delete_activity_log( array $args, int $actor_id ): array {
        if ( ! user_can( $actor_id, 'manage_options' ) ) {
            return $this->err( 'Administrator access required.' );
        }

        if ( empty( $args['confirm'] ) ) {
            return $this->err( 'Pass confirm: true to confirm deletion.' );
        }

        ILLE_PG_Logger::delete();
        ILLE_PG_Logger::log( ILLE_PG_Logger::EVENT_LOG_DELETED, [], ILLE_PG_Logger::TRIGGER_ENDPOINT, $actor_id );

        return $this->ok( [ 'deleted' => true ] );
    }

    // =========================================================================
    // Response helpers
    // =========================================================================

    private function ok( array $data ): array {
        return [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ] ] ];
    }

    private function err( string $message ): array {
        return [ 'content' => [ [ 'type' => 'text', 'text' => $message ] ], 'isError' => true ];
    }

    private function rpc_ok( mixed $id, array $result ): WP_REST_Response {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );
    }

    private function rpc_error( mixed $id, int $code, string $message ): WP_REST_Response {
        return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 );
    }
}
