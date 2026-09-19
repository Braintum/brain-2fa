/**
 * DOM manipulation utilities
 *
 * @package Brain2FA
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {HTMLFormElement} form - The login form
 */
export function hideFormFields( form ) {
	const usernameField = form.querySelector( '#user_login, #username, [name="log"], [name="username"]' );
	const passwordField = form.querySelector( '#user_pass, #password, [name="pwd"], [name="password"]' );
	const rememberField = form.querySelector( '#rememberme, [name="rememberme"]' );

	if ( usernameField ) {
		const usernamePara = usernameField.closest( 'p, .form-row' );
		if ( usernamePara ) {
			usernamePara.style.display = 'none';
		}
	}

	if ( passwordField ) {
		const passwordWrap = passwordField.closest( '.user-pass-wrap, .form-row' );
		if ( passwordWrap ) {
			passwordWrap.style.display = 'none';
		}
	}

	if ( rememberField ) {
		const rememberWrap = rememberField.closest( '.forgetmenot, .woocommerce-form__label-for-checkbox' );
		if ( rememberWrap ) {
			rememberWrap.style.display = 'none';
		}
	}
}

/**
 * Show 2FA code input field
 *
 * @param {HTMLFormElement} form - The login form
 * @param {string} method - 2FA method
 * @param {boolean} showRememberDevice - Whether to show the trusted-device option
 */
export function show2FAField( form, method = 'email', showRememberDevice = false ) {

	// Check if field already exists
	if ( document.getElementById( 'brain2fa_code' ) ) {
		return;
	}

	const submitButton = form.querySelector( 'p.submit, button[type="submit"]' );
	if ( ! submitButton ) {
		return;
	}
	const submitRow = submitButton.closest( 'p' );
	if ( ! submitRow || ! submitRow.parentNode ) {
		return;
	}

	// Create wrapper paragraph
	const wrapper = document.createElement( 'p' );

	// Create label
	const label = document.createElement( 'label' );
	label.setAttribute( 'for', 'brain2fa_code' );

	// Label text
	const labelText = document.createElement( 'span' );
	labelText.textContent = __( 'Verification Code', 'brain2fa' );
	label.appendChild( labelText );


	// Create input field
	const input = document.createElement( 'input' );
	input.type = 'text';
	input.id = 'brain2fa_code';
	input.name = 'brain2fa_code';
	input.className = 'input';
	input.setAttribute( 'inputmode', 'numeric' );
	input.setAttribute( 'autocomplete', 'one-time-code' );
	input.required = true;
	input.setAttribute( 'aria-label', __( 'Enter your verification code', 'brain2fa' ) );
	input.setAttribute( 'placeholder', __( 'Enter code', 'brain2fa' ) );

	label.appendChild( input );
	wrapper.appendChild( label );

	if ( 'email' === method ) {
		const description = document.createElement( 'div' );
		description.className = 'description';
		description.style.marginBottom = '8px';
		description.textContent = __( 'A verification code has been sent to your email address.', 'brain2fa' );
		wrapper.appendChild( description );
	}

	if ( showRememberDevice ) {
		const rememberLabel = document.createElement( 'label' );
		const rememberInput = document.createElement( 'input' );
		rememberInput.type = 'checkbox';
		rememberInput.name = 'brain2fa_remember_device';
		rememberInput.value = '1';
		rememberLabel.appendChild( rememberInput );
		rememberLabel.appendChild( document.createTextNode( ` ${__( 'Remember this device', 'brain2fa' )}` ) );
		wrapper.appendChild( rememberLabel );
	}

	// Keep the code field outside WooCommerce's submit row.
	submitRow.parentNode.insertBefore( wrapper, submitRow );

	// Focus the input
	input.focus();
}

/**
 * Get element safely
 *
 * @param {string} selector - CSS selector
 * @return {HTMLElement|null} Element or null
 */
export function getElement( selector ) {
	return document.querySelector( selector );
}

/**
 * Get all elements
 *
 * @param {string} selector - CSS selector
 * @return {NodeList} NodeList of elements
 */
export function getAllElements( selector ) {
	return document.querySelectorAll( selector );
}
