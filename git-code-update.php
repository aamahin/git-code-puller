<?php
/**
 * Plugin Name: Git Code Update
 * Plugin URI:  https://github.com/your-repo/git-code-update
 * Description: Pull code directly from a GitHub repository into the WordPress plugins folder. Enables quick deployment of plugin updates from GitHub without manual file uploads.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://yourwebsite.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: git-code-update
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package Git_Code_Update
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'GIT_CODE_UPDATE_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 */
define( 'GIT_CODE_UPDATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'GIT_CODE_UPDATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'GIT_CODE_UPDATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin textdomain for internationalization.
 */
function git_code_update_load_textdomain() {
	load_plugin_textdomain(
		'git-code-update',
		false,
		dirname( GIT_CODE_UPDATE_PLUGIN_BASENAME ) . '/languages/'
	);
}
add_action( 'plugins_loaded', 'git_code_update_load_textdomain' );

/**
 * Load admin class files.
 */
require_once GIT_CODE_UPDATE_PLUGIN_DIR . 'includes/class-git-code-update-admin.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function git_code_update_init() {
	// Only load admin functionality in the admin area.
	if ( is_admin() ) {
		$admin = new Git_Code_Update_Admin();
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'git_code_update_init' );

/**
 * Activation hook - set default options.
 *
 * @return void
 */
function git_code_update_activate() {
	$defaults = array(
		'repo_url'       => '',
		'branch_name'    => 'main',
		'target_folder'  => '',
		'last_pull_time' => '',
	);
	$existing = get_option( 'git_code_update_settings', array() );
	$settings = wp_parse_args( $existing, $defaults );
	update_option( 'git_code_update_settings', $settings );
}
register_activation_hook( __FILE__, 'git_code_update_activate' );

/**
 * Deactivation hook - cleanup if needed.
 *
 * @return void
 */
function git_code_update_deactivate() {
	// Nothing to clean up on deactivation.
}
register_deactivation_hook( __FILE__, 'git_code_update_deactivate' );

/**
 * Uninstall hook - remove all plugin data.
 *
 * @return void
 */
function git_code_update_uninstall() {
	// Delete plugin options.
	delete_option( 'git_code_update_settings' );
	delete_option( 'git_code_update_log' );
}
register_uninstall_hook( __FILE__, 'git_code_update_uninstall' );
