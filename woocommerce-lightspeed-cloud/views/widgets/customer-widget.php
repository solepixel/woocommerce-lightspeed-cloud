<a name="wclsc-customer-widget" id="wclsc-customer-widget"></a>
<?php if( $processed && $confirmation ): ?>
	<div class="wclsc-customer-widget confirmation">
		<?php echo $confirmation; ?>
	</div>
<?php else: ?>
	<form class="wclsc-customer-widget" method="post" action="<?php echo $post_url; ?>">

		<?php if( $promo_html ): ?>
			<div class="promo-html">
				<?php echo $promo_html; ?>
			</div>
		<?php endif; ?>

		<?php if( count( $errors ) ): ?>
			<?php if( in_array( '__other__', $errors ) && $this->wclsc_error ): ?>
				<div class="error other-error"><?php echo $this->wclsc_error; ?></div>
			<?php else: ?>
				<div class="error required"><?php _e( 'Please fill in all fields.', 'wclsc' ); ?></div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if( $display_name ): ?>
			<p class="firstname<?php if( in_array( 'fname', $errors ) ) echo ' error'; ?>"><label>
				<span class="label"><?php echo $fname_label; ?></span>
				<span class="field"><input type="text" name="wclsc_customer_firstname" /></span>
			</label></p>
			<p class="lastname<?php if( in_array( 'lname', $errors ) ) echo ' error'; ?>"><label>
				<span class="label"><?php echo $lname_label; ?></span>
				<span class="field"><input type="text" name="wclsc_customer_lastname" /></span>
			</label></p>
		<?php endif; ?>

		<p class="email<?php if( in_array( 'email', $errors ) ) echo ' error'; ?>"><label>
			<span class="label"><?php echo $email_label; ?></span>
			<span class="field"><input type="email" name="wclsc_customer_email" /></span>
		</label></p>

		<input type="hidden" name="wcslc_create_customer" value="1" />
		<input type="hidden" name="wcslc_widget_option" value="<?php echo $widget_option; ?>" />
		<input type="hidden" name="wcslc_widget_instance" value="<?php echo $widget_instance; ?>" />
		<input type="hidden" name="wcslc_widget_location" value="<?php echo $widget_location; ?>" />
		<input type="submit" class="button" value="<?php echo esc_attr( $button_text ); ?>" />

	</form>
<?php endif; ?>
