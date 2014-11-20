<a href="#lightspeed-order-details"><?php _e( 'Show LightSpeed Order Details', 'wclsc' ); ?></a>

<div class="lightspeed-order-details" style="display:none;">

	<div class="ls-order-summary">
		<strong><?php _e( 'LightSpeed Order Summary', 'wclsc' ); ?>:</strong><br />
		<?php _e( 'LightSpeed Sale ID', 'wclsc' ); ?>: <span><?php echo $sale_id; ?></span><br />
		<?php _e( 'LightSpeed Payment ID', 'wclsc' ); ?>: <span><?php echo $payment_id . ' (' . $stored . ')'; ?></span><br />

		calcTotal: <span><?php echo number_format( $sale->calcTotal, 2 ); ?></span><br />
		calcSubtotal: <span><?php echo number_format( $sale->calcSubtotal, 2 ); ?></span><br />
		calcTaxable: <span><?php echo number_format( $sale->calcTaxable, 2 ); ?></span><br />
		calcNonTaxable: <span><?php echo number_format( $sale->calcNonTaxable, 2 ); ?></span><br />
		calcTax1: <span><?php echo number_format( $sale->calcTax1, 2 ); ?></span><br />
		calcPayments: <span><?php echo number_format( $sale->calcPayments, 2 ); ?></span><br />

		<?php _e( 'Balance', 'wclsc' ); ?>: <span><?php echo number_format( $sale->balance, 2 ); ?></span><br />
		<?php _e( 'Is Complete', 'wclsc' ); ?>?: <span><strong><?php echo ( $sale->completed == 'true' ? 'Yes' : 'No' ); ?></strong></span><br />

		<a href="#lightspeed-sale-object"><?php _e( 'Show LightSpeed Sale Object', 'wclsc' ); ?></a>
	</div>


	<div class="ls-order-log">
		<strong><?php _e( 'LightSpeed Order Log', 'wclsc' ); ?>:</strong>
		<div class="ls-order-log-data">
			<?php if( count( $order_log ) ):
				foreach( $order_log as $time => $data ){
					if( strpos( $time, '|' ) !== false ){
						list( $time, $uniqid ) = explode( '|', $time );
					} ?>
					<div>[<?php echo date( 'Y-m-d H:i:s', $time ); ?>] <?php echo $data; ?></div>
				<?php }
			else:
				_e( 'No Order Log data found.', 'wclsc' );
			endif; ?>
		</div>
	</div>

	<div class="clear"><!-- .clear --></div>

	<div class="lightspeed-sale-object" style="display:none;">
		<strong><?php _e( 'LightSpeed Sale Object', 'wclsc' ); ?>:</strong>
		<pre><?php var_dump( $sale ); ?></pre>
	</div>

</div>
