'use strict';

/**
 * Confirms destructive-ish update buttons. Inline handlers are blocked by
 * FreshRSS' CSP, so the binding happens here.
 */
document.addEventListener('DOMContentLoaded', function () {
	var buttons = document.querySelectorAll('.extension-updater .eu-update-form button[data-confirm]');
	Array.prototype.forEach.call(buttons, function (button) {
		button.addEventListener('click', function (event) {
			if (!window.confirm(button.getAttribute('data-confirm'))) {
				event.preventDefault();
			}
		});
	});
});
