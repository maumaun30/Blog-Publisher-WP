<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_Post_Creator {

    /**
     * Build body HTML from enriched sections and create the WP post.
     *
     * @param string $title
     * @param array  $sections  Each: [ heading, content, image_attachment_id, image_url, image_alt ]
     * @param array  $seo       [ meta_title, meta_description ]
     * @param int    $featured_attachment_id
     * @param string $post_type
     * @param int    $author_id
     * @return int  Post ID
     */
    public static function create(
        string $title,
        array  $sections,
        array  $seo,
        int    $featured_attachment_id,
        string $post_type,
        int    $author_id
    ): int {
        $body_html = self::build_body_html( $sections );

        $post_id = wp_insert_post( [
            'post_title'   => wp_strip_all_tags( $title ),
            'post_content' => $body_html,
            'post_status'  => 'draft',
            'post_type'    => $post_type,
            'post_author'  => $author_id,
        ], true );

        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }

        // Featured image
        if ( $featured_attachment_id ) {
            set_post_thumbnail( $post_id, $featured_attachment_id );
        }

        // SEO — Yoast if active, otherwise skip
        self::apply_seo( $post_id, $seo );

        return $post_id;
    }

    private static function build_body_html( array $sections ): string {
        $parts = [];

        foreach ( $sections as $sec ) {
            $heading    = $sec['heading']   ?? '';
            $content    = $sec['content']   ?? '';
            $img_url    = $sec['image_url'] ?? null;
            $img_alt    = esc_attr( $sec['image_alt'] ?? $heading );
            $att_id     = $sec['image_attachment_id'] ?? 0;

            if ( $heading ) {
                $parts[] = '<h2>' . esc_html( $heading ) . '</h2>';
            }

            if ( $img_url ) {
                // Use wp_get_attachment_image if we have an attachment ID (generates srcset etc.)
                if ( $att_id ) {
                    $img_tag = wp_get_attachment_image( $att_id, 'full', false, [
                        'alt'   => $img_alt,
                        'style' => 'max-width:100%;height:auto;display:block;margin:1.5em 0;',
                    ] );
                } else {
                    $img_tag = sprintf(
                        '<img src="%s" alt="%s" width="1280" style="max-width:100%%;height:auto;display:block;margin:1.5em 0;" />',
                        esc_url( $img_url ),
                        $img_alt
                    );
                }
                $parts[] = $img_tag;
            }

            if ( $content ) {
                $parts[] = $content;
            }
        }

        return implode( "\n", $parts );
    }

    private static function apply_seo( int $post_id, array $seo ): void {
        $meta_title = $seo['meta_title']       ?? '';
        $meta_desc  = $seo['meta_description'] ?? '';

        if ( ! $meta_title && ! $meta_desc ) return;

        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            if ( $meta_title ) update_post_meta( $post_id, '_yoast_wpseo_title',    $meta_title );
            if ( $meta_desc )  update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
            return;
        }

        // Rank Math
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            if ( $meta_title ) update_post_meta( $post_id, 'rank_math_title',            $meta_title );
            if ( $meta_desc )  update_post_meta( $post_id, 'rank_math_description',      $meta_desc );
            return;
        }

        // All in One SEO
        if ( defined( 'AIOSEO_VERSION' ) ) {
            if ( $meta_title ) update_post_meta( $post_id, '_aioseo_title',       $meta_title );
            if ( $meta_desc )  update_post_meta( $post_id, '_aioseo_description', $meta_desc );
            return;
        }

        // No SEO plugin — skip (as agreed)
    }
}
