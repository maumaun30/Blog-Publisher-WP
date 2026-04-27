<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_REST_API {

    const NAMESPACE = 'blog-publisher/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        // Upload docx files
        register_rest_route( self::NAMESPACE, '/upload', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_upload' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );

        // Poll job status
        register_rest_route( self::NAMESPACE, '/jobs', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_jobs' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );

        // Get post types
        register_rest_route( self::NAMESPACE, '/post-types', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_post_types' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );

        // Save settings
        register_rest_route( self::NAMESPACE, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_settings' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'save_settings' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ],
        ] );

        // Trigger cron manually (for hosts without real cron)
        register_rest_route( self::NAMESPACE, '/trigger', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'trigger_processing' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );
    }

    public static function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public static function handle_upload( \WP_REST_Request $request ): \WP_REST_Response {
        $files     = $request->get_file_params();
        $post_type = sanitize_text_field( $request->get_param( 'post_type' ) ?? 'post' );
        $author_id = get_current_user_id();

        if ( empty( $files['files'] ) ) {
            return new \WP_REST_Response( [ 'error' => 'No files uploaded.' ], 400 );
        }

        // Normalise single vs multiple file upload
        $file_list = self::normalise_files( $files['files'] );

        $batch_id   = wp_generate_uuid4();
        $tmp_dir    = wp_upload_dir()['basedir'] . '/blog-publisher-tmp';
        wp_mkdir_p( $tmp_dir );

        global $wpdb;
        $table    = $wpdb->prefix . 'bp_jobs';
        $job_ids  = [];

        foreach ( $file_list as $file ) {
            if ( $file['error'] !== UPLOAD_ERR_OK ) continue;

            $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            if ( $ext !== 'docx' ) continue;

            $safe_name = wp_unique_filename( $tmp_dir, sanitize_file_name( $file['name'] ) );
            $dest      = $tmp_dir . '/' . $safe_name;

            if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) continue;

            $wpdb->insert( $table, [
                'batch_id'  => $batch_id,
                'filename'  => $safe_name,
                'status'    => 'queued',
                'message'   => 'Waiting in queue…',
                'post_type' => $post_type,
                'author_id' => $author_id,
            ] );

            $job_ids[] = $wpdb->insert_id;
        }

        if ( empty( $job_ids ) ) {
            return new \WP_REST_Response( [ 'error' => 'No valid .docx files found.' ], 400 );
        }

        // Kick off cron
        BP_Background_Process::schedule_queue();
        wp_schedule_single_event( time() + 2, BP_Background_Process::CRON_SINGLE, [ $job_ids[0] ] );
        spawn_cron();

        return new \WP_REST_Response( [
            'batch_id' => $batch_id,
            'job_ids'  => $job_ids,
            'message'  => count( $job_ids ) . ' file(s) queued.',
        ], 202 );
    }

    public static function get_jobs( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table    = $wpdb->prefix . 'bp_jobs';
        $batch_id = sanitize_text_field( $request->get_param( 'batch_id' ) ?? '' );

        if ( $batch_id ) {
            $jobs = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE batch_id = %s ORDER BY id ASC",
                $batch_id
            ), ARRAY_A );
        } else {
            $jobs = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT 50",
                ARRAY_A
            );
        }

        return new \WP_REST_Response( $jobs, 200 );
    }

    public static function get_post_types(): \WP_REST_Response {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        $out   = [];
        foreach ( $types as $slug => $obj ) {
            if ( in_array( $slug, [ 'attachment', 'revision' ], true ) ) continue;
            $out[] = [ 'value' => $slug, 'label' => $obj->labels->singular_name ];
        }
        return new \WP_REST_Response( $out, 200 );
    }

    public static function get_settings(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'anthropic_key' => self::mask( get_option( 'bp_anthropic_key', '' ) ),
            'pexels_key'    => self::mask( get_option( 'bp_pexels_key', '' ) ),
        ], 200 );
    }

    public static function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();

        if ( isset( $body['anthropic_key'] ) && ! self::is_masked( $body['anthropic_key'] ) ) {
            update_option( 'bp_anthropic_key', sanitize_text_field( $body['anthropic_key'] ) );
        }
        if ( isset( $body['pexels_key'] ) && ! self::is_masked( $body['pexels_key'] ) ) {
            update_option( 'bp_pexels_key', sanitize_text_field( $body['pexels_key'] ) );
        }

        return new \WP_REST_Response( [ 'saved' => true ], 200 );
    }

    public static function trigger_processing(): \WP_REST_Response {
        BP_Background_Process::run_queue();
        return new \WP_REST_Response( [ 'triggered' => true ], 200 );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function normalise_files( array $files ): array {
        // PHP gives different shapes for single vs multiple file inputs
        if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
            $out = [];
            foreach ( $files['name'] as $i => $name ) {
                $out[] = [
                    'name'     => $name,
                    'tmp_name' => $files['tmp_name'][ $i ],
                    'error'    => $files['error'][ $i ],
                    'size'     => $files['size'][ $i ],
                ];
            }
            return $out;
        }
        return [ $files ];
    }

    private static function mask( string $key ): string {
        if ( strlen( $key ) < 8 ) return $key ? '••••••••' : '';
        return substr( $key, 0, 4 ) . str_repeat( '•', max( 0, strlen( $key ) - 8 ) ) . substr( $key, -4 );
    }

    private static function is_masked( string $val ): bool {
        return strpos( $val, '•' ) !== false;
    }
}
