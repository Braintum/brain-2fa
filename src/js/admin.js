/**
 * Brain 2FA Admin JavaScript
 *
 * Handles admin settings page functionality
 *
 * @package Brain2FA
 * @since 1.0.0
 */

import { __ } from '@wordpress/i18n';
import { getElement, getAllElements } from './utils/dom';

/**
 * Initialize admin functionality
 */
function initAdmin() {
	bindEvents();
	conditionalFields();
}

/**
 * Bind events
 */
function bindEvents() {
	// Toggle remember device duration field
	const rememberCheckbox = getElement('input[name="brain2fa_settings[remember_device]"]');
	if (rememberCheckbox) {
		rememberCheckbox.addEventListener('change', (e) => {
			toggleRememberDuration(e.target);
		});
	}

	// Force 2FA warning
	const forceCheckboxes = getAllElements('input[name="brain2fa_settings[force_2fa_roles][]"]');
	forceCheckboxes.forEach((checkbox) => {
		checkbox.addEventListener('change', () => {
			handleForceWarning();
		});
	});

	// Confirm settings save if force 2FA is enabled
	const form = getElement('form');
	if (form) {
		form.addEventListener('submit', (e) => {
			handleFormSubmit(e);
		});
	}
}

/**
 * Toggle remember device duration field
 *
 * @param {HTMLInputElement} checkbox - The checkbox element
 */
function toggleRememberDuration(checkbox) {
	const td = checkbox.closest('td');
	if (!td) return;

	const durationField = td.querySelector('input[name="brain2fa_settings[remember_duration]"]');
	if (!durationField) return;

	const durationDiv = durationField.closest('div');
	if (durationDiv) {
		if (checkbox.checked) {
			slideDown(durationDiv, 200);
		} else {
			slideUp(durationDiv, 200);
		}
	}
}

/**
 * Handle force 2FA warning display
 */
function handleForceWarning() {
	const checkedBoxes = getAllElements('input[name="brain2fa_settings[force_2fa_roles][]"]:checked');
	
	if (checkedBoxes.length > 0) {
		showForceWarning();
	} else {
		hideForceWarning();
	}
}

/**
 * Handle form submission
 *
 * @param {Event} e - Submit event
 */
function handleFormSubmit(e) {
	const forceCheckboxes = getAllElements('input[name="brain2fa_settings[force_2fa_roles][]"]:checked');
	
	if (forceCheckboxes.length > 0) {
		const message = __(
			'You are about to require 2FA for selected user roles. Make sure administrators have set up 2FA before proceeding. Continue?',
			'brain2fa'
		);
		
		if (!confirm(message)) {
			e.preventDefault();
			return false;
		}
	}
}

/**
 * Handle conditional field visibility
 */
function conditionalFields() {
	// Show/hide remember device duration on page load
	const rememberCheckbox = getElement('input[name="brain2fa_settings[remember_device]"]');
	if (!rememberCheckbox) return;

	const td = rememberCheckbox.closest('td');
	if (!td) return;

	const durationField = td.querySelector('input[name="brain2fa_settings[remember_duration]"]');
	if (!durationField) return;

	const durationDiv = durationField.closest('div');
	if (durationDiv) {
		durationDiv.style.display = rememberCheckbox.checked ? 'block' : 'none';
	}
}

/**
 * Show force 2FA warning
 */
function showForceWarning() {
	// Check if warning already exists
	if (getElement('.brain2fa-force-warning')) {
		return;
	}

	const warningHtml = `
		<div class="brain2fa-warning notice notice-warning inline" style="margin-top: 10px;">
			<p>
				<span class="dashicons dashicons-warning"></span>
				<strong>${__('Warning:', 'brain2fa')}</strong>
				${__('Users in selected roles will be required to set up 2FA on their next login.', 'brain2fa')}
			</p>
		</div>
	`;

	const firstCheckbox = getElement('input[name="brain2fa_settings[force_2fa_roles][]"]');
	if (!firstCheckbox) return;

	const fieldset = firstCheckbox.closest('fieldset');
	if (!fieldset) return;

	const wrapper = document.createElement('div');
	wrapper.className = 'brain2fa-force-warning';
	wrapper.innerHTML = warningHtml;

	fieldset.parentNode.insertBefore(wrapper, fieldset.nextSibling);
}

/**
 * Hide force 2FA warning
 */
function hideForceWarning() {
	const warning = getElement('.brain2fa-force-warning');
	if (warning) {
		warning.remove();
	}
}

/**
 * Slide down animation
 *
 * @param {HTMLElement} element - Element to slide down
 * @param {number} duration - Animation duration in ms
 */
function slideDown(element, duration = 200) {
	element.style.removeProperty('display');
	let display = window.getComputedStyle(element).display;
	if (display === 'none') display = 'block';
	element.style.display = display;

	const height = element.offsetHeight;
	element.style.overflow = 'hidden';
	element.style.height = '0';
	element.style.paddingTop = '0';
	element.style.paddingBottom = '0';
	element.style.marginTop = '0';
	element.style.marginBottom = '0';
	element.offsetHeight; // Force reflow

	element.style.transition = `all ${duration}ms ease-in-out`;
	element.style.height = height + 'px';
	element.style.removeProperty('padding-top');
	element.style.removeProperty('padding-bottom');
	element.style.removeProperty('margin-top');
	element.style.removeProperty('margin-bottom');

	setTimeout(() => {
		element.style.removeProperty('height');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
	}, duration);
}

/**
 * Slide up animation
 *
 * @param {HTMLElement} element - Element to slide up
 * @param {number} duration - Animation duration in ms
 */
function slideUp(element, duration = 200) {
	element.style.height = element.offsetHeight + 'px';
	element.offsetHeight; // Force reflow

	element.style.overflow = 'hidden';
	element.style.transition = `all ${duration}ms ease-in-out`;
	element.style.height = '0';
	element.style.paddingTop = '0';
	element.style.paddingBottom = '0';
	element.style.marginTop = '0';
	element.style.marginBottom = '0';

	setTimeout(() => {
		element.style.display = 'none';
		element.style.removeProperty('height');
		element.style.removeProperty('padding-top');
		element.style.removeProperty('padding-bottom');
		element.style.removeProperty('margin-top');
		element.style.removeProperty('margin-bottom');
		element.style.removeProperty('overflow');
		element.style.removeProperty('transition');
	}, duration);
}

/**
 * Initialize on DOM ready
 */
document.addEventListener('DOMContentLoaded', () => {
	// Initialize based on current page
	if (getElement('.brain2fa-settings-wrap')) {
		initAdmin();
	}
});
