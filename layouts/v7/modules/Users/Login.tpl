{*+**********************************************************************************
* The contents of this file are subject to the vtiger CRM Public License Version 1.1
* ("License"); You may not use this file except in compliance with the License
* The Original Code is: vtiger CRM Open Source
* The Initial Developer of the Original Code is vtiger.
* Portions created by vtiger are Copyright (C) vtiger.
* All Rights Reserved.
************************************************************************************}
{* modules/Users/views/Login.php *}

{strip}
	<div class="login-page-container">
		<div class="login-card">
			<div class="login-header">
				<img class="logo-img" src="layouts/v7/resources/Images/Praesto_Main_Logo_1953x306.jpg" alt="Logo">
			</div>
			
			<div class="message-box {if $ERROR}error{elseif $MAIL_STATUS}success{/if}" id="validationMessage">
				{if $ERROR}
					{$MESSAGE}
				{elseif $MAIL_STATUS}
					{$MESSAGE}
				{/if}
			</div>

			<div id="loginFormDiv">
				<form method="POST" action="index.php">
					<input type="hidden" name="module" value="Users"/>
					<input type="hidden" name="action" value="Login"/>
					
					<div class="form-group">
						<input id="username" type="text" name="username" class="form-control" placeholder=" " required>
						<label class="form-label">Username</label>
					</div>
					
					<div class="form-group">
						<input id="password" type="password" name="password" class="form-control" placeholder=" " required>
						<label class="form-label">Password</label>
					</div>

					{assign var="CUSTOM_SKINS" value=Vtiger_Theme::getAllSkins()}
					{if !empty($CUSTOM_SKINS)}
					<div class="form-group">
						<select id="skin" name="skin" class="form-control" style="padding: 5px 0;">
							<option value="">Default Skin</option>
							{foreach item=CUSTOM_SKIN from=$CUSTOM_SKINS}
							<option value="{$CUSTOM_SKIN}">{$CUSTOM_SKIN}</option>
							{/foreach}
						</select>
					</div>
					{/if}
					
					<button type="submit" class="btn-primary">Sign In</button>
					
					<div class="forgot-password">
						<a href="#">Forgot Password?</a>
					</div>
				</form>
			</div>

			<div id="forgotPasswordDiv" class="hide">
				<form action="forgotPassword.php" method="POST">
					<h4 style="text-align:center; margin-bottom:20px;">Recover Password</h4>
					<div class="form-group">
						<input id="fusername" type="text" name="username" class="form-control" placeholder=" " required>
						<label class="form-label">Username</label>
					</div>
					<div class="form-group">
						<input id="email" type="email" name="emailId" class="form-control" placeholder=" " required>
						<label class="form-label">Email</label>
					</div>
					
					<button type="submit" class="btn-primary">Submit</button>
					
					<div class="forgot-password">
						<a href="#">Back to Login</a>
					</div>
				</form>
			</div>

			<div id="otpFormDiv" class="hide">
				<form>
					<input type="hidden" name="module" value="Users"/>
					<input type="hidden" name="action" value="LoginOtp"/>
					<input type="hidden" name="secret" value="">

					<div class="form-group qr_image hide" style="text-align:center;">
						<span style="font-size:14px;">Scan with Google Authenticator:</span>
						<img src="" alt="" id="qr_image" style="max-width:100%; margin-top:10px;">
					</div>

					<div class="form-group">
						<input id="otp-code" type="text" name="otp_code" class="form-control" placeholder=" " required>
						<label class="form-label">OTP Code</label>
					</div>

					<button type="submit" class="btn-primary">Verify</button>
				</form>
			</div>

		</div>
		
		<div style="position: absolute; bottom: 10px; width: 100%; text-align: center;">
			<div class="footer-links">
				<p>&copy; {$smarty.now|date_format:"%Y"} Praesto. All rights reserved.</p>
			</div>
		</div>
	</div>

	<script>
		jQuery(document).ready(function () {
			var validationMessage = jQuery('#validationMessage');
			var forgotPasswordDiv = jQuery('#forgotPasswordDiv');
			var loginFormDiv = jQuery('#loginFormDiv');

			// Toggle Forgot Password
			loginFormDiv.find('.forgot-password a').click(function (e) {
				e.preventDefault();
				loginFormDiv.addClass('hide');
				forgotPasswordDiv.removeClass('hide');
				validationMessage.text('').removeClass('error success');
			});

			forgotPasswordDiv.find('.forgot-password a').click(function (e) {
				e.preventDefault();
				forgotPasswordDiv.addClass('hide');
				loginFormDiv.removeClass('hide');
				validationMessage.text('').removeClass('error success');
			});

			// Form Validation
			loginFormDiv.find('button').on('click', function () {
				var username = loginFormDiv.find('#username').val();
				var password = jQuery('#password').val();
				if (username === '' || password === '') {
					validationMessage.addClass('error').text('Please enter username and password');
					return false;
				}
				return true;
			});

			// Login with AJAX
			jQuery('#loginFormDiv form').on('submit', function (e) {
				e.preventDefault();
				jQuery.ajax({
					type: 'POST',
					url: "index.php",
					data: jQuery(this).serialize(),
					dataType: 'json',
					success: function (data) {
						if (data.status === 'success') {
							validationMessage.text('').removeClass('error');
							if (data.is_use_two_factor_auth) {
								$('#loginFormDiv').addClass('hide');
								$('#otpFormDiv').removeClass('hide');
							}
							if (data.qr_image) {
								$('.qr_image').removeClass('hide');
								$('#qr_image').attr('src', data.qr_image);
								$('#otpFormDiv input[name=secret]').val(data.secret);
							}
							if (data.url) {
								window.location.href = data.url;
							}
						} else {
							validationMessage.addClass('error').text(data.error_message || 'Login failed');
						}
					}
				});
			});
			// OTP form
			jQuery('#otpFormDiv form').on('submit', function (e) {
			    e.preventDefault();
			    validationMessage.addClass('hide').removeClass('error success').text('');

			    $.ajax({
			        type: 'POST',
			        url: "index.php",
			        data: $(this).serialize() +
			              '&username=' + $('#username').val() +
			              '&password=' + $('#password').val() +
			              '&secret=' + $('#otpFormDiv input[name=secret]').val(),
			        dataType: 'json',
			        success: function (data) {
			            if (data.status === 'success') {
			                window.location.href = data.url;
			            } else {
			                validationMessage.removeClass('hide').addClass('error').text('Wrong code');
			                $('#otp-code').val('');
			            }
			        }
			    });
			});
			
			// Init
			// Ensure hide class works if it was missing before layout repaint
			loginFormDiv.find('#username').focus();
		});
	</script>
{/strip}
