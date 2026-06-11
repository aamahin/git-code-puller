/**
 * Git Code Update - Admin JavaScript
 *
 * Handles the pull code button click, AJAX requests, and UI updates.
 *
 * @package Git_Code_Update
 * @since   1.0.0
 */

(function ($) {
	'use strict';

	// DOM Ready.
	$(function () {
		const $pullBtn = $('#git-code-update-pull-btn');
		const $status = $('#git-code-update-status');
		const $statusMessage = $('#git-code-update-status-message');
		const $logSection = $('#git-code-update-log-section');
		const $logOutput = $('#git-code-update-log-output');

		/**
		 * Show status message.
		 *
		 * @param {string} message - The message to display.
		 * @param {string} type    - The message type (success, error, info).
		 */
		function showStatus(message, type) {
			$status
				.removeClass('success error info')
				.addClass(type)
				.show();

			$statusMessage.html(message);
		}

		/**
		 * Hide status message.
		 */
		function hideStatus() {
			$status.hide();
			$statusMessage.html('');
		}

		/**
		 * Display log entries.
		 *
		 * @param {Array} logEntries - Array of log entry strings.
		 */
		function displayLog(logEntries) {
			if (logEntries && logEntries.length > 0) {
				$logSection.show();
				$logOutput.text(logEntries.join('\n'));
				// Auto-scroll to bottom.
				$logOutput.scrollTop($logOutput[0].scrollHeight);
			}
		}

		/**
		 * Set button loading state.
		 *
		 * @param {boolean} loading - Whether the button is in loading state.
		 */
		function setButtonLoading(loading) {
			if (loading) {
				$pullBtn
					.addClass('loading')
					.prop('disabled', true)
					.find('.dashicons')
					.addClass('dashicons-update-alt')
					.removeClass('dashicons-update');
			} else {
				$pullBtn
					.removeClass('loading')
					.prop('disabled', false)
					.find('.dashicons')
					.removeClass('dashicons-update-alt')
					.addClass('dashicons-update');
			}
		}

		// Pull button click handler.
		$pullBtn.on('click', function (e) {
			e.preventDefault();

			// Confirm action.
			if (!confirm(gitCodeUpdate.strings.confirmPull)) {
				return;
			}

			// Set loading state.
			setButtonLoading(true);
			hideStatus();
			$logSection.hide();
			$logOutput.text('');

			// Show loading status.
			showStatus(
				'<span class="spinner is-active"></span> ' +
					gitCodeUpdate.strings.pulling,
				'info'
			);

			// Send AJAX request.
			$.ajax({
				url: gitCodeUpdate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'git_code_update_pull',
					nonce: gitCodeUpdate.nonce,
				},
				success: function (response) {
					if (response.success) {
						showStatus('✓ ' + response.data.message, 'success');
						displayLog(response.data.log);

						// Update last pull timestamp if displayed.
						if (response.data.timestamp) {
							$('.git-code-update-last-pull').html(
								'<strong>Last Pull:</strong> ' +
									response.data.timestamp
							);
						}
					} else {
						showStatus(
							'✗ ' +
								(response.data.message ||
									gitCodeUpdate.strings.error),
							'error'
						);
						if (response.data.log) {
							displayLog(response.data.log);
						}
					}
				},
				error: function (xhr, statusText, error) {
					let errorMessage = gitCodeUpdate.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data) {
						errorMessage = xhr.responseJSON.data.message || errorMessage;
					} else if (error) {
						errorMessage += ' (' + error + ')';
					}

					showStatus('✗ ' + errorMessage, 'error');
				},
				complete: function () {
					setButtonLoading(false);
				},
			});
		});
	});
})(jQuery);
