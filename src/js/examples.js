/**
 * Example usage of the modular JavaScript architecture
 * 
 * This file demonstrates how to use the various utilities and components
 * in the Brain 2FA plugin based on actual implementation patterns.
 */

// ============================================
// 1. Importing WordPress i18n functions
// ============================================
import { __, _x, _n, sprintf } from '@wordpress/i18n';

// Basic translation
const message = __('Verification Code', 'brain2fa');

// Translation with context
const label = _x('Code', 'verification code label', 'brain2fa');

// Plural translation
const count = 5;
const codes = sprintf(_n('1 backup code', '%d backup codes', count, 'brain2fa'), count);

// Formatted translation
const userName = 'John';
const greeting = sprintf(__('Welcome, %s!', 'brain2fa'), userName);


// ============================================
// 2. Using AJAX utilities
// ============================================
import { sendAjaxRequest } from '@/utils/ajax';

async function validateLogin() {
    const data = {
        action: 'brain2fa_login',
        nonce: window.Brain2FA?.nonce,
        username: document.getElementById('user_login')?.value,
        password: document.getElementById('user_pass')?.value,
    };

    try {
        const response = await sendAjaxRequest(data);
        
        if (response.success && response.data.login === 1) {
            if (response.data.requires_2fa) {
                // Show 2FA field
                console.log('2FA required');
            } else {
                // Submit form
                console.log('Login successful');
            }
        } else {
            console.error('Login failed:', response.data);
        }
    } catch (error) {
        console.error('Request failed:', error);
    }
}


// ============================================
// 3. Using DOM utilities
// ============================================
import { hideFormFields, show2FAField, getElement, getAllElements } from '@/utils/dom';

// Hide login form fields
hideFormFields();

// Show 2FA verification field
const form = document.getElementById('loginform');
if (form) {
    show2FAField(form, 'email');
}

// Get single element safely
const submitButton = getElement('.submit-button');
if (submitButton) {
    submitButton.addEventListener('click', handleClick);
}

// Get multiple elements
const checkboxes = getAllElements('input[type="checkbox"]');
checkboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', handleCheckboxChange);
});


// ============================================
// 4. Using notification utilities
// ============================================
import { showError, showSuccess, showInfo, removeNotifications } from '@/utils/notification';

// Show error message
showError(__('Invalid credentials. Please try again.', 'brain2fa'));

// Show success message
showSuccess(__('2FA has been enabled successfully!', 'brain2fa'));

// Show info message
showInfo(__('Please check your email for the verification code.', 'brain2fa'));

// Remove all notifications
removeNotifications();


// ============================================
// 5. Creating a settings page component
// ============================================
class SettingsManager {
    constructor() {
        this.init();
    }

    init() {
        this.bindToggleEvents();
        this.initializeConditionalFields();
    }

    bindToggleEvents() {
        const toggle = getElement('input[name="enable_feature"]');
        if (toggle) {
            toggle.addEventListener('change', (e) => {
                this.handleToggle(e.target.checked);
            });
        }
    }

    handleToggle(enabled) {
        const settingsSection = getElement('.feature-settings');
        if (settingsSection) {
            settingsSection.style.display = enabled ? 'block' : 'none';
        }
    }

    initializeConditionalFields() {
        const toggle = getElement('input[name="enable_feature"]');
        if (toggle) {
            this.handleToggle(toggle.checked);
        }
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    if (getElement('.settings-page')) {
        new SettingsManager();
    }
});


// ============================================
// 6. Form validation example
// ============================================
class FormValidator {
    constructor(formElement) {
        this.form = formElement;
        this.init();
    }

    init() {
        this.form.addEventListener('submit', (e) => {
            this.handleSubmit(e);
        });
    }

