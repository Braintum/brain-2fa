/**
 * Login Form Component
 *
 * Handles login form submission with 2FA support
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';
import { sendAjaxRequest } from '../utils/ajax';
import { hideFormFields, restoreFormFields, show2FAField, showPasskeyStep } from '../utils/dom';
import { showError, removeError } from '../utils/notification';

let isSubmitting = false;
let loginForm = null;
let submitHandler = null;

/**
 * Passkey options for the second step: { options, issuedAt, used }, or null.
 *
 * The server consumes a challenge on each verification attempt, and challenges
 * expire, so used or old options are replaced by running the preflight again.
 *
 * @type {Object|null}
 */
let passkeyState = null;

/**
 * Whether a second-step action (passkey or email code request) is running.
 *
 * @type {boolean}
 */
let isSecondStepBusy = false;

/**
 * Initialize the login form
 *
 * @param {HTMLFormElement} formElement - The login form element
 */
export function initLoginForm( formElement ) {
	loginForm = formElement;
	isSubmitting = false;

	// The server renders a code field for browsers without JavaScript; this script shows its own when needed.
	loginForm.querySelectorAll( '.brain2fa-fallback-field' ).forEach( ( field ) => field.remove() );

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

	if ( ! username || ! password ) {
		showError( __( 'Please enter your username and password.', 'brain-2fa' ) );
		return;
	}

	isSubmitting = true;
	removeError();

	try {
		const response = await sendPreflight();

		if ( ! response.success ) {
			handleError( response.data );
			return;
		}

		if ( 1 === response.data.login ) {
			if ( response.data.requires_2fa ) {
				handle2FA( response.data );
			} else {
				submitForm();
			}
		}
	} catch ( error ) {
		showError( __( 'An error occurred. Please try again.', 'brain-2fa' ) );
	} finally {
		isSubmitting = false;
	}
}

/**
 * Send the credential preflight with the username and password in the form.
 *
 * The fields stay in the form (hidden) during the second step, so the
 * preflight can run again to get fresh passkey options or an email code.
 *
 * @param {boolean} sendEmailCode - Ask the server to email a code even when a passkey is offered.
 * @return {Promise<Object>} Parsed JSON response.
 */
function sendPreflight( sendEmailCode = false ) {
	const rememberMe = loginForm.querySelector( '#rememberme, [name="rememberme"]' );

	const data = {
		action: 'brain2fa_login',
		nonce: window.Brain2FA?.nonce || '',
		username: loginForm.querySelector( '#user_login, #username, [name="log"], [name="username"]' )?.value || '',
		password: loginForm.querySelector( '#user_pass, #password, [name="pwd"], [name="password"]' )?.value || '',
		rememberme: rememberMe?.checked ? 'forever' : ''
	};

	if ( sendEmailCode ) {
		data.brain2fa_send_email_code = '1';
	}

	return sendAjaxRequest( data );
}

/**
 * Run the preflight again during the second step.
 *
 * @param {boolean} sendEmailCode - Ask the server to email a code.
 * @return {Promise<Object|null>} The new second-step data, or null when the step cannot continue.
 */
async function refreshSecondStep( sendEmailCode ) {
	let response;

	try {
		response = await sendPreflight( sendEmailCode );
	} catch ( error ) {
		showError( __( 'An error occurred. Please try again.', 'brain-2fa' ) );
		return null;
	}

	if ( ! response.success ) {
		handleError( response.data );

		// The sign-in has to start over (for example, after a lockout).
		if ( response.data?.reset ) {
			passkeyState = null;
			restoreFormFields( loginForm );
		}
		return null;
	}

	if ( ! response.data.requires_2fa ) {
		submitForm();
		return null;
	}

	return response.data;
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
		__( 'An unknown error occurred. Please try again.', 'brain-2fa' );

	showError( message, Boolean( data.message || data.error ) );

	if ( data.reset ) {
		resetForm();
	}
}

/**
 * Handle 2FA requirement
 *
 * The second step can be a code (data.method), a passkey (data.passkey), or a
 * choice of both. Passkeys are handled by passkey-login.js when it is loaded.
 * For email users who also have a passkey, the server sends the code only when
 * asked (data.email_on_request).
 *
 * @param {Object} data - Preflight response data.
 */
