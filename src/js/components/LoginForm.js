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
export function initLoginForm( formElement ) {
	loginForm = formElement;
	isSubmitting = false;

	submitHandler = handleSubmit;
	loginForm.addEventListener( 'submit', submitHandler );
}

/**
 * Handle form submission
 *
 * @param {Event} event - The submit event
 */
async function handleSubmit( event ) {
	event.preventDefault();

	if ( isSubmitting ) {
		return;
	}

	const codeField = document.getElementById( 'brain2fa_code' );
	const code = codeField ? codeField.value : '';

	// If 2FA code is present, submit the form natively
	if ( '' !== code ) {
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
	const username = loginForm.querySelector( '#user_login, #username, [name="log"], [name="username"]' )?.value;
	const password = loginForm.querySelector( '#user_pass, #password, [name="pwd"], [name="password"]' )?.value;
	const rememberMe = loginForm.querySelector( '#rememberme, [name="rememberme"]' );

	if ( ! username || ! password ) {
		showError( __( 'Please enter your username and password.', 'brain2fa' ) );
		return;
	}

	isSubmitting = true;
	removeError();

	const data = {
		action: 'brain2fa_login',
		nonce: window.Brain2FA?.nonce || '',
		username,
		password,
		rememberme: rememberMe?.checked ? 'forever' : ''
	};

	try {
		const response = await sendAjaxRequest( data );

		if ( ! response.success ) {
			handleError( response.data );
			return;
		}

		if ( 1 === response.data.login ) {
			if ( response.data.requires_2fa ) {
				handle2FA( response.data.method );
			} else {
				submitForm();
			}
		}
	} catch ( error ) {
		showError( __( 'An error occurred. Please try again.', 'brain2fa' ) );
	} finally {
		isSubmitting = false;
	}
}

/**
 * Handle error response
 *
 * @param {Object} data - Error data
 */
function handleError( data ) {
	const message =
		data.message ||
		data.error ||
		__( 'An unknown error occurred. Please try again.', 'brain2fa' );

	showError( message, Boolean( data.message || data.error ) );

	if ( data.reset ) {
		resetForm();
	}
}

/**
 * Handle 2FA requirement
 *
 * @param {string} method - 2FA method (e.g., 'email', 'app')
 */
function handle2FA( method ) {
	hideFormFields( loginForm );
	show2FAField( loginForm, method, window.Brain2FA?.rememberDevice );
}

/**
 * Submit the form natively
 */
function submitForm() {

	// Remove event listener to avoid recursion
	if ( loginForm && submitHandler ) {
		loginForm.removeEventListener( 'submit', submitHandler );

		const submitButton = loginForm.querySelector( 'button[type="submit"], input[type="submit"]' );
		if ( submitButton && 'function' === typeof loginForm.requestSubmit ) {
			loginForm.requestSubmit( submitButton );
			return;
		}

		// WooCommerce requires the login submit button's name/value in the request.
		if ( loginForm.classList.contains( 'woocommerce-form-login' ) && ! loginForm.querySelector( 'input[name="login"]' ) ) {
			const loginAction = document.createElement( 'input' );
			loginAction.type = 'hidden';
			loginAction.name = 'login';
			loginAction.value = submitButton?.value || 'Log in';
			loginForm.appendChild( loginAction );
		}

		loginForm.submit();
	}
}

/**
 * Reset form fields
 */
function resetForm() {
	const userLogin = loginForm?.querySelector( '#user_login, #username, [name="log"], [name="username"]' );
	const userPass = loginForm?.querySelector( '#user_pass, #password, [name="pwd"], [name="password"]' );
	const rememberMe = loginForm?.querySelector( '#rememberme, [name="rememberme"]' );

	if ( userPass ) {
		userPass.value = '';
	}
	if ( userLogin ) {
		userLogin.value = '';
	}
	if ( rememberMe ) {
		rememberMe.checked = false;
	}
}

export default initLoginForm;