    async handleSubmit(event) {
        event.preventDefault();

        // Validate form
        const errors = this.validate();
        if (errors.length > 0) {
            showError(errors.join(' '));
            return;
        }

        // Show confirmation for critical actions
        const message = __('Are you sure you want to proceed?', 'brain2fa');
        if (!confirm(message)) {
            return;
        }

        // Submit via AJAX
        try {
            const formData = new FormData(this.form);
            const data = {
                action: 'save_settings',
                nonce: window.Brain2FA?.nonce,
            };

            // Convert FormData to object
            for (const [key, value] of formData.entries()) {
                data[key] = value;
            }

            const response = await sendAjaxRequest(data);
            
            if (response.success) {
                showSuccess(__('Settings saved successfully!', 'brain2fa'));
            } else {
                showError(response.data.message || __('Failed to save settings', 'brain2fa'));
            }
        } catch (error) {
            showError(__('An error occurred while saving', 'brain2fa'));
        }
    }

    validate() {
        const errors = [];
        
        // Example validation
        const requiredField = this.form.querySelector('[required]');
        if (requiredField && !requiredField.value) {
            errors.push(__('Please fill in all required fields', 'brain2fa'));
        }
        
        return errors;
    }
}


// ============================================
// 7. Animation helpers (like jQuery slideDown/Up)
// ============================================
function slideDown(element, duration = 200) {
    element.style.removeProperty('display');
    let display = window.getComputedStyle(element).display;
    if (display === 'none') display = 'block';
    element.style.display = display;

    const height = element.offsetHeight;
    element.style.overflow = 'hidden';
    element.style.height = '0';
    element.style.paddingTop = '0';
    element.style.paddingBottom = '0';
    element.offsetHeight; // Force reflow

    element.style.transition = `all ${duration}ms ease-in-out`;
    element.style.height = height + 'px';
    element.style.removeProperty('padding-top');
    element.style.removeProperty('padding-bottom');

    setTimeout(() => {
        element.style.removeProperty('height');
        element.style.removeProperty('overflow');
        element.style.removeProperty('transition');
    }, duration);
}

function slideUp(element, duration = 200) {
    element.style.height = element.offsetHeight + 'px';
    element.offsetHeight; // Force reflow

    element.style.overflow = 'hidden';
    element.style.transition = `all ${duration}ms ease-in-out`;
    element.style.height = '0';
    element.style.paddingTop = '0';
    element.style.paddingBottom = '0';

    setTimeout(() => {
        element.style.display = 'none';
        element.style.removeProperty('height');
        element.style.removeProperty('padding-top');
        element.style.removeProperty('padding-bottom');
        element.style.removeProperty('overflow');
        element.style.removeProperty('transition');
    }, duration);
}


// ============================================
// 8. Modern JavaScript features used in plugin
// ============================================

// Async/await for AJAX calls
async function fetchData() {
    const data = await sendAjaxRequest({ action: 'get_data' });
    return data;
}

// Arrow functions for event handlers
const handleClick = (event) => {
    event.preventDefault();
    showInfo(__('Button clicked', 'brain2fa'));
};

// Destructuring
const { username, password } = formData;
const { success, data } = response;

// Optional chaining (safely access nested properties)
const nonce = window.Brain2FA?.nonce;
const message = response?.data?.message;

// Nullish coalescing (fallback to default if null/undefined)
const ajaxUrl = window.Brain2FA?.ajax ?? '/wp-admin/admin-ajax.php';

// Template literals for HTML
const html = `
    <div class="notice notice-warning">
        <p>
            <strong>${__('Warning:', 'brain2fa')}</strong>
            ${__('This action cannot be undone.', 'brain2fa')}
        </p>
    </div>
`;

// Array methods for DOM manipulation
const checkboxes = getAllElements('input[type="checkbox"]');
const checkedValues = Array.from(checkboxes)
    .filter(cb => cb.checked)
    .map(cb => cb.value);

// ForEach for event binding
checkboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', handleChange);
});


// ============================================
// Helper function stubs
// ============================================
function handleCheckboxChange(event) {
    console.log('Checkbox changed:', event.target.checked);
}

function handleChange(event) {
    console.log('Change event:', event);
}
