<?php
/*
Plugin Name: Modern Primary Category
Version: 0.0.1
Author: Saru Tole <sarutole@gmail.com>
Description: Gutenberg-friendly primary category plugin. Supports custom post types and custom taxonomies. Enables taxonomy placeholders in post permalinks. Not supported on Classical Editor.
Author URI: https://github.com/sarutole/
Plugin URI: https://github.com/sarutole/modern-primary-category
Text Domain: stmpc
Domain Path: /languages/
*/
defined( 'ABSPATH' ) || die;

if ( !class_exists( 'ST_MPC' ) ):

class ST_MPC {

	static function on_load() {

		register_activation_hook( __FILE__, [ __CLASS__, 'prepare_activation' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate_plugin' ] );

		add_action( 'plugins_loaded', [ __CLASS__, 'load_textdomain' ] );
		include_once dirname( __FILE__ ) . '/classes/class-modern-primary-category.php';
	}

	/**
	 * Run on plugin activation (will effectively flush permalinks to use the new logic).
	 * Will redirect to plugins page if minimum version requirements are not satisfied.
	 */
	static function prepare_activation() {
		add_option( 'stmpc/activation', 1 );
	}

	/**
	 * Run on plugin deactivation (restore permalinks).
	 */
	static function deactivate_plugin() {
		flush_rewrite_rules( $hard = true );
	}

	/**
	 * Load translation file
	 */
	static function load_textdomain() {
		load_plugin_textdomain( 'stmpc', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}

}

endif;

ST_MPC::on_load();
