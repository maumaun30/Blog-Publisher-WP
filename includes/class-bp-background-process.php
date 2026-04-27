<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Background job processor using WP Cron.
 * Picks up queued jobs and processes them one at a time.
 */
class BP_Background_Process {

    const CRON_HOOK    = 'bp_process_queue';
    const CRON_SINGLE  = 'bp_process_single_job';

    public static function init(): void {
        add_action( self::CRON_HOOK,   [ __CLASS__, 'run_queue' ] );
        add_action( self::CRON_SINGLE, [ __CLASS__, 'process_job' ], 10, 1 );

        // Register cron interval (every 1 minute)
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_interval' ] );
    }

    public static function add_cron_interval( array $schedules ): array {
        $schedules['bp_every_minute'] = [
            'interval' => 60,
            'display'  => 'Every Minute (Blog Publisher)',
        ];
        return $schedules;
    }

    public static function schedule_queue(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'bp_every_minute', self::CRON_HOOK );
        }
    }

    public static function run_queue(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'bp_jobs';

        // Get next queued job
        $job = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 1"
        );

        if ( ! $job ) return;

        // Mark as processing to prevent duplicate runs
        $wpdb->update( $table, [
            'status'  => 'processing',
            'message' => 'Job picked up by queue runner.',
        ], [ 'id' => $job->id ] );

        self::process_job( (int) $job->id );
    }

    public static function process_job( int $job_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'bp_jobs';

        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ) );
        if ( ! $job ) return;

        $upload_dir = wp_upload_dir();
        $file_path  = $upload_dir['basedir'] . '/blog-publisher-tmp/' . basename( $job->filename );

        try {
            // ── Parse docx ────────────────────────────────────────────
            self::update_job( $job_id, 'processing', 'Parsing document…' );
            $parsed   = BP_Docx_Parser::parse( $file_path );
            $title    = $parsed['title'];
            $sections = $parsed['sections'];

            if ( ! $title ) throw new \RuntimeException( 'No H1 heading found in document.' );

            // ── SEO metadata ──────────────────────────────────────────
            self::update_job( $job_id, 'processing', 'Generating SEO metadata…' );
            $full_text = $title . "\n" . implode( "\n", array_map(
                fn( $s ) => $s['heading'] . "\n" . $s['content'], $sections
            ) );
            $seo = BP_AI::seo( $title, $full_text );

            // ── Featured image ────────────────────────────────────────
            self::update_job( $job_id, 'processing', 'Fetching featured image…' );
            $intro_text        = $sections[0]['content'] ?? '';
            $featured_query    = BP_AI::featured_query( $title, $intro_text );
            $featured_alt      = BP_AI::alt_text( $title, $featured_query );
            $featured_att_id   = 0;

            $featured_url = BP_Pexels::find_image( $featured_query );
            if ( $featured_url ) {
                $raw = BP_Pexels::download( $featured_url );
                if ( $raw ) {
                    $webp = BP_Image_Processor::process( $raw );
                    if ( $webp ) {
                        $slug            = sanitize_title( substr( $featured_query, 0, 50 ) );
                        $featured_att_id = BP_Image_Processor::upload_to_media_library(
                            $webp, "{$slug}.webp", $featured_alt
                        ) ?? 0;
                    }
                }
            }

            // ── Per-section images ────────────────────────────────────
            $enriched = [];
            foreach ( $sections as $i => $sec ) {
                $heading = $sec['heading'];
                $num     = $i + 1;
                $total   = count( $sections );

                if ( ! $heading ) {
                    $enriched[] = array_merge( $sec, [
                        'image_attachment_id' => 0,
                        'image_url'           => null,
                        'image_alt'           => null,
                    ] );
                    continue;
                }

                self::update_job( $job_id, 'processing', "Fetching image {$num}/{$total}: {$heading}…" );

                $query   = BP_AI::pexels_query( $heading, $sec['content'] );
                $alt     = BP_AI::alt_text( $heading, $query );
                $att_id  = 0;
                $img_url = null;

                $pexels_url = BP_Pexels::find_image( $query );
                if ( $pexels_url ) {
                    $raw = BP_Pexels::download( $pexels_url );
                    if ( $raw ) {
                        $webp = BP_Image_Processor::process( $raw );
                        if ( $webp ) {
                            $slug   = sanitize_title( substr( $query, 0, 50 ) );
                            $att_id = BP_Image_Processor::upload_to_media_library(
                                $webp, "{$slug}.webp", $alt, 0
                            ) ?? 0;
                            if ( $att_id ) {
                                $img_url = wp_get_attachment_url( $att_id );
                            }
                        }
                    }
                }

                $enriched[] = array_merge( $sec, [
                    'image_attachment_id' => $att_id,
                    'image_url'           => $img_url,
                    'image_alt'           => $alt,
                ] );
            }

            // ── Create post ───────────────────────────────────────────
            self::update_job( $job_id, 'processing', 'Creating WordPress post…' );
            $post_id = BP_Post_Creator::create(
                $title,
                $enriched,
                $seo,
                $featured_att_id,
                $job->post_type,
                (int) $job->author_id
            );

            $post_url = get_edit_post_link( $post_id, 'raw' );

            // ── Done ──────────────────────────────────────────────────
            $wpdb->update( $table, [
                'status'   => 'done',
                'message'  => 'Published successfully.',
                'post_id'  => $post_id,
                'post_url' => $post_url,
            ], [ 'id' => $job_id ] );

        } catch ( \Throwable $e ) {
            self::update_job( $job_id, 'error', $e->getMessage() );
            error_log( '[BlogPublisher] Job ' . $job_id . ' failed: ' . $e->getMessage() );
        } finally {
            // Clean up temp file
            if ( file_exists( $file_path ) ) @unlink( $file_path );

            // Schedule next job if any remain
            $remaining = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'"
            );
            if ( $remaining > 0 ) {
                wp_schedule_single_event( time() + 5, self::CRON_SINGLE, [ self::get_next_job_id() ] );
            }
        }
    }

    private static function get_next_job_id(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bp_jobs';
        return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 1" );
    }

    private static function update_job( int $id, string $status, string $message ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bp_jobs',
            [ 'status' => $status, 'message' => $message ],
            [ 'id' => $id ]
        );
    }
}
