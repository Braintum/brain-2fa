/**
 * Brain 2FA My Account JavaScript
 *
 * WooCommerce My Account Login Security tab: recovery code download.
 * Passkeys are added by passkey-manage.js, loaded separately.
 *
 * @package Brain2FA
 * @since 1.3.0
 */

import { initRecoveryCodeDownload } from './utils/recovery-codes';

document.addEventListener( 'DOMContentLoaded', initRecoveryCodeDownload );
