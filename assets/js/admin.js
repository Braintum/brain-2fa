/**
 * Brain 2FA Admin JavaScript
 *
 * @package Brain_2FA
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Admin settings page functionality
	 */
	const Brain2FAAdmin = {

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.conditionalFields();
		},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			// Toggle remember device duration field
			$('input[name="brain2fa_settings[remember_device]"]').on('change', function() {
				const $durationField = $(this).closest('td').find('input[name="brain2fa_settings[remember_duration]"]').closest('div');
				if ($(this).is(':checked')) {
					$durationField.slideDown(200);
				} else {
					$durationField.slideUp(200);
				}
			});

			// Force 2FA warning
			$('input[name="brain2fa_settings[force_2fa_roles][]"]').on('change', function() {
				if ($('input[name="brain2fa_settings[force_2fa_roles][]"]:checked').length > 0) {
					Brain2FAAdmin.showForceWarning();
				} else {
					Brain2FAAdmin.hideForceWarning();
				}
			});

			// Confirm settings save if force 2FA is enabled
			$('form').on('submit', function(e) {
				if ($('input[name="brain2fa_settings[force_2fa_roles][]"]:checked').length > 0) {
					const confirmed = confirm('You are about to require 2FA for selected user roles. Make sure administrators have set up 2FA before proceeding. Continue?');
					if (!confirmed) {
						e.preventDefault();
						return false;
					}
				}
			});
		},

		/**
		 * Handle conditional field visibility
		 */
		conditionalFields: function() {
			// Show/hide remember device duration on page load
			const $rememberCheckbox = $('input[name="brain2fa_settings[remember_device]"]');
			const $durationField = $rememberCheckbox.closest('td').find('input[name="brain2fa_settings[remember_duration]"]').closest('div');
			
			if ($rememberCheckbox.is(':checked')) {
				$durationField.show();
			} else {
				$durationField.hide();
			}
		},

		/**
		 * Show force 2FA warning
		 */
		showForceWarning: function() {
			const warningHtml = '<div class="brain2fa-warning notice notice-warning inline" style="margin-top: 10px;">' +
				'<p><span class="dashicons dashicons-warning"></span> ' +
				'<strong>Warning:</strong> Users in selected roles will be required to set up 2FA on their next login.</p>' +
				'</div>';
			
			if ($('.brain2fa-force-warning').length === 0) {
				$('input[name="brain2fa_settings[force_2fa_roles][]"]').closest('fieldset').after(
					'<div class="brain2fa-force-warning">' + warningHtml + '</div>'
				);
			}
		},

		/**
		 * Hide force 2FA warning
		 */
		hideForceWarning: function() {
			$('.brain2fa-force-warning').remove();
		}
	};

	/**
	 * User profile 2FA setup functionality
	 */
	const Brain2FAProfile = {

		/**
		 * Initialize
		 */
		init: function() {
			this.bindMethodToggle();
			this.validateSetup();
		},

		/**
		 * Bind method toggle functionality
		 */
		bindMethodToggle: function() {
			$('input[name="brain2fa_setup_method"]').on('change', function() {
				$('.brain2fa-setup-form').hide();
				const methodId = $(this).val();
				$('#brain2fa-setup-' + methodId).show();
			});

			// Trigger on page load if a method is already selected
			const selectedMethod = $('input[name="brain2fa_setup_method"]:checked').val();
			if (selectedMethod) {
				$('#brain2fa-setup-' + selectedMethod).show();
			}
		},

		/**
		 * Validate 2FA setup before submission
		 */
		validateSetup: function() {
			$('form#your-profile').on('submit', function(e) {
				const setupMethod = $('input[name="brain2fa_setup_method"]:checked').val();
				
				if (setupMethod) {
					// Validate TOTP
					if (setupMethod === 'totp') {
						const verificationCode = $('input[name="brain2fa_verification_code"]').val();
						if (!verificationCode || verificationCode.length !== 6) {
							alert('Please enter a valid 6-digit verification code from your authenticator app.');
							e.preventDefault();
							return false;
						}
					}
					
					// Validate Email
					if (setupMethod === 'email_backup') {
						const backupEmail = $('input[name="brain2fa_backup_email"]').val();
						if (!backupEmail || !Brain2FAProfile.isValidEmail(backupEmail)) {
							alert('Please enter a valid email address.');
							e.preventDefault();
							return false;
						}
					}
				}
			});
		},

		/**
		 * Validate email format
		 */
		isValidEmail: function(email) {
			const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return emailRegex.test(email);
		}
	};

	/**
	 * Users page functionality
	 */
	const Brain2FAUsers = {

		/**
		 * Initialize
		 */
		init: function() {
			this.bindFilters();
		},

		/**
		 * Bind filter functionality
		 */
		bindFilters: function() {
			// Add filter controls if on users page
			if ($('.brain2fa-users-wrap').length > 0) {
				this.addFilterControls();
			}
		},

		/**
		 * Add filter controls to users table
		 */
		addFilterControls: function() {
			const filterHtml = '<div class="brain2fa-filters" style="margin-bottom: 15px;">' +
				'<label>Filter by status: ' +
				'<select class="brain2fa-status-filter">' +
				'<option value="all">All Users</option>' +
				'<option value="enabled">2FA Enabled</option>' +
				'<option value="disabled">2FA Disabled</option>' +
				'</select>' +
				'</label>' +
				'</div>';
			
			$('.brain2fa-users-container').before(filterHtml);

			// Bind filter change
			$('.brain2fa-status-filter').on('change', function() {
				const filterValue = $(this).val();
				Brain2FAUsers.filterTable(filterValue);
			});
		},

		/**
		 * Filter users table
		 */
		filterTable: function(filterValue) {
			$('.brain2fa-users-container tbody tr').each(function() {
				const $row = $(this);
				const hasEnabled = $row.find('.brain2fa-status-enabled').length > 0;
				
				if (filterValue === 'all') {
					$row.show();
				} else if (filterValue === 'enabled' && hasEnabled) {
					$row.show();
				} else if (filterValue === 'disabled' && !hasEnabled) {
					$row.show();
				} else {
					$row.hide();
				}
			});
		}
	};

	/**
	 * Document ready
	 */
	$(document).ready(function() {
		// Initialize based on current page
		if ($('.brain2fa-settings-wrap').length > 0) {
			Brain2FAAdmin.init();
		}
		
		if ($('table.form-table tr th:contains("Two-Factor Authentication")').length > 0) {
			Brain2FAProfile.init();
		}
		
		if ($('.brain2fa-users-wrap').length > 0) {
			Brain2FAUsers.init();
		}
	});

})(jQuery);
