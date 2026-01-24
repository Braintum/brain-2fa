/**
 * Notification utilities
 *
 * @package Brain2FA
 */

/**
 * Show error message
 *
 * @param {string} message - Error message to display
 */
export function showError(message) {
	removeError();

	const form = document.getElementById('loginform');
	if (!form) {
		return;
	}

	const errorDiv = document.createElement('div');
	errorDiv.id = 'login_error';
	errorDiv.className = 'notice notice-error';
	errorDiv.textContent = message;

	form.parentNode.insertBefore(errorDiv, form);
}

/**
 * Show success message
 *
 * @param {string} message - Success message to display
 */
export function showSuccess(message) {
	removeNotifications();

	const form = document.getElementById('loginform');
	if (!form) {
		return;
	}

	const successDiv = document.createElement('div');
	successDiv.id = 'login_success';
	successDiv.className = 'notice notice-success';
	successDiv.textContent = message;

	form.parentNode.insertBefore(successDiv, form);
}

/**
 * Show info message
 *
 * @param {string} message - Info message to display
 */
export function showInfo(message) {
	removeNotifications();

	const form = document.getElementById('loginform');
	if (!form) {
		return;
	}

	const infoDiv = document.createElement('div');
	infoDiv.id = 'login_info';
	infoDiv.className = 'notice notice-info';
	infoDiv.textContent = message;

	form.parentNode.insertBefore(infoDiv, form);
}

/**
 * Remove error message
 */
export function removeError() {
	const errorDiv = document.getElementById('login_error');
	if (errorDiv) {
		errorDiv.remove();
	}
}

/**
 * Remove all notification messages
 */
export function removeNotifications() {
	const notifications = document.querySelectorAll('#login_error, #login_success, #login_info');
	notifications.forEach((notification) => notification.remove());
}
