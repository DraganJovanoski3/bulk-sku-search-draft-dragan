(function ($) {
	'use strict';

	var totals = {
		succeeded: 0,
		failed: 0,
		skipped: 0,
	};

	function resetTotals() {
		totals.succeeded = 0;
		totals.failed = 0;
		totals.skipped = 0;
	}

	function updateProgress($el, offset, total) {
		$el.text(
			bssddAdmin.i18n.drafting +
				' ' +
				Math.min(offset, total) +
				' / ' +
				total
		);
	}

	function showComplete($el, $btn) {
		var message =
			bssddAdmin.i18n.complete +
			' ' +
			totals.succeeded +
			' drafted';

		if (totals.skipped > 0) {
			message += ', ' + totals.skipped + ' skipped';
		}

		if (totals.failed > 0) {
			message += ', ' + totals.failed + ' failed';
		}

		$el.removeClass('is-busy').text(message);
		$btn.prop('disabled', true);

		window.setTimeout(function () {
			window.location.reload();
		}, 1500);
	}

	function runBatch($btn, $progress, offset, total) {
		$.post(bssddAdmin.ajaxUrl, {
			action: 'bssdd_draft_batch',
			nonce: bssddAdmin.nonce,
			offset: offset,
		})
			.done(function (response) {
				if (!response || !response.success || !response.data) {
					$progress.removeClass('is-busy').text(bssddAdmin.i18n.error);
					$btn.prop('disabled', false);
					return;
				}

				var data = response.data;
				totals.succeeded += data.succeeded || 0;
				totals.failed += data.failed || 0;
				totals.skipped += data.skipped || 0;

				updateProgress($progress, data.offset || 0, total);

				if (data.done) {
					showComplete($progress, $btn);
					return;
				}

				runBatch($btn, $progress, data.offset || 0, total);
			})
			.fail(function () {
				$progress.removeClass('is-busy').text(bssddAdmin.i18n.error);
				$btn.prop('disabled', false);
			});
	}

	function showSkuEditor($cell) {
		$cell.find('.bssdd-sku-display').attr('hidden', true);
		$cell.find('.bssdd-sku-editor').removeAttr('hidden');
		$cell.find('.bssdd-sku-input').trigger('focus').select();
		$cell.find('.bssdd-sku-feedback').text('');
	}

	function hideSkuEditor($cell, sku) {
		if (typeof sku === 'string') {
			$cell.find('.bssdd-sku-value').text(sku);
			$cell.find('.bssdd-sku-input').val(sku);
		}

		$cell.find('.bssdd-sku-editor').attr('hidden', true);
		$cell.find('.bssdd-sku-display').removeAttr('hidden');
		$cell.find('.bssdd-sku-feedback').text('');
	}

	function setSkuFeedback($cell, message, isError) {
		var $feedback = $cell.find('.bssdd-sku-feedback');
		$feedback.text(message);
		$feedback.toggleClass('is-error', !!isError);
	}

	function saveSku($row) {
		var $cell = $row.find('.bssdd-sku-cell');
		var $input = $cell.find('.bssdd-sku-input');
		var $saveBtn = $cell.find('.bssdd-sku-save-btn');
		var productId = parseInt($row.data('product-id'), 10) || 0;
		var newSku = $.trim($input.val());

		if (!productId) {
			return;
		}

		$saveBtn.prop('disabled', true);
		setSkuFeedback($cell, bssddAdmin.i18n.savingSku, false);

		$.post(bssddAdmin.ajaxUrl, {
			action: 'bssdd_update_sku',
			nonce: bssddAdmin.skuNonce,
			product_id: productId,
			sku: newSku,
		})
			.done(function (response) {
				if (!response || !response.success || !response.data) {
					var errMsg =
						(response && response.data && response.data.message) ||
						bssddAdmin.i18n.skuFailed;
					setSkuFeedback($cell, errMsg, true);
					$saveBtn.prop('disabled', false);
					return;
				}

				hideSkuEditor($cell, response.data.sku || newSku);
			})
			.fail(function (xhr) {
				var errMsg = bssddAdmin.i18n.skuFailed;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					errMsg = xhr.responseJSON.data.message;
				}
				setSkuFeedback($cell, errMsg, true);
				$saveBtn.prop('disabled', false);
			});
	}

	function initDraftButton() {
		var $btn = $('#bssdd-draft-btn');
		var $progress = $('#bssdd-draft-progress');

		if (!$btn.length) {
			return;
		}

		$btn.on('click', function () {
			var count = parseInt($btn.data('count'), 10) || 0;

			if (count < 1) {
				return;
			}

			var message = bssddAdmin.i18n.confirmDraft.replace('%d', String(count));
			if (!window.confirm(message)) {
				return;
			}

			resetTotals();
			$btn.prop('disabled', true);
			$progress.addClass('is-busy').text(bssddAdmin.i18n.drafting);
			runBatch($btn, $progress, 0, count);
		});
	}

	function initSkuEditors() {
		var $table = $('.bssdd-results-table');

		if (!$table.length) {
			return;
		}

		$table.on('click', '.bssdd-sku-edit-btn', function () {
			showSkuEditor($(this).closest('.bssdd-sku-cell'));
		});

		$table.on('click', '.bssdd-sku-cancel-btn', function () {
			var $cell = $(this).closest('.bssdd-sku-cell');
			var originalSku = $.trim($cell.find('.bssdd-sku-value').text());
			hideSkuEditor($cell, originalSku);
		});

		$table.on('click', '.bssdd-sku-save-btn', function () {
			saveSku($(this).closest('tr'));
		});

		$table.on('keydown', '.bssdd-sku-input', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				saveSku($(this).closest('tr'));
			}

			if (event.key === 'Escape') {
				event.preventDefault();
				var $cell = $(this).closest('.bssdd-sku-cell');
				hideSkuEditor($cell, $.trim($cell.find('.bssdd-sku-value').text()));
			}
		});
	}

	$(function () {
		initDraftButton();
		initSkuEditors();
	});
})(jQuery);
