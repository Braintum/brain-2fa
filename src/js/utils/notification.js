/**
 * Notification utilities
 *
 * @package Brain2FA
 */

/**
 * Show error message
 *
 * @param {string} message - Error message to display
 * @param {boolean} allowMarkup - Whether to render the server-approved markup
 */
export function showError(message, allowMarkup = false) {
	removeError();

	const form = document.getElementById('loginform');
	if (!form) {
		return;
	}

	const errorDiv = document.createElement('div');
	errorDiv.id = 'login_error';
	errorDiv.className = 'notice notice-error';
	if (allowMarkup) {
		appendApprovedMarkup(errorDiv, message);
	} else {
		errorDiv.textContent = message;
	}

	form.parentNode.insertBefore(errorDiv, form);
}

/**
 * Append the limited markup returned by the login endpoint.
 *
 * @param {HTMLElement} container - Element receiving the sanitized markup.
 * @param {string} markup - Server-approved markup.
 */
function appendApprovedMarkup(container, markup) {
	const template = document.createElement('template');
	template.innerHTML = markup;

	const appendNode = (parent, node) => {
		if (node.nodeType === Node.TEXT_NODE) {
			parent.appendChild(document.createTextNode(node.textContent));
			return;
		}

		if (node.nodeType !== Node.ELEMENT_NODE) {
			return;
		}

		const tagName = node.tagName.toLowerCase();
		if (!['strong', 'a'].includes(tagName)) {
			node.childNodes.forEach((child) => appendNode(parent, child));
			return;
		}

		const element = document.createElement(tagName);
		if (tagName === 'a') {
			const href = node.getAttribute('href');
			if (href) {
				const link = document.createElement('a');
				link.href = href;
				if (['http:', 'https:'].includes(link.protocol)) {
					element.href = link.href;
				}
			}

			const title = node.getAttribute('title');
			if (title) {
				element.title = title;
			}
		}

		node.childNodes.forEach((child) => appendNode(element, child));
		parent.appendChild(element);
	};

	template.content.childNodes.forEach((node) => appendNode(container, node));
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
