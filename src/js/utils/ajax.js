/**
 * AJAX utility functions
 *
 * @package Brain2FA
 */

/**
 * Send AJAX request to WordPress
 *
 * @param {Object} data - Data to send
 * @return {Promise} Promise resolving to response data
 */
export async function sendAjaxRequest(data) {
	const ajaxUrl = window.Brain2FA?.ajax || '/wp-admin/admin-ajax.php';

	try {
		const response = await fetch(ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams(data),
			credentials: 'same-origin',
		});

		if (!response.ok) {
			throw new Error(`HTTP error! status: ${response.status}`);
		}

		return await response.json();
	} catch (error) {
		console.error('AJAX request failed:', error);
		throw error;
	}
}

/**
 * Send AJAX request using jQuery (fallback)
 * Kept for backward compatibility
 *
 * @param {Object} data - Data to send
 * @return {Promise} Promise resolving to response data
 */
export function sendAjaxRequestJquery(data) {
	const ajaxUrl = window.Brain2FA?.ajax || '/wp-admin/admin-ajax.php';

	return new Promise((resolve, reject) => {
		if (typeof jQuery === 'undefined') {
			reject(new Error('jQuery is not loaded'));
			return;
		}

		jQuery.post(ajaxUrl, data)
			.done(resolve)
			.fail(reject);
	});
}
