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
export function showError( message, allowMarkup = false ) {
	removeError();

	const form = getLoginForm();
	if ( ! form ) {
		return;
	}

	const errorDiv = createNotification( 'error' );
	if ( allowMarkup ) {
		appendApprovedMarkup( errorDiv, message );
	} else {
		errorDiv.textContent = message;
	}

	insertNotification( errorDiv, form );
}

/**
 * Append the limited markup returned by the login endpoint.
 *
 * @param {HTMLElement} container - Element receiving the sanitized markup.
 * @param {string} markup - Server-approved markup.
 */
function appendApprovedMarkup( container, markup ) {
	const template = document.createElement( 'template' );
	template.innerHTML = markup;

	const appendNode = ( parent, node ) => {
		if ( node.nodeType === Node.TEXT_NODE ) {
			parent.appendChild( document.createTextNode( node.textContent ) );
			return;
		}

		if ( node.nodeType !== Node.ELEMENT_NODE ) {
			return;
		}

		const tagName = node.tagName.toLowerCase();
		if ( ! [ 'strong', 'a' ].includes( tagName ) ) {
			node.childNodes.forEach( ( child ) => appendNode( parent, child ) );
			return;
		}

		const element = document.createElement( tagName );
		if ( 'a' === tagName ) {
			const href = node.getAttribute( 'href' );
			if ( href ) {
				const link = document.createElement( 'a' );
				link.href = href;
				if ([ 'http:', 'https:' ].includes( link.protocol ) ) {
					element.href = link.href;
				}
			}

			const title = node.getAttribute( 'title' );
			if ( title ) {
				element.title = title;
			}
		}

		node.childNodes.forEach( ( child ) => appendNode( element, child ) );
		parent.appendChild( element );
	};

	template.content.childNodes.forEach( ( node ) => appendNode( container, node ) );
}

/**
 * Show success message
 *
 * @param {string} message - Success message to display
 */
export function showSuccess( message ) {
	removeNotifications();

	const form = getLoginForm();
	if ( ! form ) {
		return;
	}

	const successDiv = createNotification( 'success' );
	successDiv.textContent = message;

	insertNotification( successDiv, form );
}

/**
 * Show info message
 *
 * @param {string} message - Info message to display
 */
export function showInfo( message ) {
	removeNotifications();

	const form = getLoginForm();
	if ( ! form ) {
		return;
	}

	const infoDiv = createNotification( 'info' );
	infoDiv.textContent = message;

	insertNotification( infoDiv, form );
}

/**
 * Find either supported login form.
 *
 * @return {HTMLFormElement|null} Login form.
 */
function getLoginForm() {
	return document.getElementById( 'loginform' ) || document.querySelector( '.woocommerce-form-login' );
}

/**
 * Create a WordPress or WooCommerce notification element.
 *
 * @param {string} type Notification type.
 * @return {HTMLDivElement} Notification element.
 */
function createNotification( type ) {
	const notification = document.createElement( 'div' );
	const isWooCommerce = Boolean( document.querySelector( '.woocommerce-form-login' ) );
	notification.id = `login_${type}`;
	notification.className = isWooCommerce ?
		`woocommerce-${'error' === type ? 'error' : 'message'}` :
		`notice notice-${type}`;

	if ( isWooCommerce && 'error' === type ) {
		notification.setAttribute( 'role', 'alert' );
	}

	return notification;
}

/**
 * Place a notification in the appropriate login-page notices area.
 *
 * @param {HTMLElement} notification Notification element.
 * @param {HTMLFormElement} form Login form.
 */
function insertNotification( notification, form ) {
	const notices = form.closest( '.woocommerce' )?.querySelector( '.woocommerce-notices-wrapper' );
	if ( notices ) {
		notices.appendChild( notification );
		return;
	}

	form.parentNode.insertBefore( notification, form );
}

/**
 * Remove error message
 */
export function removeError() {
	const errorDiv = document.getElementById( 'login_error' );
	if ( errorDiv ) {
		errorDiv.remove();
	}
}

/**
 * Remove all notification messages
 */
export function removeNotifications() {
	const notifications = document.querySelectorAll( '#login_error, #login_success, #login_info' );
	notifications.forEach( ( notification ) => notification.remove() );
}
