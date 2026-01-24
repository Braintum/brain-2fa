jQuery(function ($) {
	'use strict';

	const form = $('#loginform');

	form.on('submit', function (e) {
		e.preventDefault();

		const code = $('#brain2fa_code').length ? $('#brain2fa_code').val() : '';

		if ( code !== '' ) {
			// Use native submit to avoid triggering this handler again
			form.off('submit').submit();
			return;
		} else {

			const data = {
				action: 'brain2fa_login',
				nonce: Brain2FA.nonce,
			};

			data.username = $('#user_login').val();
			data.password = $('#user_pass').val();

			$.post(Brain2FA.ajax, data).done(function (res) {
				if (!res.success) {

					console.log( res.data );
					if ( res.data.message !== undefined ) {
						showError(res.data.message);
					} else if ( res.data.error !== undefined ) {
						showError(res.data.error);
					} else {
						showError('An unknown error occurred. Please try again.');
					}
					if ( res.data.reset ) {
						// Clear password field
						$('#user_pass').val('');
						$( '#user_login' ).val( '' );
						$( '#rememberme' ).prop( 'checked', false );
					}
					return;
				}
				if ( res.data.login === 1 ) {
					if (res.data.requires_2fa) {
						show2FA( res.data.method );
					} else {
						form.off('submit').submit();
					}
				}
			});
		}

	});

	function show2FA(method) {

		// Hide username and password fields
		var usernameField = document.getElementById('user_login');
		var passwordField = document.getElementById('user_pass');
		var rememberField = document.getElementById('rememberme');
		
		if (usernameField) {
			var usernamePara = usernameField.closest('p');
			if (usernamePara) {
				usernamePara.style.display = 'none';
			}
		}
		if (passwordField) {
			var passwordWrap = passwordField.closest('.user-pass-wrap');
			if (passwordWrap) {
				passwordWrap.style.display = 'none';
			}
		}
		if (rememberField) {
			var rememberWrap = rememberField.closest('.forgetmenot');
			if (rememberWrap) {
				rememberWrap.style.display = 'none';
			}
		}

		if (!$('#brain2fa_code').length) {
			form.find('p.submit').before(`
				<p>
					<label>
						Verification Code<br>
						<input type="text" id="brain2fa_code" class="input" inputmode="numeric" required>
					</label>
				</p>
			`);
		}
	}

	function showError(msg) {
		$('#login_error').remove();
		form.before(`<div id="login_error" class="notice notice-error">${msg}</div>`);
	}
});
