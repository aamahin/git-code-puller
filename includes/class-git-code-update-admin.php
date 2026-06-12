<?php
/**
 * Admin functionality for Git Code Update plugin.
 *
 * Handles the settings page, form processing, and GitHub pull operations.
 * Supports multiple repositories with repeatable field groups.
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

		// Ensure repos key exists.
		if ( ! isset( $this->settings['repos'] ) || ! is_array( $this->settings['repos'] ) ) {
			$this->settings['repos'] = array();
		}

		// Migrate old format if needed.
		$this->settings = git_code_update_migrate_settings( $this->settings );

		$this->log = get_option( 'git_code_update_log', array() );

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

		// Repositories repeatable field group.
		add_settings_field(
			'repos',
			__( 'Repositories', 'git-code-update' ),
			array( $this, 'render_repos_field' ),
			'git-code-update',
			'git_code_update_general_section',
			array()
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array(
			'repos' => array(),
		);

		if ( isset( $input['repos'] ) && is_array( $input['repos'] ) ) {
			foreach ( $input['repos'] as $index => $repo ) {
				$clean_repo = array();

				// Sanitize GitHub URL.
				$clean_repo['repo_url'] = esc_url_raw( trim( $repo['repo_url'] ?? '' ) );

				// Validate GitHub URL format.
				if ( ! empty( $clean_repo['repo_url'] ) && ! $this->is_valid_github_url( $clean_repo['repo_url'] ) ) {
					add_settings_error(
						'git_code_update_settings',
						'invalid_github_url_' . $index,
						sprintf(
							/* translators: %d: Repository number */
							__( 'Repository #%d: Please enter a valid GitHub repository URL.', 'git-code-update' ),
							$index + 1
						),
						'error'
					);
					$clean_repo['repo_url'] = '';
				}

				// Sanitize branch name.
				$clean_repo['branch_name'] = sanitize_text_field( trim( $repo['branch_name'] ?? 'main' ) );
				if ( empty( $clean_repo['branch_name'] ) ) {
					$clean_repo['branch_name'] = 'main';
				}

				// Sanitize target folder.
				$clean_repo['target_folder'] = sanitize_text_field( trim( $repo['target_folder'] ?? '' ) );
				if ( ! empty( $clean_repo['target_folder'] ) ) {
					$clean_repo['target_folder'] = basename( $clean_repo['target_folder'] );
					$clean_repo['target_folder'] = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $clean_repo['target_folder'] );
				}

				// Preserve last pull time from existing settings.
				$existing_repos = $this->settings['repos'] ?? array();
				$existing_repo  = $existing_repos[ $index ] ?? array();
				$clean_repo['last_pull_time'] = $existing_repo['last_pull_time'] ?? '';

				// Skip completely empty entries.
				if ( empty( $clean_repo['repo_url'] ) && empty( $clean_repo['target_folder'] ) ) {
					continue;
				}

				$sanitized['repos'][] = $clean_repo;
			}
		}

		// Ensure at least one empty row exists for new entries.
		if ( empty( $sanitized['repos'] ) ) {
			$sanitized['repos'] = array(
				array(
					'repo_url'      => '',
					'branch_name'   => 'main',
					'target_folder' => '',
					'last_pull_time'=> '',
				),
			);
		}

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
		echo '<p>' . esc_html__( 'Configure one or more GitHub repositories. Each repository entry maps a GitHub repo to a target folder in wp-content/plugins/.', 'git-code-update' ) . '</p>';
	}

	/**
	 * Render the repeatable repos field group.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_repos_field( $args ) {
		$repos = $this->settings['repos'] ?? array();

		// Ensure at least one entry.
		if ( empty( $repos ) ) {
			$repos = array(
				array(
					'repo_url'      => '',
					'branch_name'   => 'main',
					'target_folder' => '',
					'last_pull_time'=> '',
				),
			);
		}
		?>
		<div id="git-code-update-repos-container">
			<?php foreach ( $repos as $index => $repo ) : ?>
				<div class="git-code-update-repo-row" data-index="<?php echo esc_attr( $index ); ?>">
					<div class="git-code-update-repo-header">
						<span class="git-code-update-repo-title">
							<?php
							printf(
								/* translators: %d: Repository number */
								esc_html__( 'Repository #%d', 'git-code-update' ),
								$index + 1
							);
							?>
						</span>
						<button type="button" class="git-code-update-remove-repo button-link-delete" title="<?php esc_attr_e( 'Remove this repository', 'git-code-update' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
					</div>
					<div class="git-code-update-repo-fields">
						<p class="git-code-update-field-row">
							<label>
								<?php esc_html_e( 'GitHub Repository URL', 'git-code-update' ); ?>
							</label>
							<input type="text"
								name="git_code_update_settings[repos][<?php echo esc_attr( $index ); ?>][repo_url]"
								value="<?php echo esc_attr( $repo['repo_url'] ?? '' ); ?>"
								placeholder="https://github.com/username/repo-name"
								class="regular-text"
							/>
							<span class="description"><?php esc_html_e( 'Full GitHub repository URL.', 'git-code-update' ); ?></span>
						</p>
						<p class="git-code-update-field-row">
							<label>
								<?php esc_html_e( 'Branch Name', 'git-code-update' ); ?>
							</label>
							<input type="text"
								name="git_code_update_settings[repos][<?php echo esc_attr( $index ); ?>][branch_name]"
								value="<?php echo esc_attr( $repo['branch_name'] ?? 'main' ); ?>"
								placeholder="main"
								class="regular-text"
							/>
							<span class="description"><?php esc_html_e( 'Branch to pull (e.g., main, master, develop).', 'git-code-update' ); ?></span>
						</p>
						<p class="git-code-update-field-row">
							<label>
								<?php esc_html_e( 'Target Plugin Folder', 'git-code-update' ); ?>
							</label>
							<input type="text"
								name="git_code_update_settings[repos][<?php echo esc_attr( $index ); ?>][target_folder]"
								value="<?php echo esc_attr( $repo['target_folder'] ?? '' ); ?>"
								placeholder="my-plugin"
								class="regular-text"
							/>
							<span class="description"><?php esc_html_e( 'Destination folder inside wp-content/plugins/.', 'git-code-update' ); ?></span>
						</p>
						<?php if ( ! empty( $repo['last_pull_time'] ) ) : ?>
							<p class="git-code-update-field-row git-code-update-last-pull-row">
								<strong><?php esc_html_e( 'Last Pull:', 'git-code-update' ); ?></strong>
								<span class="git-code-update-last-pull-time" data-index="<?php echo esc_attr( $index ); ?>">
									<?php echo esc_html( $repo['last_pull_time'] ); ?>
								</span>
							</p>
						<?php endif; ?>
						<p class="git-code-update-field-row">
							<button type="button" class="button git-code-update-pull-single-btn" data-index="<?php echo esc_attr( $index ); ?>">
								<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'Pull Code', 'git-code-update' ); ?>
							</button>
							<span class="git-code-update-single-status" data-index="<?php echo esc_attr( $index ); ?>"></span>
						</p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<script type="text/html" id="git-code-update-repo-template">
			<div class="git-code-update-repo-row" data-index="{{INDEX}}">
				<div class="git-code-update-repo-header">
					<span class="git-code-update-repo-title">
						<?php
						/* translators: {{INDEX}} will be replaced by JS */
						echo esc_html__( 'Repository #{{INDEX_PLUS_1}}', 'git-code-update' );
						?>
					</span>
					<button type="button" class="git-code-update-remove-repo button-link-delete" title="<?php esc_attr_e( 'Remove this repository', 'git-code-update' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
				<div class="git-code-update-repo-fields">
					<p class="git-code-update-field-row">
						<label><?php esc_html_e( 'GitHub Repository URL', 'git-code-update' ); ?></label>
						<input type="text"
							name="git_code_update_settings[repos][{{INDEX}}][repo_url]"
							value=""
							placeholder="https://github.com/username/repo-name"
							class="regular-text"
						/>
						<span class="description"><?php esc_html_e( 'Full GitHub repository URL.', 'git-code-update' ); ?></span>
					</p>
					<p class="git-code-update-field-row">
						<label><?php esc_html_e( 'Branch Name', 'git-code-update' ); ?></label>
						<input type="text"
							name="git_code_update_settings[repos][{{INDEX}}][branch_name]"
							value="main"
							placeholder="main"
							class="regular-text"
						/>
						<span class="description"><?php esc_html_e( 'Branch to pull (e.g., main, master, develop).', 'git-code-update' ); ?></span>
					</p>
					<p class="git-code-update-field-row">
						<label><?php esc_html_e( 'Target Plugin Folder', 'git-code-update' ); ?></label>
						<input type="text"
							name="git_code_update_settings[repos][{{INDEX}}][target_folder]"
							value=""
							placeholder="my-plugin"
							class="regular-text"
						/>
						<span class="description"><?php esc_html_e( 'Destination folder inside wp-content/plugins/.', 'git-code-update' ); ?></span>
					</p>
					<p class="git-code-update-field-row">
						<button type="button" class="button git-code-update-pull-single-btn" data-index="{{INDEX}}">
							<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Pull Code', 'git-code-update' ); ?>
						</button>
						<span class="git-code-update-single-status" data-index="{{INDEX}}"></span>
					</p>
				</div>
			</div>
		</script>

		<p>
			<button type="button" id="git-code-update-add-repo" class="button button-secondary">
				<span class="dashicons dashicons-plus-alt" style="margin-top: 4px;"></span>
				<?php esc_html_e( 'Add Repository', 'git-code-update' ); ?>
			</button>
		</p>
		<?php
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
		?>
		<div class="wrap git-code-update-wrap">
			<h1><?php echo esc_html__( 'Git Code Update', 'git-code-update' ); ?></h1>

			<div class="git-code-update-info-box">
				<h2><?php echo esc_html__( 'How it works', 'git-code-update' ); ?></h2>
				<p><?php echo esc_html__( 'This plugin allows you to pull code directly from GitHub repositories into your WordPress plugins folder. Add one or more repositories below, configure each one, and click "Pull Code" on any repo to deploy.', 'git-code-update' ); ?></p>
			</div>

			<form method="post" action="options.php" id="git-code-update-settings-form">
				<?php
				settings_fields( 'git_code_update_settings_group' );
				do_settings_sections( 'git-code-update' );
				submit_button( __( 'Save Settings', 'git-code-update' ) );
				?>
			</form>

			<div id="git-code-update-status" class="git-code-update-status" style="display: none;">
				<p id="git-code-update-status-message"></p>
			</div>

			<div id="git-code-update-log-section" style="display: none;">
				<h3><?php echo esc_html__( 'Operation Log', 'git-code-update' ); ?></h3>
				<pre id="git-code-update-log-output"></pre>
			</div>

			<div class="git-code-update-help-box">
				<h3><?php echo esc_html__( 'Important Notes', 'git-code-update' ); ?></h3>
				<ul>
					<li><?php echo esc_html__( 'Always backup your files before pulling code.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'The target folder will be overwritten with the repository contents.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'Make sure the GitHub repository is public or accessible from your server.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'The branch name must exist in the repository.', 'git-code-update' ); ?></li>
					<li><?php echo esc_html__( 'Save your settings before pulling code from a newly added repository.', 'git-code-update' ); ?></li>
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
					'confirmPull'  => __( 'Are you sure you want to pull code for this repository? This will overwrite the target folder.', 'git-code-update' ),
					'pulling'      => __( 'Pulling code...', 'git-code-update' ),
					'success'      => __( 'Code pulled successfully!', 'git-code-update' ),
					'error'        => __( 'Error pulling code. Please check the log for details.', 'git-code-update' ),
					'repoLabel'    => __( 'Repository #', 'git-code-update' ),
				),
			)
		);
	}

	/**
	 * Handle AJAX request to pull code for a specific repository.
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

		// Get repo index from request.
		$repo_index = isset( $_POST['repo_index'] ) ? absint( $_POST['repo_index'] ) : 0;

		// Refresh settings.
		$this->settings = get_option( 'git_code_update_settings', array() );
		$this->settings = git_code_update_migrate_settings( $this->settings );
		$this->log      = get_option( 'git_code_update_log', array() );

		// Validate repo index.
		$repos = $this->settings['repos'] ?? array();
		if ( ! isset( $repos[ $repo_index ] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid repository index.', 'git-code-update' ),
				)
			);
		}

		$repo         = $repos[ $repo_index ];
		$repo_url     = $repo['repo_url'] ?? '';
		$branch_name  = $repo['branch_name'] ?? 'main';
		$target_folder = $repo['target_folder'] ?? '';

		// Validation checks.
		if ( empty( $repo_url ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure the GitHub Repository URL for this entry.', 'git-code-update' ),
				)
			);
		}

		if ( empty( $target_folder ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure the Target Plugin Folder Name for this entry.', 'git-code-update' ),
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
		$repo_url = untrailingslashit( $repo_url );
		$parsed   = wp_parse_url( $repo_url );
		$path     = trim( $parsed['path'] ?? '', '/' );
		$zip_url  = 'https://github.com/' . $path . '/archive/refs/heads/' . rawurlencode( $branch_name ) . '.zip';

		$this->add_log( '[' . ( $repo_index + 1 ) . '] Starting pull from: ' . $zip_url );

		// Step 1: Download the ZIP file.
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Downloading ZIP file...' );
		$download_result = $this->download_file( $zip_url );

		if ( is_wp_error( $download_result ) ) {
			$this->add_log( '[' . ( $repo_index + 1 ) . '] Download failed: ' . $download_result->get_error_message() );
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
		$this->add_log( '[' . ( $repo_index + 1 ) . '] ZIP downloaded to: ' . $zip_path );

		// Step 2: Extract the ZIP file.
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Extracting ZIP file...' );
		$extract_result = $this->extract_zip( $zip_path );

		if ( is_wp_error( $extract_result ) ) {
			$this->add_log( '[' . ( $repo_index + 1 ) . '] Extraction failed: ' . $extract_result->get_error_message() );
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
		$this->add_log( '[' . ( $repo_index + 1 ) . '] ZIP extracted to: ' . $extract_dir );

		// Step 3: Copy files to target directory.
		$plugins_dir = WP_PLUGIN_DIR;
		$target_dir  = $plugins_dir . '/' . $target_folder;

		$this->add_log( '[' . ( $repo_index + 1 ) . '] Target directory: ' . $target_dir );

		// Find the extracted subdirectory (GitHub creates a folder like repo-branch).
		$subdirs = glob( $extract_dir . '/*', GLOB_ONLYDIR );
		if ( empty( $subdirs ) ) {
			$this->add_log( '[' . ( $repo_index + 1 ) . '] No subdirectory found in extracted ZIP.' );
			$this->cleanup_temp_files( $zip_path );
			$this->save_log();
			wp_send_json_error(
				array(
					'message' => __( 'Invalid ZIP structure. No directory found in extracted files.', 'git-code-update' ),
					'log'     => $this->log,
				)
			);
		}

		$source_dir = $subdirs[0];
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Source directory: ' . $source_dir );

		// Copy files to target.
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Copying files to target directory...' );
		$copy_result = $this->copy_directory( $source_dir, $target_dir );

		if ( is_wp_error( $copy_result ) ) {
			$this->add_log( '[' . ( $repo_index + 1 ) . '] Copy failed: ' . $copy_result->get_error_message() );
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

		$this->add_log( '[' . ( $repo_index + 1 ) . '] Files copied successfully!' );

		// Step 4: Cleanup temporary files.
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Cleaning up temporary files...' );
		$this->cleanup_temp_files( $zip_path );
		$this->add_log( '[' . ( $repo_index + 1 ) . '] Cleanup complete.' );

		// Update last pull time for this specific repo.
		$this->settings['repos'][ $repo_index ]['last_pull_time'] = current_time( 'mysql' );
		update_option( 'git_code_update_settings', $this->settings );

		$this->add_log( '[' . ( $repo_index + 1 ) . '] Pull operation completed successfully!' );
		$this->save_log();

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %s: Target folder name */
					__( 'Code pulled successfully! Files have been deployed to: %s', 'git-code-update' ),
					$target_folder
				),
				'timestamp'  => $this->settings['repos'][ $repo_index ]['last_pull_time'],
				'repo_index' => $repo_index,
				'log'        => $this->log,
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

		if ( ! file_exists( $file_path ) || 0 === filesize( $file_path ) ) {
			$this->cleanup_temp_dir( $temp_dir );
			return new WP_Error(
				'empty_file',
				__( 'Downloaded file is empty or does not exist.', 'git-code-update' )
			);
		}

		return array(
			'file_path' => $file_path,
			'temp_dir'  => $temp_dir,
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

		$zip    = new ZipArchive();
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
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

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

		if ( ! $wp_filesystem->is_dir( $destination ) ) {
			$wp_filesystem->mkdir( $destination, FS_CHMOD_DIR );
		}

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
				$result = $this->recursive_copy( $source_path, $destination_path );
				if ( is_wp_error( $result ) ) {
					closedir( $dir );
					return $result;
				}
			} else {
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
		$timestamp    = current_time( 'H:i:s' );
		$this->log[] = '[' . $timestamp . '] ' . $message;
	}

	/**
	 * Save log to database.
	 *
	 * @return void
	 */
	private function save_log() {
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