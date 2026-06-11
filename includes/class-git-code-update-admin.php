<?php
/**
 * Admin functionality for Git Code Update plugin.
 *
 * Handles the settings page, form processing, and GitHub pull operations.
 *
 * @package Git_Code_Update
 * @since   1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Git_Code_Update_Admin
 *
 * Manages all admin-facing functionality for the plugin.
 */
class Git_Code_Update_Admin {

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Log entries for debugging.
	 *
	 * @var array
	 */
	private $log = array();

	/**
	 * Initialize the admin class.
	 *
	 * @return void
	 */
	public function init() {
		$this->settings = get_option( 'git_code_update_settings', array() );
		$this->log      = get_option( 'git_code_update_log', array() );

		// Add settings page.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

		// Register settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Enqueue admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Handle AJAX pull request.
		add_action( 'wp_ajax_git_code_update_pull', array( $this, 'ajax_pull_code' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . GIT_CODE_UPDATE_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Git Code Update', 'git-code-update' ),
			__( 'Git Code Update', 'git-code-update' ),
			'manage_options',
			'git-code-update',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings using Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'git_code_update_settings_group',
			'git_code_update_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// General Settings Section.
		add_settings_section(
			'git_code_update_general_section',
			__( 'Repository Settings', 'git-code-update' ),
			array( $this, 'render_section_description' ),
			'git-code-update'
		);

		// GitHub Repository URL field.
		add_settings_field(
			'repo_url',
			__( 'GitHub Repository URL', 'git-code-update' ),
			array( $this, 'render_text_field' ),
			'git-code-update',
			'git_code_update_general_section',
			array(
				'id'          => 'repo_url',
				'description' => __( 'Enter the full GitHub repository URL (e.g., https://github.com/username/repo-name).', 'git-code-update' ),
				'placeholder' => 'https://github.com/username/repo-name',
			)
		);

		// Branch Name field.
		add_settings_field(
			'branch_name',
			__( 'Branch Name', 'git-code-update' ),
			array( $this, 'render_text_field' ),
			'git-code-update',
			'git_code_update_general_section',
			array(
				'id'          => 'branch_name',
				'description' => __( 'Specify which branch to pull (e.g., main or master).', 'git-code-update' ),
				'placeholder' => 'main',
			)
		);

		// Target Plugin Folder Name field.
		add_settings_field(
			'target_folder',
			__( 'Target Plugin Folder Name', 'git-code-update' ),
			array( $this, 'render_text_field' ),
			'git-code-update',
			'git_code_update_general_section',
			array(
				'id'          => 'target_folder',
				'description' => __( 'Destination folder name inside wp-content/plugins/.', 'git-code-update' ),
				'placeholder' => 'my-plugin',
			)
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Sanitize GitHub URL.
		$sanitized['repo_url'] = esc_url_raw( trim( $input['repo_url'] ?? '' ) );

		// Validate GitHub URL format.
		if ( ! empty( $sanitized['repo_url'] ) && ! $this->is_valid_github_url( $sanitized['repo_url'] ) ) {
			add_settings_error(
				'git_code_update_settings',
				'invalid_github_url',
				__( 'Please enter a valid GitHub repository URL.', 'git-code-update' ),
				'error'
			);
			$sanitized['repo_url'] = '';
		}

		// Sanitize branch name - allow alphanumeric, hyphens, underscores, dots, and slashes.
		$sanitized['branch_name'] = sanitize_text_field( trim( $input['branch_name'] ?? 'main' ) );
		if ( empty( $sanitized['branch_name'] ) ) {
			$sanitized['branch_name'] = 'main';
		}

		// Sanitize target folder - only allow alphanumeric, hyphens, and underscores.
		$sanitized['target_folder'] = sanitize_text_field( trim( $input['target_folder'] ?? '' ) );
		if ( ! empty( $sanitized['target_folder'] ) ) {
			// Remove any path traversal attempts.
			$sanitized['target_folder'] = basename( $sanitized['target_folder'] );
			// Only allow safe characters.
			$sanitized['target_folder'] = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $sanitized['target_folder'] );
		}

		// Preserve last pull time.
		$sanitized['last_pull_time'] = $this->settings['last_pull_time'] ?? '';

		return $sanitized;
	}

	/**
	 * Validate if the URL is a valid GitHub repository URL.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if valid GitHub URL, false otherwise.
	 */
	private function is_valid_github_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed ) {
			return false;
		}

		// Check host.
		$valid_hosts = array( 'github.com', 'www.github.com' );
		if ( ! in_array( $parsed['host'] ?? '', $valid_hosts, true ) ) {
			return false;
		}

		// Check path format: /username/repo-name.
		$path = trim( $parsed['path'] ?? '', '/' );
		if ( empty( $path ) ) {
			return false;
		}

		$parts = explode( '/', $path );
		if ( count( $parts ) < 2 ) {
			return false;
		}

		// Validate username and repo name contain valid characters.
		$username = $parts[0];
		$repo     = $parts[1];

		return preg_match( '/^[a-zA-Z0-9\-_]+$/', $username )
			&& preg_match( '/^[a-zA-Z0-9\-_.]+$/', $repo );
	}

