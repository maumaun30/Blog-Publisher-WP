<?php
/**
 * Plugin Name: Blog Publisher
 * Plugin URI:  https://github.com/maumaun30/blog-publisher-wp
 * Description: Upload .docx blog posts and auto-publish them with AI-sourced images and SEO via Anthropic + Pexels.
 * Version:     1.0.2
 * Author:      Mau
 * License:     GPL-2.0+
 * Text Domain: blog-publisher
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BP_VERSION',     '1.0.2' );
define( 'BP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'BP_PLUGIN_FILE', __FILE__ );

require_once BP_PLUGIN_DIR . 'includes/class-bp-activator.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-docx-parser.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-ai.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-pexels.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-image-processor.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-post-creator.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-background-process.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-rest-api.php';
require_once BP_PLUGIN_DIR . 'includes/class-bp-admin.php';

register_activation_hook( __FILE__,   [ 'BP_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BP_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function() {
    BP_Admin::init();
    BP_REST_API::init();
    BP_Background_Process::init();
} );
