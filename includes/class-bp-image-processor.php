<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resize to 1280px width (locked aspect ratio) and convert to WebP.
 * Uses WordPress's WP_Image_Editor (GD or Imagick — whichever is available).
 * Falls back to raw GD if WP_Image_Editor can't handle the source.
 */
class BP_Image_Processor {

    const TARGET_WIDTH = 1280;

    /**
     * Takes raw image bytes, returns processed WebP bytes.
     * Returns null on failure.
     */
    public static function process( string $raw_bytes ): ?string {
        // Write to a temp file so WP_Image_Editor can open it
        $tmp_in  = wp_tempnam( 'bp_img_in' );
        $tmp_out = wp_tempnam( 'bp_img_out' ) . '.webp';

        file_put_contents( $tmp_in, $raw_bytes );

        $result = self::process_with_wp_editor( $tmp_in, $tmp_out );

        if ( ! $result ) {
            $result = self::process_with_gd( $tmp_in, $tmp_out );
        }

        @unlink( $tmp_in );

        if ( ! $result || ! file_exists( $tmp_out ) ) {
            @unlink( $tmp_out );
            return null;
        }

        $webp_bytes = file_get_contents( $tmp_out );
        @unlink( $tmp_out );
        return $webp_bytes ?: null;
    }

    private static function process_with_wp_editor( string $tmp_in, string $tmp_out ): bool {
        $editor = wp_get_image_editor( $tmp_in );
        if ( is_wp_error( $editor ) ) return false;

        $size = $editor->get_size();
        if ( is_wp_error( $size ) ) return false;

        $orig_w = $size['width'];
        $orig_h = $size['height'];

        if ( $orig_w > self::TARGET_WIDTH ) {
            $ratio  = self::TARGET_WIDTH / $orig_w;
            $new_h  = (int) round( $orig_h * $ratio );
            $result = $editor->resize( self::TARGET_WIDTH, $new_h, false );
            if ( is_wp_error( $result ) ) return false;
        }

        $saved = $editor->save( $tmp_out, 'image/webp' );
        return ! is_wp_error( $saved ) && file_exists( $tmp_out );
    }

    private static function process_with_gd( string $tmp_in, string $tmp_out ): bool {
        if ( ! function_exists( 'imagecreatefromstring' ) ) return false;

        $raw  = file_get_contents( $tmp_in );
        $src  = @imagecreatefromstring( $raw );
        if ( ! $src ) return false;

        $orig_w = imagesx( $src );
        $orig_h = imagesy( $src );

        if ( $orig_w > self::TARGET_WIDTH ) {
            $ratio  = self::TARGET_WIDTH / $orig_w;
            $new_w  = self::TARGET_WIDTH;
            $new_h  = (int) round( $orig_h * $ratio );
            $dst    = imagecreatetruecolor( $new_w, $new_h );
            imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
            imagedestroy( $src );
            $src = $dst;
        }

        if ( ! function_exists( 'imagewebp' ) ) {
            imagedestroy( $src );
            return false;
        }

        $ok = imagewebp( $src, $tmp_out, 85 );
        imagedestroy( $src );
        return $ok && file_exists( $tmp_out );
    }

    /**
     * Upload processed WebP bytes to WordPress Media Library.
     * Returns attachment ID or null on failure.
     */
    public static function upload_to_media_library(
        string $webp_bytes,
        string $filename,
        string $alt_text,
        int    $post_id = 0
    ): ?int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload = wp_upload_bits( $filename, null, $webp_bytes );
        if ( $upload['error'] ) {
            error_log( '[BlogPublisher] wp_upload_bits error: ' . $upload['error'] );
            return null;
        }

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => 'image/webp',
            'post_title'     => sanitize_file_name( $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'], $post_id );

        if ( is_wp_error( $attachment_id ) ) return null;

        $meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $meta );

        if ( $alt_text ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
        }

        return $attachment_id;
    }
}
