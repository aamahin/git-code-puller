<?php
/**
 * Uninstall handler for Git Code Update plugin.
 *
 * Fired when the plugin is deleted via WordPress admin.
 *
 * @package Git_Code_Update
 * @since   1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'git_code_update_settings' );
delete_option( 'git_code_update_log' );

// For multisite, delete options from all sites.
if ( is_multisite() ) {
	$sites = get_sites();
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		delete_option( 'git_code_update_settings' );
		delete_option( 'git_code_update_log' );
		restore_current_blog();
	}
}