	/**
	 * Render the section description.
	 *
	 * @return void
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the GitHub repository settings to pull code from.', 'git-code-update' ) . '</p>';
	}

	/**
	 * Render a text field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_text_field( $args ) {
		$id          = $args['id'];
		$description = $args['description'];
		$placeholder = $args['placeholder'] ?? '';
		$value       = $this->settings[ $id ] ?? '';

		printf(
			'<input type="text" id="%s" name="git_code_update_settings[%s]" value="%s" placeholder="%s" class="regular-text" />',
			esc_attr( $id ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( $description )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last_pull = $this->settings['last_pull_time'] ?? '';
		?>
		<div class="wrap git-code-update-wrap">
			<h1><?php echo esc_html__( 'Git Code Update', 'git-code-update' ); ?></h1>

			<div class="git-code-update-info-box">
				<h2><?php echo esc_html__( 'How it works', 'git-code-update' ); ?></h2>
				<p><?php echo esc_html__( 'This plugin allows you to pull code directly from a GitHub repository into your WordPress plugins folder. Configure the settings below and click "Pull Code" to deploy.', 'git-code-update' ); ?></p>
			</div>

			<form method="post" action="options.php" id="git-code-update-settings-form">
				<?php
				settings_fields( 'git_code_update_settings_group' );
				do_settings_sections( 'git-code-update' );
				submit_button( __( 'Save Settings', 'git-code-update' ) );
				?>
			</form>

			<div class="git-code-update-pull-section">
				<h2><?php echo esc_html__( 'Pull Code from GitHub', 'git-code-update' ); ?></h2>

				<?php if ( ! empty( $last_pull ) ) : ?>
					<p class="git-code-update-last-pull">
						<strong><?php echo esc_html__( 'Last Pull:', 'git-code-update' ); ?></strong>
						<?php echo esc_html( $last_pull ); ?>
					</p>
				<?php endif; ?>

				<div id="git-code-update-status" class="git-code-update-status" style="display: none;">
					<p id="git-code-update-status-message"></p>
				</div>

				<p>
					<button type="button" id="git-code-update-pull-btn" class="button button-primary button-hero">
						<span class="dashicons dashicons-update" style="margin-top: 6px;"></span>
						<?php echo esc_html__( 'Pull Code Now', 'git-code-update' ); ?>
					</button>
				</p>

				<div id="git-code-update-log-section" style="display: none;">
					<h3><?php echo esc_html__( 'Operation Log', 'git-code-update' ); ?></h3>
					<pre id="git-code-update-log-output"></pre>
				</div>
			</div>

			<div class="git-code-update-help-box">
				<h3><?php echo esc_html__( 'Important Notes', 'git-code-update' ); ?></h3>
				<ul>
					<li><?php echo esc_html__( 'Always backup your files before pulling code.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'The target folder will be overwritten with the repository contents.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'Make sure the GitHub repository is public or accessible from your server.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'The branch name must exist in the repository.', 'git-code-update' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin CSS and JavaScript.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our settings page.
		if ( 'settings_page_git-code-update' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'git-code-update-admin',
			GIT_CODE_UPDATE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			GIT_CODE_UPDATE_VERSION
		);

		wp_enqueue_script(
			'git-code-update-admin',
			GIT_CODE_UPDATE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GIT_CODE_UPDATE_VERSION,
			true
		);

		wp_localize_script(
			'git-code-update-admin',
			'gitCodeUpdate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'git_code_update_pull_nonce' ),
				'strings' => array(
					'confirmPull' => __( 'Are you sure you want to pull code? This will overwrite the target folder.', 'git-code-update' ),
					'pulling'     => __( 'Pulling code...', 'git-code-update' ),
					'success'     => __( 'Code pulled successfully!', 'git-code-update' ),
					'error'       => __( 'Error pulling code. Please check the log for details.', 'git-code-update' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX request to pull code.
	 *
	 * @return void
	 */
	public function ajax_pull_code() {
		// Verify nonce.
		check_ajax_referer( 'git_code_update_pull_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'git-code-update' ),
				)
			);
		}

		// Refresh settings.
		$this->settings = get_option( 'git_code_update_settings', array() );

		// Validate settings.
		$repo_url      = $this->settings['repo_url'] ?? '';
		$branch_name   = $this->settings['branch_name'] ?? 'main';
		$target_folder = $this->settings['target_folder'] ?? '';

		// Validation checks.
		if ( empty( $repo_url ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure the GitHub Repository URL in settings.', 'git-code-update' ),
				)
			);
		}

		if ( empty( $target_folder ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure the Target Plugin Folder Name in settings.', 'git-code-update' ),
				)
			);
		}

		if ( ! $this->is_valid_github_url( $repo_url ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid GitHub repository URL.', 'git-code-update' ),
				)
			);
		}

		// Build the ZIP download URL.
		$repo_url    = untrailingslashit( $repo_url );
		$parsed      = wp_parse_url( $repo_url );
		$path        = trim( $parsed['path'] ?? '', '/' );
		$zip_url     = 'https://github.com/' . $path . '/archive/refs/heads/' . rawurlencode( $branch_name ) . '.zip';

		$this->add_log( 'Starting pull from: ' . $zip_url );

		// Step 1: Download the ZIP file.
		$this->add_log( 'Downloading ZIP file...' );
		$download_result = $this->download_file( $zip_url );

		if ( is_wp_error( $download_result ) ) {
			$this->add_log( 'Download failed: ' . $download_result->get_error_message() );
			$this->save_log();
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Failed to download ZIP: %s', 'git-code-update' ),
						$download_result->get_error_message()
					),
					'log'     => $this->log,
				)
			);
		}

		$zip_path = $download_result['file_path'];
		$this->add_log( 'ZIP downloaded to: ' . $zip_path );

		// Step 2: Extract the ZIP file.
		$this->add_log( 'Extracting ZIP file...' );
		$extract_result = $this->extract_zip( $zip_path );

		if ( is_wp_error( $extract_result ) ) {
			$this->add_log( 'Extraction failed: ' . $extract_result->get_error_message() );
			$this->cleanup_temp_files( $zip_path );
			$this->save_log();
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Failed to extract ZIP: %s', 'git-code-update' ),
						$extract_result->get_error_message()
					),
					'log'     => $this->log,
				)
			);
		}

		$extract_dir = $extract_result['extract_dir'];
		$this->add_log( 'ZIP extracted to: ' . $extract_dir );

		// Step 3: Copy files to target directory.
		$plugins_dir = WP_PLUGIN_DIR;
		$target_dir  = $plugins_dir . '/' . $target_folder;

		$this->add_log( 'Target directory: ' . $target_dir );

		// Find the extracted subdirectory (GitHub creates a folder like repo-branch).
		$subdirs = glob( $extract_dir . '/*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$this->add_log( 'No subdirectory found in extracted ZIP.' );
			$this->cleanup_temp_files( $zip_path );
			$this->save_log();
			wp_send_json_error(
				array(
					'message' => __( 'Invalid ZIP structure. No directory found in extracted files.', 'git-code-update' ),
					'log'     => $this->log,
				)
			);
		}

		$source_dir = $subdirs[0]; // Use the first subdirectory.
		$this->add_log( 'Source directory: ' . $source_dir );

		// Copy files to target.
		$this->add_log( 'Copying files to target directory...' );
		$copy_result = $this->copy_directory( $source_dir, $target_dir );

		if ( is_wp_error( $copy_result ) ) {
			$this->add_log( 'Copy failed: ' . $copy_result->get_error_message() );
			$this->cleanup_temp_files( $zip_path );
			$this->save_log();
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: Error message */
						__( 'Failed to copy files: %s', 'git-code-update' ),
						$copy_result->get_error_message()
					),
					'log'     => $this->log,
				)
			);
		}

		$this->add_log( 'Files copied successfully!' );

		// Step 4: Cleanup temporary files.
		$this->add_log( 'Cleaning up temporary files...' );
		$this->cleanup_temp_files( $zip_path );
		$this->add_log( 'Cleanup complete.' );

		// Update last pull time.
		$this->settings['last_pull_time'] = current_time( 'mysql' );
		update_option( 'git_code_update_settings', $this->settings );

		$this->add_log( 'Pull operation completed successfully!' );
		$this->save_log();

		wp_send_json_success(
			array(
				'message'   => __( 'Code pulled successfully! Files have been deployed to the target folder.', 'git-code-update' ),
				'timestamp' => $this->settings['last_pull_time'],
				'log'       => $this->log,
			)
		);
	}

	/**
	 * Download a file from a URL.
	 *
	 * @param string $url The URL to download from.
	 * @return array|WP_Error Array with file_path on success, WP_Error on failure.
	 */
	private function download_file( $url ) {
		// Create temp directory.
		$temp_dir = get_temp_dir() . 'git-code-update-' . wp_generate_password( 12, false ) . '/';
		wp_mkdir_p( $temp_dir );

		$file_path = $temp_dir . 'repo.zip';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $file_path,
				'headers'  => array(
					'Accept' => 'application/zip',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cleanup_temp_dir( $temp_dir );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP error %d while downloading file.', 'git-code-update' ),
					$status_code
				)
			);
		}

		// Verify file exists and has content.
		if ( ! file_exists( $file_path ) || 0 === filesize( $file_path ) ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'empty_file',
				__( 'Downloaded file is empty or does not exist.', 'git-code-update' )
			);
		}

		return array(
			'file_path'  => $file_path,
			'temp_dir'   => $temp_dir,
		);
	}

	/**
	 * Extract a ZIP file.
	 *
	 * @param string $zip_path Path to the ZIP file.
	 * @return array|WP_Error Array with extract_dir on success, WP_Error on failure.
	 */
	private function extract_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'zip_not_available',
				__( 'ZipArchive PHP extension is not available.', 'git-code-update' )
			);
		}

		$zip = new ZipArchive();
		$result = $zip->open( $zip_path );

		if ( true !== $result ) {
			return new WP_Error(
				'zip_open_failed',
				sprintf(
					/* translators: %d: ZipArchive error code */
					__( 'Failed to open ZIP file. Error code: %d', 'git-code-update' ),
					$result
				)
			);
		}

		$extract_dir = dirname( $zip_path ) . '/extracted/';
		wp_mkdir_p( $extract_dir );

		$zip->extractTo( $extract_dir );
		$zip->close();

		return array(
			'extract_dir' => $extract_dir,
		);
	}

	/**
	 * Copy directory recursively.
	 *
	 * @param string $source Source directory path.
	 * @param string $destination Destination directory path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function copy_directory( $source, $destination ) {
		// Use WordPress Filesystem API.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Initialize WP_Filesystem.
		$access_type = get_filesystem_method();
		if ( 'direct' === $access_type ) {
			WP_Filesystem();
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'filesystem_error',
				__( 'Could not initialize WordPress Filesystem.', 'git-code-update' )
			);
		}

		// Check if source exists.
		if ( ! $wp_filesystem->is_dir( $source ) ) {
			return new WP_Error(
				'source_not_found',
				sprintf(
					/* translators: %s: Source directory path */
					__( 'Source directory does not exist: %s', 'git-code-update' ),
					$source
				)
			);
		}

		// Create destination if it doesn't exist.
		if ( ! $wp_filesystem->is_dir( $destination ) ) {
			$wp_filesystem->mkdir( $destination, FS_CHMOD_DIR );
		}

		// Copy files recursively.
		$result = $this->recursive_copy( $source, $destination );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Recursively copy files and directories.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function recursive_copy( $source, $destination ) {
		global $wp_filesystem;

		$dir = opendir( $source );

		if ( ! $dir ) {
			return new WP_Error(
				'dir_open_failed',
				sprintf(
					/* translators: %s: Directory path */
					__( 'Failed to open directory: %s', 'git-code-update' ),
					$source
				)
			);
		}

		// Create destination directory.
		if ( ! $wp_filesystem->is_dir( $destination ) ) {
			$wp_filesystem->mkdir( $destination, FS_CHMOD_DIR );
		}

		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$source_path      = $source . '/' . $file;
			$destination_path = $destination . '/' . $file;

			if ( is_dir( $source_path ) ) {
				// Recursively copy subdirectory.
				$result = $this->recursive_copy( $source_path, $destination_path );
				if ( is_wp_error( $result ) ) {
					closedir( $dir );
					return $result;
				}
			} else {
				// Copy file.
				$content = $wp_filesystem->get_contents( $source_path );
				if ( false === $content ) {
					closedir( $dir );
					return new WP_Error(
						'read_failed',
						sprintf(
							/* translators: %s: File path */
							__( 'Failed to read file: %s', 'git-code-update' ),
							$source_path
						)
					);
				}

				$written = $wp_filesystem->put_contents(
					$destination_path,
					$content,
					FS_CHMOD_FILE
				);

				if ( ! $written ) {
					closedir( $dir );
					return new WP_Error(
						'write_failed',
						sprintf(
							/* translators: %s: File path */
							__( 'Failed to write file: %s', 'git-code-update' ),
							$destination_path
						)
					);
				}
			}
		}

		closedir( $dir );
		return true;
	}

	/**
	 * Clean up temporary files.
	 *
	 * @param string $zip_path Path to the ZIP file.
	 * @return void
	 */
	private function cleanup_temp_files( $zip_path ) {
		$temp_dir = dirname( $zip_path );
		$this->cleanup_temp_dir( $temp_dir );
	}

	/**
	 * Clean up a temporary directory recursively.
	 *
	 * @param string $dir Directory path to clean up.
	 * @return void
	 */
	private function cleanup_temp_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->cleanup_temp_dir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Add entry to operation log.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function add_log( $message ) {
		$timestamp = current_time( 'H:i:s' );
		$this->log[] = '[' . $timestamp . '] ' . $message;
	}

	/**
	 * Save log to database.
	 *
	 * @return void
	 */
	private function save_log() {
		// Keep only last 50 entries.
		if ( count( $this->log ) > 50 ) {
			$this->log = array_slice( $this->log, -50 );
		}
		update_option( 'git_code_update_log', $this->log );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=git-code-update' ),
			__( 'Settings', 'git-code-update' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
