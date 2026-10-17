/**
 * Passkey registration on the Login Security page
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';
import { browserSupportsWebAuthn, startRegistration } from '@simplewebauthn/browser';

const config = window.Brain2FAPasskeyManager || {};

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
		body: new URLSearchParams({ nonce: config.nonce, ...data }),
		credentials: 'same-origin'
	});

	return response.json();
}

/**
 * Show a message below the form.
 *
 * @param {HTMLElement} container - Message container.
 * @param {string}      message   - Text to show.
 * @param {string}      type      - "error", "success" or "info".
 */
function showMessage( container, message, type ) {
	container.textContent = '';

	const notice = document.createElement( 'div' );
	notice.className = `notice notice-${ type } inline`;
	const paragraph = document.createElement( 'p' );
	paragraph.textContent = message;
	notice.appendChild( paragraph );
	container.appendChild( notice );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const button = document.getElementById( 'brain2fa-passkey-add' );
	const nameField = document.getElementById( 'brain2fa_passkey_new_name' );
	const passwordField = document.getElementById( 'brain2fa_passkey_password' );
	const message = document.getElementById( 'brain2fa-passkey-message' );

	if ( ! button || ! passwordField || ! message || ! config.ajax ) {
		return;
	}

	if ( ! window.isSecureContext || ! browserSupportsWebAuthn() ) {
		button.disabled = true;
		showMessage( message, __( 'This browser does not support passkeys.', 'brain-2fa' ), 'error' );
		return;
	}

	button.addEventListener( 'click', async() => {
		if ( '' === passwordField.value ) {
			showMessage( message, __( 'Enter your password to add a passkey.', 'brain-2fa' ), 'error' );
			passwordField.focus();
			return;
		}

		button.disabled = true;
		showMessage( message, __( 'Follow the prompts from your browser or device.', 'brain-2fa' ), 'info' );

		try {
			const options = await post({
				action: 'brain2fa_passkey_register_options',
				name: nameField?.value || '',
				password: passwordField.value
			});
			passwordField.value = '';

			if ( ! options.success ) {
				showMessage( message, options.data?.message || __( 'The passkey could not be added.', 'brain-2fa' ), 'error' );
				return;
			}

			const credential = await startRegistration({ optionsJSON: options.data.options });
			const result = await post({
				action: 'brain2fa_passkey_register_verify',
				credential: JSON.stringify( credential )
			});

			if ( ! result.success ) {
				showMessage( message, result.data?.message || __( 'The passkey could not be added.', 'brain-2fa' ), 'error' );
				return;
			}

			showMessage( message, __( 'Passkey added. Reloading…', 'brain-2fa' ), 'success' );
			window.location.reload();
		} catch ( error ) {
			const text = 'InvalidStateError' === error?.name ?
				__( 'This device already has a passkey for your account.', 'brain-2fa' ) :
				__( 'Passkey setup was cancelled or failed. Please try again.', 'brain-2fa' );
			showMessage( message, text, 'error' );
		} finally {
			button.disabled = false;
		}
	});
});
