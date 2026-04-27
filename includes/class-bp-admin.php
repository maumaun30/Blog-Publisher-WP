<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BP_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'Blog Publisher',
            'Blog Publisher',
            'edit_posts',
            'blog-publisher',
            [ __CLASS__, 'render_page' ],
            'dashicons-upload',
            30
        );

        add_submenu_page(
            'blog-publisher',
            'Upload Posts',
            'Upload Posts',
            'edit_posts',
            'blog-publisher',
            [ __CLASS__, 'render_page' ]
        );

        add_submenu_page(
            'blog-publisher',
            'Settings',
            'Settings',
            'manage_options',
            'blog-publisher-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'blog-publisher' ) === false ) return;

        wp_enqueue_style(
            'bp-admin',
            BP_PLUGIN_URL . 'admin/css/app.css',
            [],
            BP_VERSION
        );

        wp_enqueue_script(
            'bp-admin',
            BP_PLUGIN_URL . 'admin/js/app.js',
            [],
            BP_VERSION,
            true
        );

        wp_localize_script( 'bp-admin', 'BP', [
            'restUrl'   => esc_url_raw( rest_url( 'blog-publisher/v1' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'page'      => isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : 'blog-publisher',
            'hasYoast'  => defined( 'WPSEO_VERSION' )    ? 'Yoast SEO'      : null,
            'hasRankMath' => defined( 'RANK_MATH_VERSION' ) ? 'Rank Math'   : null,
            'hasAioseo' => defined( 'AIOSEO_VERSION' )   ? 'All in One SEO' : null,
        ] );
    }

    public static function render_page(): void {
        echo '<div id="bp-app"></div>';
    }
}
