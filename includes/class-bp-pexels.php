<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_Pexels {

    private static function api_key(): string {
        return get_option( 'bp_pexels_key', '' );
    }

    /**
     * Search Pexels and return the raw image URL (large2x preferred).
     * Returns null if nothing found.
     */
    public static function find_image( string $query ): ?string {
        $key = self::api_key();
        if ( ! $key ) return null;

        $url = add_query_arg( [
            'query'       => $query,
            'orientation' => 'landscape',
            'size'        => 'large',
            'per_page'    => 5,
            'page'        => 1,
        ], 'https://api.pexels.com/v1/search' );

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [ 'Authorization' => $key ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[BlogPublisher] Pexels error: ' . $response->get_error_message() );
            return null;
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $photos = $body['photos'] ?? [];
        if ( empty( $photos ) ) return null;

        $photo = $photos[0];
        return $photo['src']['large2x']
            ?? $photo['src']['large']
            ?? $photo['src']['original']
            ?? null;
    }

    /**
     * Download raw image bytes from a URL.
     */
    public static function download( string $url ): ?string {
        $response = wp_remote_get( $url, [
            'timeout'   => 30,
            'stream'    => false,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[BlogPublisher] Pexels download error: ' . $response->get_error_message() );
            return null;
        }

        return wp_remote_retrieve_body( $response );
    }
}
