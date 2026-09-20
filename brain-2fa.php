<?php
/**
 * Plugin Name: Brain 2FA
 * Description: Two Factor Authentication (2FA) plugin for WordPress.
 * Version: 1.0.0
 * Author: Braintum
 * Author URI: https://www.braintum.com/
 * Text Domain: brain2fa
 * Domain Path: /languages
 * License: GPLv2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Tested up to: 7.1.1
 *
 * @package Brain2FA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoload.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

define( 'BRAIN_2FA_VERSION', '1.0.0' );
define( 'BRAIN_2FA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRAIN_2FA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main instance of Brain 2FA App.
 *
 * @return \Brain_2FA\App
 */
function brain_2fa(): \Brain_2FA\App {
	return \Brain_2FA\App::instance();
}

// Initialize the plugin.
brain_2fa();
