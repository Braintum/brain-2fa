/**
 * Brain 2FA Admin JavaScript
 *
 * Settings page: unsaved-change warning, and confirmations before turning off a code method
 * that users depend on or requiring 2FA for more roles.
 * Dependent fields are shown and hidden with CSS, so the page works without JavaScript.
 * Login Security page: recovery code download.
 *
 * @package Brain2FA
 * @since 1.0.0
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { getElement } from './utils/dom';

const ROLE_SELECTOR = 'input[name="brain2fa_settings[force_2fa_roles][]"]';

/**
 * Get the roles currently ticked in the settings form.
 *
 * @param {HTMLFormElement} form - The settings form.
 * @return {string[]} Role slugs.
 */
function getCheckedRoles( form ) {
	return Array.from( form.querySelectorAll( `${ROLE_SELECTOR}:checked` ) ).map( ( input ) => input.value );
}

/**
 * Warn before leaving the settings page with unsaved edits, and confirm before
 * requiring two-factor authentication for roles that did not require it.
 *
 * @param {HTMLFormElement} form - The settings form.
 */
function initSettingsForm( form ) {
	const initialRoles = getCheckedRoles( form );

	// Code methods that are on and have users who could not sign in without them.
	const guardedMethods = Array.from( form.querySelectorAll( 'input[data-brain2fa-blocked-users]' ) )
		.filter( ( input ) => input.checked && 0 < parseInt( input.dataset.brain2faBlockedUsers, 10 ) );
	let isDirty = false;

	const markDirty = () => {
		isDirty = true;
	};

	form.addEventListener( 'input', markDirty );
	form.addEventListener( 'change', markDirty );

	form.addEventListener( 'submit', ( e ) => {
		const turnedOff = guardedMethods.filter( ( input ) => ! input.checked );
		if ( 0 < turnedOff.length ) {
			const blocked = turnedOff.reduce( ( sum, input ) => sum + parseInt( input.dataset.brain2faBlockedUsers, 10 ), 0 );
			const message = sprintf(

				/* translators: %d: number of users */
				_n(
					'%d user will not be able to sign in with their password, because the code method they use is being turned off and they have no passkey. Continue?',
					'%d users will not be able to sign in with their password, because the code method they use is being turned off and they have no passkey. Continue?',
					blocked,
					'brain-2fa'
				),
				blocked
			);

			if ( ! window.confirm( message ) ) {
				e.preventDefault();
				return;
			}
		}

		const addedRoles = getCheckedRoles( form ).filter( ( role ) => ! initialRoles.includes( role ) );

		if ( 0 < addedRoles.length ) {
			const message = __(
				'Users in the newly selected roles must set up two-factor authentication. Make sure your own account has a method set up first. Continue?',
				'brain-2fa'
			);

			if ( ! window.confirm( message ) ) {
				e.preventDefault();
				return;
			}
		}

		isDirty = false;
	});

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( isDirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
}

/**
 * Handle recovery code download triggered from a button with data attributes.
 * Expected data attributes: data-codes (JSON array), data-site, data-user, data-email.
 */
function initRecoveryCodeDownload() {
	const btn = document.getElementById( 'brain2fa-download-codes' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', () => {
		let codes = [];
		try {
			codes = JSON.parse( btn.dataset.codes || '[]' );
		} catch ( e ) {
			return;
		}

		const site  = btn.dataset.site  || '';
		const user  = btn.dataset.user  || '';
		const email = btn.dataset.email || '';

		const content = [
			site + ' - ' + __( 'Two-Factor Authentication Recovery Codes', 'brain-2fa' ),
			'',
			__( 'Generated:', 'brain-2fa' ) + ' ' + new Date().toLocaleString(),
			'',
			__( 'IMPORTANT: Store these codes in a safe place.', 'brain-2fa' ),
			__( 'Each code can only be used once.', 'brain-2fa' ),
			'',
			...codes,
			'',
			__( 'User:', 'brain-2fa' )  + ' ' + user,
			__( 'Email:', 'brain-2fa' ) + ' ' + email
		].join( '\n' );

		const blob = new Blob([ content ], { type: 'text/plain' });
		const url  = window.URL.createObjectURL( blob );
		const a    = document.createElement( 'a' );
		a.href     = url;
		a.download = 'brain2fa-recovery-codes.txt';
		document.body.appendChild( a );
		a.click();
		window.URL.revokeObjectURL( url );
		document.body.removeChild( a );
	});
}

/**
 * Initialize on DOM ready
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const settingsForm = getElement( '.brain2fa-settings-form' );
	if ( settingsForm ) {
		initSettingsForm( settingsForm );
	}

	initRecoveryCodeDownload();
});
