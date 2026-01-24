/**
 * DOM manipulation utilities
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';

/**
 * Hide login form fields (username, password, remember me)
 */
export function hideFormFields() {
	const usernameField = document.getElementById('user_login');
	const passwordField = document.getElementById('user_pass');
	const rememberField = document.getElementById('rememberme');

	if (usernameField) {
		const usernamePara = usernameField.closest('p');
		if (usernamePara) {
			usernamePara.style.display = 'none';
		}
	}

	if (passwordField) {
		const passwordWrap = passwordField.closest('.user-pass-wrap');
		if (passwordWrap) {
			passwordWrap.style.display = 'none';
		}
	}

	if (rememberField) {
		const rememberWrap = rememberField.closest('.forgetmenot');
		if (rememberWrap) {
			rememberWrap.style.display = 'none';
		}
	}
}

/**
 * Show 2FA code input field
 *
 * @param {HTMLFormElement} form - The login form
 * @param {string} method - 2FA method
 */
export function show2FAField(form, method = 'email') {
	// Check if field already exists
	if (document.getElementById('brain2fa_code')) {
		return;
	}

	const submitButton = form.querySelector('p.submit');
	if (!submitButton) {
		return;
	}

	// Create wrapper paragraph
	const wrapper = document.createElement('p');

	// Create label
	const label = document.createElement('label');
	label.setAttribute('for', 'brain2fa_code');

	// Label text
	const labelText = document.createElement('span');
	labelText.textContent = __('Verification Code', 'brain2fa');
	label.appendChild(labelText);

	// Line break
	label.appendChild(document.createElement('br'));

	// Create input field
	const input = document.createElement('input');
	input.type = 'text';
	input.id = 'brain2fa_code';
	input.name = 'brain2fa_code';
	input.className = 'input';
	input.setAttribute('inputmode', 'numeric');
	input.setAttribute('autocomplete', 'one-time-code');
	input.required = true;
	input.setAttribute('aria-label', __('Enter your verification code', 'brain2fa'));
	input.setAttribute('placeholder', __('Enter code', 'brain2fa'));

	label.appendChild(input);
	wrapper.appendChild(label);

	// Insert before submit button
	submitButton.parentNode.insertBefore(wrapper, submitButton);

	// Focus the input
	input.focus();
}

/**
 * Get element safely
 *
 * @param {string} selector - CSS selector
 * @return {HTMLElement|null} Element or null
 */
export function getElement(selector) {
	return document.querySelector(selector);
}

/**
 * Get all elements
 *
 * @param {string} selector - CSS selector
 * @return {NodeList} NodeList of elements
 */
export function getAllElements(selector) {
	return document.querySelectorAll(selector);
}
