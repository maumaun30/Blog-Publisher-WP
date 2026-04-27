<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_Activator {

    public static function activate() {
        global $wpdb;
        $table  = $wpdb->prefix . 'bp_jobs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id    VARCHAR(36)         NOT NULL,
            filename    VARCHAR(255)        NOT NULL,
            status      VARCHAR(20)         NOT NULL DEFAULT 'queued',
            message     TEXT,
            post_id     BIGINT(20) UNSIGNED DEFAULT NULL,
            post_url    VARCHAR(500)        DEFAULT NULL,
            post_type   VARCHAR(50)         NOT NULL DEFAULT 'post',
            author_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY status  (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        add_option( 'bp_version', BP_VERSION );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'bp_process_queue' );
    }
}
