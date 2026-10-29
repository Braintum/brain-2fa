/**
 * Recovery code download on the Login Security page (wp-admin and My Account)
 *
 * @package Brain2FA
 * @since 1.3.0
 */

import { __ } from '@wordpress/i18n';

/**
 * Handle recovery code download triggered from a button with data attributes.
 * Expected data attributes: data-codes (JSON array), data-site, data-user, data-email.
 */
export function initRecoveryCodeDownload() {
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
