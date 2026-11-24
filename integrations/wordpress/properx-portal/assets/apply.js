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

/*
 * The license copy control (a button and the file's name in one box; the file input stays, visually
 * hidden and focusable); whole cards clickable; the "Payment" cards choosing the Payment Element's type;
 * the Payment Element's place while Stripe builds it again after "My agency" and back to "Just me": its
 * box keeps its height (the description below stays put) and Stripe's skeleton stays hidden until ready.
 */
(function () {
	'use strict';

	var config = window.ppxApply;

	if (!config || !config.formId) {
		return;
	}

	// The license copy.
	function fileMessage(input, box) {
		var file = input.files && input.files[0];
		var types = String(box.getAttribute('data-types') || '').split(',').map(function (type) { return type.trim().toLowerCase(); }).filter(Boolean);
		var maxBytes = parseInt(box.getAttribute('data-max-bytes'), 10) || 0;

		if (!file) {
			return '';
		}

		var extension = file.name.split('.').pop().toLowerCase();

		if (types.length && types.indexOf(extension) === -1) {
			return 'Choose a PDF, JPG or PNG file.';
		}

		if (maxBytes && file.size > maxBytes) {
			return 'This file is larger than ' + Math.round(maxBytes / 1048576) + ' MB. Choose a smaller one.';
		}

		return '';
	}

	document.addEventListener('change', function (event) {
		var input = event.target;
		var box = input && input.closest ? input.closest('[data-ppx-file]') : null;

		if (!box || input.type !== 'file') {
			return;
		}

		var name = box.querySelector('[data-ppx-file-name]');
		var error = box.querySelector('[data-ppx-file-error]');
		var message = fileMessage(input, box);

		if (message) {
			input.value = '';
		}

		var file = input.files && input.files[0];

		name.textContent = file ? file.name : 'No file chosen';
		error.textContent = message;
		box.classList.toggle('is-chosen', !!file);
		box.classList.toggle('is-invalid', !!message);
		input.setAttribute('aria-invalid', message ? 'true' : 'false');
	});

	// The whole card of "Who's joining?" and "Payment" chooses it, not only its words.
	document.addEventListener('click', function (event) {
		var card = event.target && event.target.closest ? event.target.closest('.ppx-joining .gchoice, .ppx-payment .gchoice') : null;

		if (!card || event.target.closest('input, label')) {
			return;
		}

		var input = card.querySelector('input');

		if (input && !input.checked && !input.disabled) {
			input.click();
		}

		if (input) {
			input.focus({ preventScroll: true });
		}
	});

	// Our "Payment" cards choose what Stripe's Payment Element asks for: a card for credit and debit, a bank
	// account for ACH. Stripe then shows no tabs of its own. The plugin asks the server for the same type
	// when the payment is made (properx_portal_apply_payment_methods).
	function paymentTypes() {
		var form = document.getElementById('gform_' + config.formId);
		var chosen = form ? form.querySelector('input[name="input_' + config.paymentField + '"]:checked') : null;

		if (!chosen) {
			return null;
		}

		return chosen.value === 'ach' ? ['us_bank_account'] : ['card'];
	}

	// The Payment Element Gravity Forms Stripe shows now, its group and the type it was made for. Gravity
	// Forms Stripe destroys and creates it again on its own (a changed amount, "My agency" and back).
	var active = { group: null, element: null, types: '' };

	// Gravity Forms Stripe creates the Payment Element without options and has no filter for them, so
	// Stripe's own factory is wrapped (this script runs before Gravity Forms Stripe's): the element gets the
	// chosen payment type and Stripe Link stays off (wallets.link = "never"). Stripe's objects expose these
	// methods as getters, hence defineProperty. Should Stripe's objects change, the form works as before.
	function wrapMethod(object, name, wrap) {
		var original = object[name];

		if (typeof original !== 'function') {
			return;
		}

		Object.defineProperty(object, name, { configurable: true, writable: true, value: wrap(original) });
	}

	function wrapStripe() {
		var original = window.Stripe;

		if (typeof original !== 'function' || original.ppxWrapped) {
			return;
		}

		var wrapped = function () {
			var instance = original.apply(this, arguments);

			try {
				wrapMethod(instance, 'elements', function (elements) {
					return function (options) {
						var types = paymentTypes();

						options = Object.assign({}, options);

						if (types && options.mode) {
							options.paymentMethodTypes = types;
						}

						var group = elements.call(instance, options);

						var made = { group: group, element: null, types: String(options.paymentMethodTypes || '') };

						wrapMethod(group, 'create', function (create) {
							return function (type, elementOptions) {
								if (type === 'payment') {
									elementOptions = Object.assign({}, elementOptions);
									elementOptions.wallets = Object.assign({}, elementOptions.wallets, { link: 'never' });
								}

								var element = create.call(group, type, elementOptions);

								if (type === 'payment' && made.types) {
									made.element = element;
									active = made;
									wrapMethod(element, 'destroy', function (destroy) {
										return function () {
											if (active === made) {
												active = { group: null, element: null, types: '' };
											}

											return destroy.apply(element, arguments);
										};
									});
								}

								return element;
							};
						});

						return group;
					};
				});
			} catch (e) {
				// Left as Stripe made it.
			}

			return instance;
		};

		Object.keys(original).forEach(function (key) {
			wrapped[key] = original[key];
		});
		wrapped.ppxWrapped = true;
		window.Stripe = wrapped;
	}

	wrapStripe();

	document.addEventListener('change', function (event) {
		var input = event.target;

		if (!input || input.name !== 'input_' + config.paymentField) {
			return;
		}

		// After Gravity Forms' own handlers: should they make the element again, it is made for the new
		// type already; otherwise the live one is told.
		setTimeout(function () {
			var types = paymentTypes();

			if (!types || !active.group || !active.element || active.types === String(types)) {
				return;
			}

			try {
				active.group.update({ paymentMethodTypes: types });
				active.types = String(types);
			} catch (e) {
				// An older Stripe.js: the element keeps the type it was made for.
			}
		}, 0);
	});

	// The Payment Element is ready once Stripe's loader (its skeleton, on the page) is gone and its frame
	// has its height; Stripe changes these nodes itself, so a watcher on the field sees it.
	function paymentIsReady(field) {
		var frame = field.querySelector('.__PrivateStripeElement iframe');

		return !!frame && !field.querySelector('.__PrivateStripeElementLoader') && frame.offsetHeight > 40;
	}

	var watcher = null;
	var lastHeight = 0;
	var timer = null;

	function paymentField() {
		var form = document.getElementById('gform_' + config.formId);

		return form ? form.querySelector('.gfield--type-stripe_creditcard') : null;
	}

	// The element's own box: its height is kept, so the field's description below it stays in place.
	function paymentBox(field) {
		return field ? field.querySelector('.ginput_container') : null;
	}

	function watchPayment(field) {
		if (watcher || !window.MutationObserver) {
			return;
		}

		// The last height the element had when shown and ready: the space kept while it is built again.
		if (window.ResizeObserver) {
			new ResizeObserver(function () {
				var box = paymentBox(field);

				if (box && !field.classList.contains('ppx-payment-loading') && box.offsetHeight > 40) {
					lastHeight = box.offsetHeight;
				}
			}).observe(paymentBox(field));
		}

		watcher = new MutationObserver(function () {
			if (field.classList.contains('ppx-payment-loading') && paymentIsReady(field)) {
				paymentReady();
			}
		});
		watcher.observe(field, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
	}

	function waitForPayment() {
		var field = paymentField();
		var box = paymentBox(field);

		if (!box) {
			return;
		}

		// Exactly the last height: Gravity Forms Stripe makes the element many times over meanwhile.
		box.style.height = (lastHeight || 160) + 'px';
		box.style.overflow = 'hidden';
		field.classList.add('ppx-payment-loading');
		watchPayment(field);
		clearTimeout(timer);

		if (paymentIsReady(field)) {
			paymentReady();
		}

		// Should the element never look ready, the form shows anyway.
		timer = setTimeout(function () {
			paymentReady(true);
		}, 8000);
	}

	var settle = null;

	function release(field, box) {
		clearTimeout(timer);
		clearTimeout(settle);
		field.classList.remove('ppx-payment-loading');
		box.style.height = '';
		box.style.overflow = '';
		lastHeight = box.offsetHeight || lastHeight;
	}

	// Ready, and still ready a moment later: Gravity Forms Stripe makes the element several times in a
	// row, each briefly ready. The fallback timer (force) shows it whatever its state.
	function paymentReady(force) {
		var field = paymentField();
		var box = paymentBox(field);

		if (!box || !field.classList.contains('ppx-payment-loading')) {
			return;
		}

		if (force === true) {
			release(field, box);

			return;
		}

		clearTimeout(settle);
		settle = setTimeout(function () {
			if (paymentIsReady(field)) {
				release(field, box);
			}
		}, 400);
	}

	document.addEventListener('change', function (event) {
		var input = event.target;

		if (!input || input.name !== 'input_' + config.joiningField) {
			return;
		}

		var field = paymentField();
		var box = paymentBox(field);

		if (input.value === config.agencyValue) {
			clearTimeout(timer);

			if (field && field.classList.contains('ppx-payment-loading')) {
				// Back to "My agency" while Stripe was still building: the field goes with its section.
				field.classList.remove('ppx-payment-loading');
				box.style.height = '';
				box.style.overflow = '';
			}
		} else {
			waitForPayment();
		}
	});

	// A license copy Gravity Forms kept after a failed submission (it shows it in its own preview list): its
	// name goes into our box, so the person sees the file is still there. Choosing another replaces it.
	function initFileControls() {
		document.querySelectorAll('#gform_' + config.formId + ' [data-ppx-file]').forEach(function (box) {
			var field = box.closest('.gfield');
			var input = box.querySelector('input[type="file"]');
			var kept = field ? field.querySelector('.ginput_preview .gfield_fileupload_filename') : null;

			if (!kept || (input && input.files && input.files.length)) {
				return;
			}

			box.querySelector('[data-ppx-file-name]').textContent = kept.textContent.trim();
			box.classList.add('is-chosen');
			field.classList.add('ppx-file-kept');
		});
	}

	// The form as drawn now: on the first load, and each time Gravity Forms draws it again (a failed
	// submission comes back through admin-ajax.php with new nodes).
	function onRender() {
		var form = document.getElementById('gform_' + config.formId);
		var joining = form ? form.querySelector('input[name="input_' + config.joiningField + '"]:checked') : null;

		initFileControls();

		if (watcher) {
			watcher.disconnect();
			watcher = null;
		}

		if (!joining || joining.value !== config.agencyValue) {
			waitForPayment();
		}
	}

	document.addEventListener('DOMContentLoaded', onRender);

	if (window.jQuery) {
		window.jQuery(document).on('gform_post_render', function (event, formId) {
			if (parseInt(formId, 10) === config.formId) {
				onRender();
			}
		});
	}
})();
