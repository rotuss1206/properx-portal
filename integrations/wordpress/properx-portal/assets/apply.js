/*
 * The application form (/apply): the order summary for "Just me" and the button's words ("Pay $173.90 and
 * join ProPerX Alliance" or "Continue to agency enrollment"), worked out as the form charges it: the plan,
 * plus 2.9% while "Credit card" is chosen. Settings come from window.ppxApply (includes/apply-form.php).
 */
(function ($) {
	'use strict';

	var config = window.ppxApply;

	if (!config || !config.formId) {
		return;
	}

	function formElement() {
		return document.getElementById('gform_' + config.formId);
	}

	function checkedValue(form, fieldId) {
		var checked = form.querySelector('input[name="input_' + fieldId + '"]:checked');

		return checked ? checked.value : '';
	}

	function money(amount) {
		return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function setText(element, text) {
		if (element) {
			element.textContent = text;
		}
	}

	function refresh() {
		var form = formElement();

		if (!form) {
			return;
		}

		// As the form charges it: the plan, plus the surcharge field (2.9% of the plan, 2 decimals) only
		// while "Credit card" is chosen.
		var agency = checkedValue(form, config.joiningField) === config.agencyValue;
		var credit = checkedValue(form, config.paymentField) === 'credit';
		var surcharge = credit ? Math.round(config.planPrice * config.surchargePercent) / 100 : 0;
		var amount = Math.round((config.planPrice + surcharge) * 100) / 100;
		var button = form.querySelector('#gform_submit_button_' + config.formId) || form.querySelector('[type="submit"]');
		var words = agency ? config.agencyText : config.payText.replace('{amount}', money(amount));

		if (button) {
			if (button.tagName === 'INPUT') {
				button.value = words;
			} else {
				button.textContent = words;
			}
		}

		var summary = form.querySelector('[data-ppx-summary]');

		if (summary) {
			setText(summary.querySelector('[data-ppx-summary-plan]'), money(config.planPrice));
			setText(summary.querySelector('[data-ppx-summary-surcharge]'), money(surcharge));
			setText(summary.querySelector('[data-ppx-summary-total]'), money(amount));

			var row = summary.querySelector('[data-ppx-summary-surcharge-row]');

			if (row) {
				row.hidden = surcharge <= 0;
			}
		}
	}

	$(document).on('change', '#gform_' + config.formId + ' input', refresh);
	$(document).on('gform_post_render', function (event, formId) {
		if (parseInt(formId, 10) === config.formId) {
			refresh();
		}
	});
	$(refresh);
})(jQuery);
