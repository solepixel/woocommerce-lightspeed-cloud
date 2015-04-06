; var wclsc_update_sync, wclsc_update_status;

jQuery(function($){

	function PopupCenter(url, title, w, h) {
		// Fixes dual-screen position                         Most browsers      Firefox
		var dualScreenLeft = window.screenLeft != undefined ? window.screenLeft : screen.left;
		var dualScreenTop = window.screenTop != undefined ? window.screenTop : screen.top;

		width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
		height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

		var left = ((width / 2) - (w / 2)) + dualScreenLeft;
		var top = ((height / 2) - (h / 2)) + dualScreenTop;
		var newWindow = window.open(url, title, 'scrollbars=yes, chrome=yes, menubar=no, toolbar=no, width=' + w + ', height=' + h + ', top=' + top + ', left=' + left);

		// Puts focus on the newWindow
		if (window.focus) {
			newWindow.focus();
		}
	}


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
			url: wclsc_vars.ajax_url + '?wclsc=lookup-account-id',
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
			url: wclsc_vars.ajax_url + '?wclsc=clear-error-log',
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



	$('#wclsc_oauth').on('click', function(e){
		e.preventDefault();

		var href = $(this).attr('href'),
			width = $(this).data('width') ? $(this).data('width') : 600,
			height = $(this).data('height') ? $(this).data('height') : 600;

		PopupCenter( href, 'wclsc_oauth', width, height );

		$(this).hide();
		$('#wclsc_oauth_code').show();

		return false;
	});

	if( $('#ls-product-sync').length ){
		var product_id = $('input[type="hidden"]#post_ID').val();

		if( product_id ){
			$('#ls-product-sync').html( '<div class="spinner" style="display:block;"></div>' );
			$.ajax({
				data: {
					action: 'wclsc_single_product_sync',
					product_id: product_id
				},
				dataType: 'json',
				url: wclsc_vars.ajax_url + '?wclsc=single-product-sync',
				type: 'post',
				success: function( response ){
					$('#ls-product-sync').css('padding-bottom','13px').html( response.html );

					$('.wclsc-sync-product').on('click', function(e){
						e.preventDefault();

						var $btn = $(this),
							item_id = $btn.data('item-id');

						if( ! item_id ){
							alert( 'Error: LightSpeed Item ID not found.' );
							$(this).remove();
							return false;
						}

						if( ! confirm( 'Syncing this item will overwrite any changes made in WooCommerce and will import data from LightSpeed. Are you sure you want to continue?') )
							return false;

						$btn.html( 'Syncing...' );

						$.ajax({
							data: {
								action: 'wclsc_force_sync_product',
								item_id: item_id
							},
							dataType: 'json',
							url: wclsc_vars.ajax_url + '?wclsc=force-single-product-sync',
							type: 'post',
							success: function( response ){
								if( response && response.success ){
									$btn.html( 'Synced! Redirecting...' );
									if( response.redirect ){
										window.location.replace( response.redirect );
									} else {
										location.reload();
									}
								} else {
									$btn.html( 'Sync Failed.' );
								}
							}
						});
					});
				}
			});
		}
	}


	$('.wclsc-stop-sync').live('click', function(e){
		e.preventDefault();
		var $parent = $(this).parents('.wclsc-sync');
		$parent.removeClass('syncing');

		$.ajax({
			data: {
				action: 'wclsc_abort_product_sync'
			},
			dataType: 'json',
			url: wclsc_vars.ajax_url + '?wclsc=abort-product-sync',
			type: 'post',
			success: function( response ){
				if( response.html )
					$parent.html( response.html );
			}
		});

		return false;
	});

	var $sync_element = $('.wclsc-sync.syncing');
	if( $sync_element.length )
		wclsc_update_sync( $sync_element );

	$('.wclsc-start-sync').on('click', function(e){
		e.preventDefault();

		var $parent = $(this).parents('.wclsc-sync');
		$parent.addClass('syncing').html('<div class="sync-status">Sync Starting...</div>');

		$.ajax({
			data: {
				action: 'wclsc_all_product_sync'
			},
			dataType: 'json',
			url: wclsc_vars.ajax_url + '?wclsc=all-product-sync',
			type: 'post',
			success: function( response ){
				if( response && response.html )
					$parent.html( response.html );

				// poll status in 5 seconds
				wclsc_update_sync( $parent );
			}
		});
	});

	$('.wclsc-start-inventory-sync').on('click', function(e){
		e.preventDefault();

		var $btn = $(this);
		$btn.html('Syncing Inventory...');

		$.ajax({
			data: {
				action: 'wclsc_product_inventory_sync'
			},
			dataType: 'json',
			url: wclsc_vars.ajax_url + '?wclsc=product-inventory-sync',
			type: 'post',
			success: function( response ){
				if( response && response.html )
					$parent.html( response.html );

				$btn.html('Inventory Sync Started.');
			}
		});
	});

	function wclsc_update_sync( $parent ){
		(function wclsc_update_status() {
			if( ! $parent.hasClass('syncing') )
				return;

			setTimeout(function() {
				$.ajax({
					data: {
						action: 'wclsc_trigger_cron'
					},
					dataType: 'json',
					url: wclsc_vars.ajax_url + '?wclsc=trigger-cron',
					success: function( response ){
						if( response && response.cron )
							console.log( response.cron );
					}
				});

				$.ajax({
					data: {
						action: 'wclsc_get_sync_status'
					},
					dataType: 'json',
					url: wclsc_vars.ajax_url + '?wclsc=sync-status',
					type: 'post',
					success: function( response ){
						if( response && response.html )
							$parent.html( response.html );

						if( ! response.html || ( typeof response.complete !== 'undefined' && response.complete == '1' ) ){
							$parent.removeClass('syncing');
						}
					},
					complete: wclsc_update_status
				});
			}, 5000);

		})();
	}

});
