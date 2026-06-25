=== Git Code Update ===
Contributors: yourname
Tags: github, git, deploy, update, code
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pull code directly from a GitHub repository into your WordPress plugins folder.

== Description ==

Git Code Update allows WordPress administrators to pull code directly from a GitHub repository into the WordPress plugins folder. This enables quick deployment of plugin updates from GitHub without manual file uploads.

= Features =

* Configure GitHub repository URL, branch name, target folder, and access token
* Fetch available branches from GitHub and select from a dropdown
* One-click code pulling from GitHub
* Automatic ZIP download and extraction
* Operation logging for debugging
* Clean and intuitive admin interface
* Secure with nonce verification and capability checks

= How It Works =

1. Enter your GitHub repository URL in the settings
2. Specify the branch name (e.g., main, master)
3. Set the target plugin folder name
4. Click "Pull Code Now" to deploy

= Requirements =

* PHP 7.4 or higher
* WordPress 5.0 or higher
* ZipArchive PHP extension enabled
* Write permissions for wp-content/plugins directory

= Important Notes =

* Always backup your files before pulling code
* The target folder will be overwritten with the repository contents
* For private repositories, enter a GitHub personal access token with repo scope
* Make sure the GitHub repository is public or accessible from your server

== Installation ==

1. Upload the `git-code-update` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Git Code Update to configure

== Frequently Asked Questions ==

= Is this plugin secure? =

Yes, the plugin uses WordPress nonces for form submissions, checks user capabilities (manage_options), validates all inputs, and prevents directory traversal attacks.

= Can I use private repositories? =

Yes. Enter a GitHub personal access token with at least `repo` scope in the repository settings. The plugin will use the GitHub API to download private repository archives.

= What happens to existing files in the target folder? =

Files in the target folder will be overwritten with the repository contents. It's recommended to backup your files before pulling.

= Does this work with multisite? =

Yes, the plugin supports WordPress multisite installations.

== Screenshots ==

1. Settings page with configuration options
2. Pull code button and status messages
3. Operation log display

== Changelog ==

= 1.3.0 =
* Added Load Branches button to fetch and select branches from GitHub

= 1.2.0 =
* Added personal access token support for private GitHub repositories

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.3.0 =
Added a Load Branches button that fetches available branches from GitHub and lets you select one from a dropdown.

= 1.2.0 =
Added support for pulling code from private GitHub repositories using personal access tokens.

= 1.0.0 =
Initial release of the Git Code Update plugin.
