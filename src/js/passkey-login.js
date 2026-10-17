/**
 * Passwordless sign-in with passkeys
 *
 * Offers saved passkeys in the username field's autofill (conditional UI) and
 * through a "Sign in with a passkey" button. After the server verifies the
 * passkey it returns a single-use token, and the normal login form is
 * submitted with it so WordPress completes the sign-in as usual.
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';
import {
	browserSupportsWebAuthn,
	browserSupportsWebAuthnAutofill,
	startAuthentication
} from '@simplewebauthn/browser';
import { showError, removeError } from './utils/notification';

const config = window.Brain2FAPasskeys || {};

/**
 * Post form data to admin-ajax.php.
 *
 * @param {Object} data - Fields to send.
 * @return {Promise<Object>} Parsed JSON response.
 */
async function post( data ) {
	const response = await fetch( config.ajax, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( data ),
		credentials: 'same-origin'
	});

	return response.json();
}

/**
 * Submit the login form natively with the passkey token.
 *
 * Uses HTMLFormElement.submit() so the password-flow submit handler in
 * login.js does not intercept it.
 *
 * @param {HTMLFormElement} form  - Login form.
 * @param {Object}          data  - Token and login returned by the server.
 */
function submitWithToken( form, data ) {
	const userField = form.querySelector( '#user_login, #username' );
	if ( userField ) {
		userField.value = data.login;
	}

	const token = document.createElement( 'input' );
	token.type = 'hidden';
	token.name = 'brain2fa_passkey_token';
	token.value = data.token;
	form.appendChild( token );

	// WooCommerce only processes the login when the submit button's name is present.
	if ( form.classList.contains( 'woocommerce-form-login' ) && ! form.querySelector( 'input[type="hidden"][name="login"]' ) ) {
		const loginAction = document.createElement( 'input' );
		loginAction.type = 'hidden';
		loginAction.name = 'login';
		loginAction.value = '1';
		form.appendChild( loginAction );
	}

	HTMLFormElement.prototype.submit.call( form );
}

/**
 * Run a passkey sign-in ceremony.
 *
 * @param {HTMLFormElement} form     - Login form.
 * @param {boolean}         autofill - True for conditional UI, false for the button.
 */
async function signIn( form, autofill ) {
	try {
		const options = await post({ action: 'brain2fa_passkey_auth_options' });
		if ( ! options.success ) {
			if ( ! autofill ) {
				showError( options.data?.message || __( 'Passkey sign-in is unavailable. Please try again.', 'brain-2fa' ) );
			}
			return;
		}

		const credential = await startAuthentication({
			optionsJSON: options.data.options,
			useBrowserAutofill: autofill
		});

		removeError();
		const result = await post({
			action: 'brain2fa_passkey_auth_verify',
			credential: JSON.stringify( credential )
		});

		if ( ! result.success ) {
			showError( result.data?.message || __( 'Passkey sign-in failed. Please try again.', 'brain-2fa' ) );
			startAutofill( form );
			return;
		}

		submitWithToken( form, result.data );
	} catch ( error ) {

		// Cancelled prompts and replaced ceremonies (for example, clicking the button
		// while autofill is waiting) are not errors worth showing.
		if ( autofill || 'NotAllowedError' === error?.name || 'AbortError' === error?.name ) {
			if ( ! autofill ) {
				startAutofill( form );
			}
			return;
		}

		showError( __( 'Passkey sign-in failed. Please try again.', 'brain-2fa' ) );
		startAutofill( form );
	}
}

/**
 * Offer passkeys in the username field's autofill, when the browser supports it.
 *
 * @param {HTMLFormElement} form - Login form.
 */
async function startAutofill( form ) {
	if ( form.querySelector( '#user_login, #username' ) && ( await browserSupportsWebAuthnAutofill() ) ) {
		signIn( form, true );
	}
}

/**
 * Add the "Sign in with a passkey" button below the form's submit button.
 *
 * @param {HTMLFormElement} form - Login form.
 */
function addButton( form ) {
	const submit = form.querySelector( 'p.submit, button[type="submit"]' );
	const row = submit?.closest( 'p' ) || submit;
	if ( ! row?.parentNode ) {
		return;
	}

	const wrapper = document.createElement( 'p' );
	wrapper.className = 'brain2fa-passkey-login';
	wrapper.style.clear = 'both';
	wrapper.style.paddingTop = '16px';

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'button button-secondary button-large';
	button.style.width = '100%';
	button.textContent = __( 'Sign in with a passkey', 'brain-2fa' );
	button.addEventListener( 'click', () => signIn( form, false ) );

	wrapper.appendChild( button );
	row.parentNode.insertBefore( wrapper, row.nextSibling );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const form = document.getElementById( 'loginform' ) || document.querySelector( '.woocommerce-form-login' );
	if ( ! form || ! config.ajax || ! window.isSecureContext || ! browserSupportsWebAuthn() ) {
		return;
	}

	// Conditional UI only shows passkeys on fields marked with the "webauthn" token.
	const userField = form.querySelector( '#user_login, #username' );
	if ( userField ) {
		const tokens = ( userField.getAttribute( 'autocomplete' ) || 'username' ).split( /\s+/ ).filter( ( token ) => token && 'webauthn' !== token );
		userField.setAttribute( 'autocomplete', [ ...tokens, 'webauthn' ].join( ' ' ) );
	}

	addButton( form );
	startAutofill( form );
});
