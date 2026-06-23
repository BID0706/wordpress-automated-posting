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
            $args['focus_keyword'] ?? ''
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

    private static function build_prompt( string $topic, string $keyword ): string {
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

        // Recent posts for internal linking
        $recent_posts      = get_posts( [ 'numberposts' => 5, 'post_status' => 'publish' ] );
        $internal_link_ctx = '';
        if ( ! empty( $recent_posts ) ) {
            $lines = [];
            foreach ( $recent_posts as $p ) {
                $lines[] = '- ' . get_the_title( $p ) . ': ' . get_permalink( $p );
            }
            $internal_link_ctx = "\n\nRecent posts available for internal linking:\n" . implode( "\n", $lines );
        }

        return trim( $base . $internal_link_ctx . '

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

Respond with ONLY a valid JSON object — no markdown, no code fences, no extra text:
{
  "title":         "Focus Keyphrase: Compelling Rest of Title",
  "slug":          "focus-keyphrase-rest-of-title",
  "content":       "<p>HTML body...</p>",
  "excerpt":       "Excerpt containing the focus keyphrase (max 155 chars)",
  "focus_keyword": "exact focus keyphrase",
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
    // Image generation (Pollinations.ai)
    // -------------------------------------------------------------------------

    public static function generate_image( string $prompt, string $alt_text ): int {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url = 'https://image.pollinations.ai/prompt/' . urlencode( $prompt )
             . '?width=1200&height=630&nologo=true&seed=' . wp_rand( 1, 99999 );

        $attachment_id = media_sideload_image( $url, 0, $alt_text, 'id' );

        if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
            return $attachment_id;
        }

        // Fallback 1: admin-configured default image
        $default = ILLE_PG_Settings::get_default_image_id();
        if ( $default > 0 ) {
            return $default;
        }

        // Fallback 2: most recent media library image
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