function handle2FA( data ) {
	const hasPasskey = Boolean( data.passkey && window.Brain2FAPasskeyLogin );

	// Without passkey support in this browser, the email code is the only option, so send it now.
	if ( data.email_on_request && ! hasPasskey ) {
		requestEmailCode();
		return;
	}

	if ( ! data.method && ! hasPasskey ) {
		showError( __( 'Use your passkey to finish signing in. Passkeys are not available in this browser.', 'brain-2fa' ) );
		return;
	}

	showSecondStep( data );

	// With no code method, the passkey is the only option, so ask for it right away.
	if ( hasPasskey && ! data.method ) {
		startPasskey();
	}
}

/**
 * Show the second-step fields for a preflight response and store its passkey options.
 *
 * Safe to call again with a newer response: existing fields are kept or redrawn.
 *
 * @param {Object} data - Preflight response data.
 * @return {boolean} True when the user has a way to continue in this browser.
 */
function showSecondStep( data ) {
	const passkeyLogin = data.passkey ? window.Brain2FAPasskeyLogin : null;

	// The code field counts once it is shown, even if this response did not send a new email.
	const hasCodeField = Boolean( data.method ) && ( ! data.email_on_request || null !== document.getElementById( 'brain2fa_code' ) );

	passkeyState = passkeyLogin ? { options: data.passkey, issuedAt: Date.now(), used: false } : null;

	// Nothing to offer: keep the username and password fields visible.
	if ( ! hasCodeField && ! passkeyLogin ) {
		return false;
	}

	hideFormFields( loginForm );

	if ( hasCodeField ) {
		show2FAField( loginForm, data.method, window.Brain2FA?.rememberDevice );
	}

	if ( passkeyLogin ) {
		showPasskeyStep( loginForm, {
			hasCodeField,
			onUsePasskey: startPasskey,
			onRequestEmailCode: data.email_on_request && ! hasCodeField ? requestEmailCode : null
		});
	}

	return true;
}

/**
 * Check whether the stored passkey options can still be used.
 *
 * @return {boolean} True when the options are unused and not about to expire.
 */
function hasUsablePasskeyOptions() {
	if ( ! passkeyState || passkeyState.used ) {
		return false;
	}

	// Leave a margin so the challenge does not expire while the browser prompt is open.
	const lifetime = ( passkeyState.options.timeout || 300000 ) - 60000;

	return Date.now() - passkeyState.issuedAt < lifetime;
}

/**
 * Run the passkey second step, getting fresh options first when needed.
 */
async function startPasskey() {
	const passkeyLogin = window.Brain2FAPasskeyLogin;
	if ( isSecondStepBusy || ! passkeyLogin ) {
		return;
	}

	isSecondStepBusy = true;

	try {
		if ( ! hasUsablePasskeyOptions() ) {
			const data = await refreshSecondStep( false );
			if ( ! data ) {
				return;
			}

			showSecondStep( data );
			if ( ! passkeyState ) {
				showError( __( 'Passkey sign-in is unavailable. Please try again.', 'brain-2fa' ) );
				return;
			}
		}

		const result = await passkeyLogin.runSecondStep( loginForm, passkeyState.options );
		if ( 'failed' === result ) {
			passkeyState.used = true;
		}
	} finally {
		isSecondStepBusy = false;
	}
}

/**
 * Ask the server to email a verification code, then show the code field.
 */
async function requestEmailCode() {
	if ( isSecondStepBusy ) {
		return;
	}

	isSecondStepBusy = true;
	removeError();

	try {
		const data = await refreshSecondStep( true );
		if ( ! data ) {
			return;
		}

		const canContinue = showSecondStep( data );
		if ( 'email' !== data.method || data.email_on_request ) {
			showError(
				canContinue ?
					__( 'Unable to send your email authentication code. Please try again or use your passkey.', 'brain-2fa' ) :
					__( 'Unable to send your email authentication code. Please try again or contact an administrator.', 'brain-2fa' )
			);
		}
	} finally {
		isSecondStepBusy = false;
	}
}

/**
 * Submit the form natively
 */
function submitForm() {

	// Remove event listener to avoid recursion
	if ( loginForm && submitHandler ) {
		loginForm.removeEventListener( 'submit', submitHandler );

		setTimeout( () => {
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
		}, 0 );
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
