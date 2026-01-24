/**
 * Login Form Component
 *
 * Handles login form submission with 2FA support
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';
import { sendAjaxRequest } from '../utils/ajax';
import { hideFormFields, show2FAField } from '../utils/dom';
import { showError, removeError } from '../utils/notification';

let isSubmitting = false;
let loginForm = null;
let submitHandler = null;

/**
 * Initialize the login form
 *
 * @param {HTMLFormElement} formElement - The login form element
 */
export function initLoginForm(formElement) {
	loginForm = formElement;
	isSubmitting = false;
	
	submitHandler = handleSubmit;
	loginForm.addEventListener('submit', submitHandler);
}

/**
 * Handle form submission
 *
 * @param {Event} event - The submit event
 */
async function handleSubmit(event) {
	event.preventDefault();

	if (isSubmitting) {
		return;
	}

	const codeField = document.getElementById('brain2fa_code');
	const code = codeField ? codeField.value : '';

	// If 2FA code is present, submit the form natively
	if (code !== '') {
		submitForm();
		return;
	}

	// Otherwise, validate credentials first
	await validateCredentials();
}

/**
 * Validate user credentials via AJAX
 */
async function validateCredentials() {
	const username = document.getElementById('user_login')?.value;
	const password = document.getElementById('user_pass')?.value;

	if (!username || !password) {
		showError(__('Please enter your username and password.', 'brain2fa'));
		return;
	}

	isSubmitting = true;
	removeError();

	const data = {
		action: 'brain2fa_login',
		nonce: window.Brain2FA?.nonce || '',
		username,
		password,
	};

	try {
		const response = await sendAjaxRequest(data);

		if (!response.success) {
			handleError(response.data);
			return;
		}

		if (response.data.login === 1) {
			if (response.data.requires_2fa) {
				handle2FA(response.data.method);
			} else {
				submitForm();
			}
		}
	} catch (error) {
		showError(__('An error occurred. Please try again.', 'brain2fa'));
	} finally {
		isSubmitting = false;
	}
}

/**
 * Handle error response
 *
 * @param {Object} data - Error data
 */
function handleError(data) {
	const message = 
		data.message || 
		data.error || 
		__('An unknown error occurred. Please try again.', 'brain2fa');

	showError(message);

	if (data.reset) {
		resetForm();
	}
}

/**
 * Handle 2FA requirement
 *
 * @param {string} method - 2FA method (e.g., 'email', 'app')
 */
function handle2FA(method) {
	hideFormFields();
	show2FAField(loginForm, method);
}

/**
 * Submit the form natively
 */
function submitForm() {
	// Remove event listener to avoid recursion
	if (loginForm && submitHandler) {
		loginForm.removeEventListener('submit', submitHandler);
		loginForm.submit();
	}
}

/**
 * Reset form fields
 */
function resetForm() {
	const userLogin = document.getElementById('user_login');
	const userPass = document.getElementById('user_pass');
	const rememberMe = document.getElementById('rememberme');

	if (userPass) {
		userPass.value = '';
	}
	if (userLogin) {
		userLogin.value = '';
	}
	if (rememberMe) {
		rememberMe.checked = false;
	}
}

export default initLoginForm;
