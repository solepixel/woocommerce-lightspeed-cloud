</div><!-- close reviews div -->

<div class="lightspeed-product-details">
	<div class="ls-product-log">
		<strong><?php _e( 'LightSpeed Product Log', 'wclsc' ); ?>:</strong>
		<div class="ls-product-log-data">
			<?php if( count( $product_log ) ):
				foreach( $product_log as $time => $data ){
					if( strpos( $time, '|' ) !== false ){
						list( $time, $uniqid ) = explode( '|', $time );
					} ?>
					<div>[<?php echo date( 'Y-m-d H:i:s', $time ); ?>] <?php echo $data; ?></div>
				<?php }
			else:
				_e( 'No Product Log data found.', 'wclsc' );
			endif; ?>
		</div>
	</div>
<!-- do not close div ! ! ! -->
