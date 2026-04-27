<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_AI {

    private static function api_key(): string {
        return get_option( 'bp_anthropic_key', '' );
    }

    private static function call( string $prompt, int $max_tokens = 300 ): ?string {
        $key = self::api_key();
        if ( ! $key ) return null;

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => $max_tokens,
                'messages'   => [
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[BlogPublisher] Anthropic error: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['content'][0]['text'] ?? null;
    }

    public static function pexels_query( string $heading, string $content_snippet ): string {
        $snippet = substr( strip_tags( $content_snippet ), 0, 300 );
        $result  = self::call(
            "You are helping find a landscape stock photo for a blog section.\n\n" .
            "Section heading: {$heading}\n" .
            "Content preview: {$snippet}\n\n" .
            "Return ONLY a short Pexels search query (3-6 words) for a relevant, high-quality landscape photo. No quotes, no explanation.",
            30
        );
        return trim( $result ?? $heading );
    }

    public static function alt_text( string $heading, string $query ): string {
        $result = self::call(
            "Write a concise, descriptive image alt text (max 125 characters) for a landscape photo " .
            "used in a blog section titled \"{$heading}\". The photo was found with query: \"{$query}\". " .
            "Return ONLY the alt text, no quotes, no explanation.",
            60
        );
        return substr( trim( $result ?? "{$heading} — {$query}" ), 0, 125 );
    }

    public static function seo( string $title, string $full_text ): array {
        $excerpt = substr( strip_tags( $full_text ), 0, 800 );
        $result  = self::call(
            "Generate SEO metadata for this blog post.\n\n" .
            "Title: {$title}\nContent excerpt: {$excerpt}\n\n" .
            "Return a JSON object with exactly:\n" .
            "- \"meta_title\": SEO-optimized title, max 60 characters\n" .
            "- \"meta_description\": compelling description, max 155 characters\n\n" .
            "Return ONLY valid JSON, no markdown, no explanation.",
            200
        );

        if ( ! $result ) return [ 'meta_title' => substr( $title, 0, 60 ), 'meta_description' => '' ];

        $clean = preg_replace( '/```json|```/', '', $result );
        $data  = json_decode( trim( $clean ), true );

        return [
            'meta_title'        => substr( $data['meta_title']        ?? $title, 0, 60 ),
            'meta_description'  => substr( $data['meta_description']  ?? '',      0, 155 ),
        ];
    }

    public static function featured_query( string $title, string $intro ): string {
        $snippet = substr( strip_tags( $intro ), 0, 300 );
        $result  = self::call(
            "You are helping find a featured hero image for a blog post.\n\n" .
            "Blog title: {$title}\nIntro: {$snippet}\n\n" .
            "Return ONLY a short Pexels search query (3-6 words) for a beautiful landscape hero image. No quotes, no explanation.",
            30
        );
        return trim( $result ?? $title );
    }
}
