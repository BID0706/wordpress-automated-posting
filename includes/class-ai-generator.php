<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_AI_Generator {

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    public static function generate( array $args ): array|WP_Error {
        $model = ILLE_PG_Settings::resolve_active_model();
        if ( is_wp_error( $model ) ) {
            return $model;
        }

        $prompt = self::build_prompt(
            $args['topic']         ?? '',
            $args['focus_keyword'] ?? '',
            $args
        );

        $raw = self::call_text_api( $model['id'], $model['key'], $prompt );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        return self::parse_ai_response( $raw );
    }

    // -------------------------------------------------------------------------
    // Prompt building
    // -------------------------------------------------------------------------

    private static function build_prompt( string $topic, string $keyword, array $args = [] ): string {
        if ( ! $topic ) {
            $topic = 'a trending topic in Nigerian lifestyle and business';
        }
        if ( ! $keyword ) {
            $keyword = $topic;
        }

        $base = ILLE_PG_Settings::get_post_prompt();
        $base = str_replace( [ '{topic}', '{keyword}' ], [ $topic, $keyword ], $base );

        // Existing site categories
        $categories = get_categories( [ 'hide_empty' => false ] );
        $cat_names  = implode( ', ', wp_list_pluck( $categories, 'name' ) );
        if ( ! $cat_names ) {
            $cat_names = 'Uncategorized';
        }

        // Fetch recent posts — one query serves three purposes:
        // internal links (first 10), covered titles, and used keyphrases
        $n            = max( 10, ILLE_PG_Settings::get_covered_topics_count() );
        $recent_posts = get_posts( [
            'numberposts' => $n,
            'post_status' => 'publish',
            'fields'      => 'all',
        ] );

        // Internal linking context (first 10)
        $link_posts        = array_slice( $recent_posts, 0, 10 );
        $internal_link_ctx = '';
        if ( ! empty( $link_posts ) ) {
            $lines = [];
            foreach ( $link_posts as $p ) {
                $lines[] = '- ' . get_the_title( $p ) . ': ' . get_permalink( $p );
            }
            $internal_link_ctx = "\n\nRecent posts available for internal linking:\n" . implode( "\n", $lines );
        }

        // Covered titles — all N posts
        $covered_ctx = '';
        if ( ! empty( $recent_posts ) ) {
            $titles = array_map( fn( $p ) => '- ' . get_the_title( $p ), $recent_posts );
            $covered_ctx = "\n\nTopics ALREADY PUBLISHED on this site — do NOT write about the same topic or a close variation of any of these:\n"
                         . implode( "\n", $titles );
        }

        // Used keyphrases — extracted from Yoast meta
        $used_keywords_ctx = '';
        $used_kws = [];
        foreach ( $recent_posts as $p ) {
            $kw = get_post_meta( $p->ID, '_yoast_wpseo_focuskw', true );
            if ( $kw ) {
                $used_kws[] = '- ' . $kw;
            }
        }
        if ( ! empty( $used_kws ) ) {
            $used_keywords_ctx = "\n\nFocus keyphrases already used on this site — choose a DIFFERENT keyphrase (1–2 words only):\n"
                               . implode( "\n", array_unique( $used_kws ) );
        }

        // Existing post context (when supervised duplicate is detected)
        $existing_ctx = '';
        if ( ! empty( $args['existing_post'] ) ) {
            $ep = $args['existing_post'];
            $existing_ctx = "\n\nIMPORTANT: A post about this exact keyphrase already exists:\n"
                          . "Title: {$ep['title']}\n"
                          . "Summary: {$ep['excerpt']}\n"
                          . "URL: {$ep['url']}\n\n"
                          . "Your post MUST cover a DIFFERENT angle, continuation, or sub-topic. "
                          . "Do NOT repeat the existing post's content. "
                          . "Acknowledge the existing post with an internal link where appropriate.";
        }

        return trim( $base . $internal_link_ctx . $covered_ctx . $used_keywords_ctx . $existing_ctx . '

MANDATORY REQUIREMENTS — follow every rule exactly:
1. Pick exactly one category from this list: ' . $cat_names . '
2. Title MUST start with the focus keyphrase.
3. First paragraph MUST contain the focus keyphrase.
4. At least 2 H2 or H3 subheadings MUST contain the focus keyphrase or a close synonym.
5. Excerpt MUST contain the focus keyphrase naturally and be under 155 characters.
6. Slug MUST contain the focus keyphrase (lowercase, hyphens, no special chars).
7. Include 2–3 internal links using <a href="..."> to the recent posts listed above.
8. Keyphrase density should be approximately 1–2% of total word count.
9. Minimum 800 words of body content.
10. Use only <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>, <a> HTML tags.
11. Focus keyphrase MUST be 1–2 words only — no phrases longer than 2 words.

Respond with ONLY a valid JSON object — no markdown, no code fences, no extra text:
{
  "title":         "Focus Keyphrase: Compelling Rest of Title",
  "slug":          "focus-keyphrase-rest-of-title",
  "content":       "<p>HTML body...</p>",
  "excerpt":       "Excerpt containing the focus keyphrase (max 155 chars)",
  "focus_keyword": "exact focus keyphrase (1–2 words)",
  "category":      "ExactCategoryNameFromList",
  "image_prompt":  "Short visual scene description for image generation"
}' );
    }

    // -------------------------------------------------------------------------
    // API dispatch
    // -------------------------------------------------------------------------

    private static function call_text_api( string $model_id, string $api_key, string $prompt ): string|WP_Error {
        return match ( $model_id ) {
            'gemini-2.0-flash' => self::call_gemini( $api_key, $prompt ),
            'gpt-4o-mini'      => self::call_openai_compat( $api_key, 'gpt-4o-mini', 'https://api.openai.com/v1', $prompt ),
            'grok-3-mini'      => self::call_openai_compat( $api_key, 'grok-3-mini', 'https://api.x.ai/v1', $prompt ),
            default            => new WP_Error( 'unknown_model', "Unknown model ID: {$model_id}" ),
        };
    }

    private static function call_gemini( string $key, string $prompt ): string|WP_Error {
        $url      = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $key;
        $response = wp_remote_post( $url, [
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => wp_json_encode( [
                'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
                'generationConfig' => [ 'temperature' => 0.7, 'maxOutputTokens' => 4096 ],
            ] ),
            'timeout'     => 90,
            'data_format' => 'body',
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gemini_request_failed', 'Gemini request failed: ' . $response->get_error_message() );
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $decoded['error']['message'] ?? wp_remote_retrieve_body( $response );
            return new WP_Error( 'gemini_api_error', "Gemini API error ({$code}): {$msg}" );
        }

        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ( ! $text ) {
            return new WP_Error( 'gemini_empty', 'Gemini returned an empty response.' );
        }

        return $text;
    }

    private static function call_openai_compat( string $key, string $model, string $base_url, string $prompt ): string|WP_Error {
        $response = wp_remote_post( rtrim( $base_url, '/' ) . '/chat/completions', [
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'body'        => wp_json_encode( [
                'model'      => $model,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
                'max_tokens' => 4096,
            ] ),
            'timeout'     => 90,
            'data_format' => 'body',
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'openai_request_failed', "{$model} request failed: " . $response->get_error_message() );
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $decoded['error']['message'] ?? wp_remote_retrieve_body( $response );
            return new WP_Error( 'openai_api_error', "{$model} API error ({$code}): {$msg}" );
        }

        $text = $decoded['choices'][0]['message']['content'] ?? '';
        if ( ! $text ) {
            return new WP_Error( 'openai_empty', "{$model} returned an empty response." );
        }

        return $text;
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    private static function parse_ai_response( string $raw ): array|WP_Error {
        // Strip markdown fences
        $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $clean = preg_replace( '/\s*```$/', '', $clean );
        $clean = trim( $clean );

        // Extract outermost JSON object even if there is surrounding text
        $start = strpos( $clean, '{' );
        $end   = strrpos( $clean, '}' );

        if ( $start === false || $end === false || $end <= $start ) {
            return new WP_Error( 'parse_no_json', 'AI response contained no JSON object. Got: ' . substr( $raw, 0, 300 ) );
        }

        $decoded = json_decode( substr( $clean, $start, $end - $start + 1 ), true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'parse_json_error', 'JSON parse error: ' . json_last_error_msg() . '. Raw: ' . substr( $raw, 0, 300 ) );
        }

        foreach ( [ 'title', 'content', 'excerpt', 'focus_keyword' ] as $field ) {
            if ( empty( $decoded[ $field ] ) ) {
                return new WP_Error( 'parse_missing_field', "AI response missing required field: {$field}" );
            }
        }

        $decoded['slug']         = $decoded['slug']         ?? sanitize_title( $decoded['focus_keyword'] );
        $decoded['category']     = $decoded['category']     ?? '';
        $decoded['image_prompt'] = $decoded['image_prompt'] ?? $decoded['title'];

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Async image generation
    // -------------------------------------------------------------------------

    /**
     * Set the default placeholder immediately, then schedule a background job
     * to generate and attach the real AI image once it's ready.
     */
    public static function schedule_image_async( int $post_id, string $prompt, string $alt_text ): void {
        // Attach default image right away so the post is never imageless
        $default = self::get_fallback_image_id();
        if ( $default > 0 ) {
            set_post_thumbnail( $post_id, $default );
        }

        // Schedule the real generation a few seconds later
        wp_schedule_single_event(
            time() + 5,
            'ille_pg_image_async',
            [ $post_id, $prompt, $alt_text ]
        );
    }

    /**
     * WP-Cron callback: generate the real image and update the post thumbnail.
     * Called with args [$post_id, $prompt, $alt_text] from the cron hook.
     */
    public static function handle_async_image( int $post_id, string $prompt, string $alt_text ): void {
        if ( ! get_post( $post_id ) ) {
            return;
        }

        $attachment_id = self::generate_image_sync( $prompt, $alt_text );

        if ( $attachment_id > 0 ) {
            set_post_thumbnail( $post_id, $attachment_id );
            ILLE_PG_Logger::log(
                ILLE_PG_Logger::EVENT_POST_CREATED,
                [
                    'post_id'      => $post_id,
                    'image_update' => 'async_complete',
                    'attachment'   => $attachment_id,
                ],
                ILLE_PG_Logger::TRIGGER_CRON,
                0
            );
        }
    }

    /**
     * Synchronously generate an image using the configured image model.
     * Returns attachment ID on success, 0 on failure.
     */
    public static function generate_image_sync( string $prompt, string $alt_text ): int {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $model = ILLE_PG_Settings::resolve_image_model();

        $attachment_id = match ( $model['id'] ) {
            'dall-e-3'      => self::generate_image_dalle( $model['key'], $prompt, $alt_text ),
            'grok-aurora'   => self::generate_image_grok( $model['key'], $prompt, $alt_text ),
            'gemini-imagen' => self::generate_image_gemini( $model['key'], $prompt, $alt_text ),
            default         => self::generate_image_pollinations( $model['key'], $prompt, $alt_text ),
        };

        if ( $attachment_id > 0 ) {
            return $attachment_id;
        }

        // Primary model failed — try Pollinations as last resort (unless it was primary)
        if ( $model['id'] !== 'pollinations' ) {
            $poll_key = trim( (string) ILLE_PG_Settings::get( ILLE_PG_Settings::KEY_POLLINATIONS_KEY, '' ) );
            $attachment_id = self::generate_image_pollinations( $poll_key, $prompt, $alt_text );
        }

        return $attachment_id > 0 ? $attachment_id : 0;
    }

    // -------------------------------------------------------------------------
    // Image model implementations
    // -------------------------------------------------------------------------

    private static function generate_image_pollinations( string $key, string $prompt, string $alt_text ): int {
        $url = 'https://image.pollinations.ai/prompt/' . rawurlencode( $prompt )
             . '?width=1200&height=630&nologo=true&seed=' . wp_rand( 1, 99999 );

        if ( $key ) {
            $url .= '&token=' . rawurlencode( $key );
        }

        return self::download_and_sideload( $url, $alt_text, 60 );
    }

    private static function generate_image_dalle( string $key, string $prompt, string $alt_text ): int {
        if ( ! $key ) return 0;

        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'           => 'dall-e-3',
                'prompt'          => $prompt,
                'n'               => 1,
                'size'            => '1792x1024',
                'response_format' => 'url',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return 0;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $url  = $body['data'][0]['url'] ?? '';
        if ( ! $url ) return 0;

        return self::download_and_sideload( $url, $alt_text, 60 );
    }

    private static function generate_image_grok( string $key, string $prompt, string $alt_text ): int {
        if ( ! $key ) return 0;

        $response = wp_remote_post( 'https://api.x.ai/v1/images/generations', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'  => 'grok-2-image-1212',
                'prompt' => $prompt,
                'n'      => 1,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return 0;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $url  = $body['data'][0]['url'] ?? '';
        if ( ! $url ) return 0;

        return self::download_and_sideload( $url, $alt_text, 60 );
    }

    private static function generate_image_gemini( string $key, string $prompt, string $alt_text ): int {
        if ( ! $key ) return 0;

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0-generate-001:predict?key=' . $key,
            [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'instances'  => [ [ 'prompt' => $prompt ] ],
                    'parameters' => [ 'sampleCount' => 1, 'aspectRatio' => '16:9' ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return 0;

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $b64    = $body['predictions'][0]['bytesBase64Encoded'] ?? '';
        $mime   = $body['predictions'][0]['mimeType'] ?? 'image/png';
        if ( ! $b64 ) return 0;

        $ext     = ( $mime === 'image/jpeg' ) ? 'jpg' : 'png';
        $tmpfile = tempnam( sys_get_temp_dir(), 'ille-pg-' );
        file_put_contents( $tmpfile, base64_decode( $b64 ) );

        $file_array = [
            'name'     => 'ille-pg-image-' . time() . '.' . $ext,
            'tmp_name' => $tmpfile,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, $alt_text );
        @unlink( $tmpfile );

        return ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) ? $attachment_id : 0;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private static function download_and_sideload( string $url, string $alt_text, int $timeout = 30 ): int {
        $tmp = download_url( $url, $timeout );
        if ( is_wp_error( $tmp ) ) return 0;

        $file_array = [
            'name'     => 'ille-pg-image-' . time() . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, $alt_text );
        @unlink( $tmp );

        return ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) ? $attachment_id : 0;
    }

    private static function get_fallback_image_id(): int {
        $default = ILLE_PG_Settings::get_default_image_id();
        if ( $default > 0 ) return $default;

        $images = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        return ! empty( $images ) ? $images[0]->ID : 0;
    }
}
