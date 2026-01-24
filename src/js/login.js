/**
 * Login form handler with 2FA support
 *
 * @package Brain2FA
 */

import initLoginForm from './components/LoginForm';

/**
 * Initialize login form when DOM is ready
 */
document.addEventListener('DOMContentLoaded', () => {
	const loginFormElement = document.getElementById('loginform');
	
	if (loginFormElement) {
		initLoginForm(loginFormElement);
	}
});
