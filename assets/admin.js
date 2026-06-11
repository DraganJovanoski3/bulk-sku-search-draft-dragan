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
		clearSkuFeedback($cell);
	}

	function hideSkuEditor($cell, sku) {
		if (typeof sku === 'string') {
			$cell.find('.bssdd-sku-value').text(sku);
			$cell.find('.bssdd-sku-input').val(sku);
		}

		$cell.find('.bssdd-sku-editor').attr('hidden', true);
		$cell.find('.bssdd-sku-display').removeAttr('hidden');
		clearSkuFeedback($cell);
	}

	function setSkuFeedback($cell, message, isError) {
		var $targets = $cell.find('.bssdd-sku-feedback, .bssdd-sku-display-feedback');
		$targets.text(message);
		$targets.toggleClass('is-error', !!isError);
	}

	function clearSkuFeedback($cell) {
		$cell.find('.bssdd-sku-feedback, .bssdd-sku-display-feedback').text('').removeClass('is-error');
	}

	function saveSkuValue($row, newSku, options) {
		options = options || {};
		var $cell = $row.find('.bssdd-sku-cell');
		var $saveBtn = $cell.find('.bssdd-sku-save-btn');
		var $prefixBtn = $cell.find('.bssdd-sku-prefix-zero-btn');
		var productId = parseInt($row.data('product-id'), 10) || 0;

		if (!productId) {
			return;
		}

		$saveBtn.prop('disabled', true);
		$prefixBtn.prop('disabled', true);
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
					$prefixBtn.prop('disabled', false);
					return;
				}

				var savedSku = response.data.sku || newSku;
				$cell.find('.bssdd-sku-input').val(savedSku);
				hideSkuEditor($cell, savedSku);
				clearSkuFeedback($cell);

				if (savedSku.charAt(0) === '0') {
					$prefixBtn.remove();
					$cell.find('.bssdd-sku-target').remove();
				} else {
					$prefixBtn.prop('disabled', false);
				}

				if (options.onSuccess) {
					options.onSuccess(savedSku);
				}
			})
			.fail(function (xhr) {
				var errMsg = bssddAdmin.i18n.skuFailed;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					errMsg = xhr.responseJSON.data.message;
				}
				setSkuFeedback($cell, errMsg, true);
				$saveBtn.prop('disabled', false);
				$prefixBtn.prop('disabled', false);
			});
	}

	function saveSku($row) {
		var $cell = $row.find('.bssdd-sku-cell');
		var newSku = $.trim($cell.find('.bssdd-sku-input').val());
		saveSkuValue($row, newSku);
	}

	function prefixZeroSku($row) {
		var $cell = $row.find('.bssdd-sku-cell');
		var currentSku = $.trim($cell.find('.bssdd-sku-value').text());

		if (!currentSku || currentSku.charAt(0) === '0') {
			return;
		}

		saveSkuValue($row, '0' + currentSku);
	}

	function runPrefixZeroBatch($btn, $progress, offset, total) {
		$.post(bssddAdmin.ajaxUrl, {
			action: 'bssdd_prefix_zero_batch',
			nonce: bssddAdmin.prefixZeroNonce,
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

				$progress.text(
					bssddAdmin.i18n.prefixingZero +
						' ' +
						Math.min(data.offset || 0, total) +
						' / ' +
						total
				);

				if (data.done) {
					var message =
						bssddAdmin.i18n.prefixZeroComplete +
						' ' +
						totals.succeeded +
						' updated';

					if (totals.skipped > 0) {
						message += ', ' + totals.skipped + ' skipped';
					}

					if (totals.failed > 0) {
						message += ', ' + totals.failed + ' failed';
					}

					$progress.removeClass('is-busy').text(message);
					$btn.prop('disabled', true);

					window.setTimeout(function () {
						window.location.reload();
					}, 1500);
					return;
				}

				runPrefixZeroBatch($btn, $progress, data.offset || 0, total);
			})
			.fail(function () {
				$progress.removeClass('is-busy').text(bssddAdmin.i18n.error);
				$btn.prop('disabled', false);
			});
	}

	function initPrefixZeroButton() {
		var $btn = $('#bssdd-prefix-zero-btn');
		var $progress = $('#bssdd-prefix-zero-progress');

		if (!$btn.length) {
			return;
		}

		$btn.on('click', function () {
			var count = parseInt($btn.data('count'), 10) || 0;

			if (count < 1) {
				return;
			}

			var message = bssddAdmin.i18n.confirmPrefixZero.replace('%d', String(count));
			if (!window.confirm(message)) {
				return;
			}

			resetTotals();
			$btn.prop('disabled', true);
			$progress.addClass('is-busy').text(bssddAdmin.i18n.prefixingZero);
			runPrefixZeroBatch($btn, $progress, 0, count);
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

		$table.on('click', '.bssdd-sku-prefix-zero-btn', function () {
			prefixZeroSku($(this).closest('tr'));
		});

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
		initPrefixZeroButton();
		initSkuEditors();
	});
})(jQuery);
