<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ILLE_PG_Post_Creator {

    /**
     * Main entry point. Accepts an args array, returns post_id or WP_Error.
     *
     * @param array $args {
     *   @type string $topic          Optional. Blog title / topic hint.
     *   @type string $focus_keyword  Optional. Overrides AI-generated keyword.
     *   @type bool   $featured_image Whether to attach a featured image. Default true.
     *   @type string $post_status    'publish' or 'draft'. Default 'publish'.
     *   @type string $scheduled_date ISO 8601 datetime string or empty for immediate.
     * }
     */
    public static function create( array $args = [] ): int|WP_Error {
        $args = wp_parse_args( $args, [
            'topic'          => '',
            'focus_keyword'  => '',
            'featured_image' => true,
            'post_status'    => 'publish',
            'scheduled_date' => '',
            'author_id'      => 0,
        ] );

        // Resolve author: explicit arg → current user → fallback to admin (ID 1)
        if ( ! $args['author_id'] ) {
            $args['author_id'] = get_current_user_id() ?: 1;
        }

        $content_data = ILLE_PG_AI_Generator::generate( $args );

        if ( is_wp_error( $content_data ) ) {
            return $content_data;
        }

        // Resolve featured image
        $image_id = 0;
        if ( $args['featured_image'] ) {
            $image_id = ILLE_PG_AI_Generator::generate_image(
                $content_data['image_prompt'] ?? $content_data['title'],
                $content_data['focus_keyword'] ?? $content_data['title']
            );
        }

        // Resolve category
        $category_id = self::resolve_category( $content_data['category'] );

        // Determine post status + date
        $post_status = in_array( $args['post_status'], [ 'publish', 'draft', 'future' ], true )
            ? $args['post_status']
            : 'publish';

        $post_date = '';
        if ( $args['scheduled_date'] ) {
            $ts = strtotime( $args['scheduled_date'] );
            if ( $ts && $ts > time() ) {
                $post_date   = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) );
                $post_status = 'future';
            }
        }

        $post_data = [
            'post_title'    => sanitize_text_field( $content_data['title'] ),
            'post_name'     => sanitize_title( $content_data['slug'] ?? $content_data['focus_keyword'] ?? '' ),
            'post_content'  => wp_kses_post( $content_data['content'] ),
            'post_excerpt'  => sanitize_text_field( $content_data['excerpt'] ),
            'post_status'   => $post_status,
            'post_author'   => $args['author_id'],
            'post_category' => [ $category_id ],
        ];

        if ( $post_date ) {
            $post_data['post_date'] = $post_date;
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Featured image
        if ( $image_id > 0 ) {
            set_post_thumbnail( $post_id, $image_id );
        }

        // Yoast SEO fields
        $keyword = $args['focus_keyword'] ?: $content_data['focus_keyword'];
        update_post_meta( $post_id, '_yoast_wpseo_focuskw',  sanitize_text_field( $keyword ) );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $content_data['excerpt'] ) );
        update_post_meta( $post_id, '_yoast_wpseo_title',    sanitize_text_field( $keyword ) . ' %%sep%% %%sitename%%' );

        ILLE_PG_Logger::log(
            ILLE_PG_Logger::EVENT_POST_CREATED,
            [
                'post_id'  => $post_id,
                'title'    => $content_data['title'],
                'status'   => $post_status,
                'category' => $content_data['category'],
                'keyword'  => $keyword,
                'post_url' => get_permalink( $post_id ),
            ],
            $args['trigger'] ?? ILLE_PG_Logger::TRIGGER_MANUAL,
            $args['author_id']
        );

        return $post_id;
    }

    // -------------------------------------------------------------------------
    // Phase 1: Dummy content
    // -------------------------------------------------------------------------

    private static function generate_dummy_content( array $args ): array {
        $topic   = $args['topic'] ?: 'The Future of Digital Business in Nigeria';
        $keyword = $args['focus_keyword'] ?: 'digital business nigeria';

        $title   = $args['topic'] ? ucwords( $args['topic'] ) : 'The Future of Digital Business in Nigeria';

        $content = '<p>Nigeria\'s digital landscape is evolving at an unprecedented pace, reshaping how businesses operate and how consumers interact with brands. From fintech innovations to e-commerce growth, the opportunities for entrepreneurs and professionals are enormous. In this post, we explore key trends and practical steps you can take to position yourself for success in Nigeria\'s digital economy.</p>

<h2>Why Digital Transformation Matters Now</h2>
<p>The COVID-19 pandemic accelerated Nigeria\'s digital adoption by years. Today, millions of Nigerians shop online, pay bills via mobile apps, and work remotely. Businesses that embraced digital tools survived — and many thrived. Those that resisted fell behind.</p>
<p>With over 100 million internet users and a young, tech-savvy population, Nigeria represents one of Africa\'s most vibrant digital markets. The window to establish a digital presence has never been more accessible or more important.</p>

<h2>Key Sectors Driving Growth</h2>
<p>Fintech continues to lead the charge. Companies like Flutterwave, Paystack, and Kuda Bank have demonstrated that Nigerian startups can compete on a global stage. Their success has inspired a new generation of founders across health-tech, agri-tech, and edtech sectors.</p>
<p>E-commerce is another major driver. Jumia, Konga, and thousands of Instagram and WhatsApp-based small businesses are changing how Nigerians buy and sell. If your business is not selling online, you are leaving money on the table.</p>

<h2>Practical Steps to Go Digital</h2>
<p>Start with the basics: a professional website and active social media profiles. These are your digital storefronts. Invest in good photography, clear messaging, and consistent posting. Customers need to trust you before they buy from you.</p>
<p>Next, integrate a payment solution. Free tools like Paystack or Flutterwave make accepting online payments straightforward, even for small businesses. Remove every friction point between your customer and their purchase.</p>
<p>Finally, track your results. Use Google Analytics, Meta Insights, and your payment dashboard to understand what is working. Data-driven decisions separate growing businesses from stagnating ones.</p>

<h2>Challenges to Watch Out For</h2>
<p>Nigeria\'s digital economy is not without obstacles. Inconsistent power supply, high data costs, and cybersecurity risks remain real concerns. Factor these into your digital strategy and build resilience — offline payment fallbacks, data-light website versions, and strong password hygiene go a long way.</p>
<p>Regulatory changes can also impact digital businesses quickly. Stay informed about CBN guidelines, NITDA policies, and tax obligations for digital income to avoid unpleasant surprises.</p>

<h2>Conclusion</h2>
<p>Nigeria\'s digital future is bright, and the businesses that invest in their online presence today will reap the rewards tomorrow. Whether you are a startup founder, a side-hustle entrepreneur, or an established brand, going digital is no longer optional — it is essential. Start small, be consistent, and keep learning. The opportunities are right in front of you.</p>';

        $categories = get_categories( [ 'hide_empty' => false ] );
        $category   = ! empty( $categories ) ? $categories[0]->name : 'Uncategorized';

        return [
            'title'         => $title,
            'content'       => $content,
            'excerpt'       => 'Discover how Nigerian businesses can harness digital transformation to grow, compete, and thrive in today\'s fast-changing economy.',
            'focus_keyword' => $keyword,
            'category'      => $category,
            'image_prompt'  => 'Professional Nigerian entrepreneur working on laptop in modern office, digital technology concept',
        ];
    }

    private static function get_dummy_image( string $title, string $alt_text ): int {
        // Use the site's default placeholder or first image in media library
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $images = get_posts( $args );
        if ( ! empty( $images ) ) {
            return $images[0]->ID;
        }

        // No images in library — return 0 (no featured image)
        return 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function resolve_category( string $ai_category ): int {
        $categories = get_categories( [ 'hide_empty' => false ] );
        foreach ( $categories as $cat ) {
            if ( strcasecmp( $cat->name, $ai_category ) === 0 ) {
                return $cat->term_id;
            }
        }
        return (int) get_option( 'default_category' );
    }
}
