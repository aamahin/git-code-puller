/**
 * Git Code Update - Admin JavaScript
 *
 * Handles repeatable repository fields, per-repo pull buttons, and AJAX requests.
 *
 * @package Git_Code_Update
 * @since   1.0.0
 */

(function ($) {
	'use strict';

	// DOM Ready.
	$(function () {
		const $container = $('#git-code-update-repos-container');
		const $addBtn = $('#git-code-update-add-repo');
		const $globalStatus = $('#git-code-update-status');
		const $globalStatusMessage = $('#git-code-update-status-message');
		const $logSection = $('#git-code-update-log-section');
		const $logOutput = $('#git-code-update-log-output');

		/**
		 * Get the next index for a new repo row.
		 *
		 * @returns {number} Next index value.
		 */
		function getNextIndex() {
			const rows = $container.find('.git-code-update-repo-row');
			if (rows.length === 0) {
				return 0;
			}
			let maxIndex = -1;
			rows.each(function () {
				const idx = parseInt($(this).attr('data-index'), 10);
				if (idx > maxIndex) {
					maxIndex = idx;
				}
			});
			return maxIndex + 1;
		}

		/**
		 * Re-index all repo rows (update titles).
		 */
		function reindexRows() {
			$container.find('.git-code-update-repo-row').each(function (i) {
				$(this)
					.find('.git-code-update-repo-title')
					.text(gitCodeUpdate.strings.repoLabel + (i + 1));
			});
		}

		/**
		 * Add a new repository row.
		 */
		$addBtn.on('click', function (e) {
			e.preventDefault();

			const template = $('#git-code-update-repo-template').html();
			const nextIndex = getNextIndex();
			const nextDisplay = $container.find('.git-code-update-repo-row').length + 1;

			const newRow = template
				.replace(/\{\{INDEX\}\}/g, nextIndex)
				.replace(/\{\{INDEX_PLUS_1\}\}/g, nextDisplay);

			$container.append(newRow);
			reindexRows();

			// Scroll to the new row.
			const $added = $container.find('.git-code-update-repo-row').last();
			$('html, body').animate(
				{
					scrollTop: $added.offset().top - 50,
				},
				300
			);
		});

		/**
		 * Remove a repository row.
		 */
		$container.on('click', '.git-code-update-remove-repo', function (e) {
			e.preventDefault();

			const $row = $(this).closest('.git-code-update-repo-row');
			const rowCount = $container.find('.git-code-update-repo-row').length;

			if (rowCount <= 1) {
				// Don't remove the last row, just clear its fields instead.
				$row.find('input').val('');
				$row.find('input[name*="[branch_name]"]').val('main');
				$row
					.find('.git-code-update-single-status')
					.text('')
					.removeClass('success error loading');
				$row.find('.git-code-update-last-pull-row').remove();
				return;
			}

			$row.fadeOut(200, function () {
				$(this).remove();
				reindexRows();
			});
		});

		/**
		 * Show global status message.
		 *
		 * @param {string} message - The message to display.
		 * @param {string} type    - The message type (success, error, info).
		 */
		function showGlobalStatus(message, type) {
			$globalStatus
				.removeClass('success error info')
				.addClass(type)
				.show();
			$globalStatusMessage.html(message);
		}

		/**
		 * Hide global status message.
		 */
		function hideGlobalStatus() {
			$globalStatus.hide();
			$globalStatusMessage.html('');
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
				$logOutput.scrollTop($logOutput[0].scrollHeight);
			}
		}

		/**
		 * Handle fetch branches button click.
		 */
		$container.on('click', '.git-code-update-fetch-branches-btn', function (e) {
			e.preventDefault();

			const $btn = $(this);
			const repoIndex = $btn.attr('data-index');
			const $row = $btn.closest('.git-code-update-repo-row');
			const $status = $row.find('.git-code-update-fetch-status');
			const $datalist = $row.find('.git-code-update-branch-input').attr('list');

			// Set loading state.
			$btn.addClass('loading').prop('disabled', true);
			$status
				.text('')
				.removeClass('success error')
				.addClass('loading')
				.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span> ' + gitCodeUpdate.strings.loadingBranches);

			hideGlobalStatus();

			// Send AJAX request.
			$.ajax({
				url: gitCodeUpdate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'git_code_update_fetch_branches',
					nonce: gitCodeUpdate.nonce,
					repo_index: repoIndex,
				},
				success: function (response) {
					if (response.success && response.data.branches) {
						const $list = $('#' + $datalist);
						$list.empty();

						response.data.branches.forEach(function (branch) {
							$list.append('<option value="' + branch + '"></option>');
						});

						$status
							.removeClass('loading')
							.addClass('success')
							.text('✓ ' + gitCodeUpdate.strings.branchesLoaded);
					} else {
						$status
							.removeClass('loading')
							.addClass('error')
							.text('✗ ' + (response.data.message || gitCodeUpdate.strings.error));
					}
				},
				error: function (xhr, statusText, error) {
					let errorMessage = gitCodeUpdate.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data) {
						errorMessage = xhr.responseJSON.data.message || errorMessage;
					} else if (error) {
						errorMessage += ' (' + error + ')';
					}

					$status.removeClass('loading').addClass('error').text('✗ ' + errorMessage);
				},
				complete: function () {
					$btn.removeClass('loading').prop('disabled', false);

					// Clear loading spinner from status after a brief moment.
					setTimeout(function () {
						$status.find('.spinner').remove();
					}, 500);
				},
			});
		});

		/**
		 * Handle per-repo pull button click.
		 */
		$container.on('click', '.git-code-update-pull-single-btn', function (e) {
			e.preventDefault();

			const $btn = $(this);
			const repoIndex = $btn.attr('data-index');
			const $row = $btn.closest('.git-code-update-repo-row');
			const $status = $row.find('.git-code-update-single-status');

			// Confirm action.
			if (!confirm(gitCodeUpdate.strings.confirmPull)) {
				return;
			}

			// Set loading state.
			$btn.addClass('loading').prop('disabled', true);
			$status
				.text('')
				.removeClass('success error')
				.addClass('loading')
				.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span> ' + gitCodeUpdate.strings.pulling);

			hideGlobalStatus();
			$logSection.hide();
			$logOutput.text('');

			// Send AJAX request.
			$.ajax({
				url: gitCodeUpdate.ajaxUrl,
				type: 'POST',
				data: {
					action: 'git_code_update_pull',
					nonce: gitCodeUpdate.nonce,
					repo_index: repoIndex,
				},
				success: function (response) {
					if (response.success) {
						$status
							.removeClass('loading')
							.addClass('success')
							.text('✓ ' + response.data.message);

						showGlobalStatus('✓ ' + response.data.message, 'success');
						displayLog(response.data.log);

						// Update last pull timestamp for this repo.
						if (response.data.timestamp) {
							const $lastPullRow = $row.find('.git-code-update-last-pull-row');
							if ($lastPullRow.length > 0) {
								$lastPullRow
									.find('.git-code-update-last-pull-time')
									.text(response.data.timestamp);
							} else {
								// Insert last pull row before the pull button row.
								const lastPullHtml =
									'<p class="git-code-update-field-row git-code-update-last-pull-row">' +
									'<strong>' + 'Last Pull:' + '</strong> ' +
									'<span class="git-code-update-last-pull-time" data-index="' + repoIndex + '">' +
									response.data.timestamp +
									'</span></p>';
								$btn.closest('.git-code-update-field-row').before(lastPullHtml);
							}
						}
					} else {
						$status
							.removeClass('loading')
							.addClass('error')
							.text('✗ ' + (response.data.message || gitCodeUpdate.strings.error));

						showGlobalStatus(
							'✗ ' + (response.data.message || gitCodeUpdate.strings.error),
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

					$status.removeClass('loading').addClass('error').text('✗ ' + errorMessage);
					showGlobalStatus('✗ ' + errorMessage, 'error');
				},
				complete: function () {
					$btn.removeClass('loading').prop('disabled', false);

					// Clear loading spinner from status after a brief moment.
					setTimeout(function () {
						$status.find('.spinner').remove();
					}, 500);
				},
			});
		});
	});
})(jQuery);