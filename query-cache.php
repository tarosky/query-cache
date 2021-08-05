<?php
/**
Plugin Name: Query Cache
Plugin URI: https://wordpress.org/plugins/query-cache/
Description: Leverage object cache to enhance WP_Query
Author: Tarosky INC.
Version: nightly
Author URI: https://tarosky.co.jp/
License: GPL3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: query-cache
Domain Path: /languages
 */

defined( 'ABSPATH' ) or die();

/**
 * Initializer.
 */
function ts_query_cache_init() {
	// Load text domain.
	load_plugin_textdomain( 'query-cache', false, basename( __DIR__ ) . '/languages' );
	// Initialize.
	require __DIR__ . '/vendor/autoload.php';
	\Tarosky\QueryCache::get_instance();
}
add_action( 'plugin_loaded', 'ts_query_cache_init' );
