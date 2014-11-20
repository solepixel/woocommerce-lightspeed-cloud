jQuery(function($){

	// Create the Lookup button
	var $button = $('<input />')
		.attr( 'type', 'button' )
		.addClass( 'wclsc-lookup-account-id' )
		.addClass( 'button' )
		.attr( 'value', wclsc_vars.lookup_account_text );

	// Bind the click event to the Lookup button
	$button.on( 'click', function(e){
		e.preventDefault();

		// get the API Key from the API Key input field
		var api_key = $('input#' + wclsc_vars.opt_prefix + 'api_key').val();

		// Stop here if no API Key
		if( ! api_key ){
			wclsc_display_message( wclsc_vars.api_key_error, 'error' );
			return false;
		}

		// Hide any message that may have already be triggered
		wclsc_hide_message( 'error' );

		// Create the Spinner
		var $spinner = $('<span />')
			.addClass( 'spinner' )
			.addClass( 'wclsc-spinner' );

		// Attach the spinner
		$( this ).after( $spinner );

		// Handle the AJAX request
		$.ajax({
			url: wclsc_vars.ajax_url,
			type: 'post',
			dataType: 'json',
			data: {
				action: 'wclsc_lookup_account_id',
				api_key: api_key
			},
			success: function( response ){
				// Hide the spinner
				$spinner.remove();

				if( ! response.success ){
					// Display the error if unsuccessful
					wclsc_display_message( response.error_message, 'error' );
				} else {
					// Insert the Account ID into the Account ID field
					$('input#' + wclsc_vars.opt_prefix + 'account_id').val( response.account_id );

					// Alert the user to save changes
					wclsc_display_message( wclsc_vars.account_success_text, 'updated' );
				}
			}
		});

		return false;
	});


	// Append the button after the Account ID field
	$('input#' + wclsc_vars.opt_prefix + 'account_id').after( $button );



	/**
	 * Display an error or success message in the admin
	 * @param  {string} message The message content
	 * @param  {string} type    The class/type of message
	 * @return {void}
	 */
	function wclsc_display_message( message, type ){
		var type = type ? type : 'error';
		var $message = $( 'div.wclsc-' + type );

		var $content = $( '<p />' )
				.html( message );

		if( ! $message.length ){
			var $message = $( '<div />' )
				.addClass( type )
				.addClass( 'wclsc-' + type )
				.hide();
			$( '.wrap h2' ).after( $message );
		}

		if( ! $message.is( ':visible' ) ){
			$message.hide();
		}

		$message.html( $content )
			.fadeIn( 'fast' );
	}

	/**
	 * Hide an error or success message in the admin
	 * @param  {string} type The class/type of message
	 * @return {void}
	 */
	function wclsc_hide_message( type ){
		var type = type ? type : 'error';
		var $message = $( 'div.wclsc-' + type );
		$message.fadeOut( 'fast', function(){
			$message.remove();
		} );
	}

	/**
	 * Bind clear logs button
	 * @param  {event} e Click Event
	 * @return {void}
	 */
	$('input.wclsc-clear-log').on( 'click', function(e){
		e.preventDefault();

		// javascript confirm
		if( ! confirm( wclsc_vars.confirm_clear_log ) )
			return false;

		// clear the logs via ajax
		$.ajax({
			url: wclsc_vars.ajax_url,
			type: 'post',
			dataType: 'json',
			data: {
				action: 'wclsc_clear_error_log'
			},
			success: function( response ){
				if( ! response.success ){
					// Display the error if unsuccessful
					wclsc_display_message( response.error_message, 'error' );
				} else {
					// Remove the value in the log
					$( 'textarea.' + wclsc_vars.opt_prefix + 'error_log' ).replaceWith( $( '<p />' ).html( wclsc_vars.no_errors_message ) );

					// Alert the user the logs have been cleared
					wclsc_display_message( wclsc_vars.logs_clear_text, 'updated' );
				}
			}
		});

	});

	var $details = $('.lightspeed-order-details');

	if( $details.length ){
		$details.appendTo('#order_data');

		$('a[href^="#lightspeed-order-details"]').on( 'click', function(){
			var $details = $('.lightspeed-order-details');

			if( $details.is(':visible') ){
				$(this).text( $(this).text().replace( 'Hide', 'Show' ) );
			} else {
				$(this).text( $(this).text().replace( 'Show', 'Hide' ) );
			}

			$details.slideToggle( 'fast' );

			return false;
		});

		$('a[href^="#lightspeed-sale-object"]').on( 'click', function(){
			var $sale_object = $('.lightspeed-sale-object');

			if( $sale_object.is(':visible') ){
				$(this).text( $(this).text().replace( 'Hide', 'Show' ) );
			} else {
				$(this).text( $(this).text().replace( 'Show', 'Hide' ) );
			}

			$sale_object.slideToggle( 'fast' );

			return false;
		});
	}

});
