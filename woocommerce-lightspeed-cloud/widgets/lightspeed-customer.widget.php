<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_action( 'widgets_init', 'wclsc_register_customer_widget' );

function wclsc_register_customer_widget(){
	register_widget( 'WCLSC_Customer_Widget' );
}

add_action( 'init', 'wclsc_customer_widget_redirect' );

function wclsc_customer_widget_redirect(){
	$widget_option = isset( $_POST['wcslc_widget_option'] ) ? $_POST['wcslc_widget_option'] : NULL;
	$widget_instance = isset( $_POST['wcslc_widget_instance'] ) ? $_POST['wcslc_widget_instance'] : NULL;

	if( ! $widget_option || ! $widget_instance )
		return;

	$widget = get_option( $widget_option );

	if( isset( $widget[ $widget_instance ] ) ){
		$instance = $widget[ $widget_instance ];
		if( isset( $instance['redirect'] ) && $instance['redirect'] ){
			$wclsc_cw = new WCLSC_Customer_Widget;

			if( ! $wclsc_cw->wclsc_processed ){
				$errors = $wclsc_cw->_check_lightspeed_form_errors( $instance );

				if( $wclsc_cw->wclsc_error )
					$errors[] = '__other__';

				if( count( $errors ) <= 0 )
					$wclsc_cw->_process_lightspeed_form( $instance );

				$wclsc_cw->wclsc_processed = true;
			}
		}
	}
}

if( class_exists( 'WCLSC_Customer_Widget' ) ) return;

class WCLSC_Customer_Widget extends WP_Widget {

	var $wclsc_error;

	var $wclsc_processed = false;

	/**
	 * Register widget with WordPress.
	 */
	function __construct() {
		parent::__construct(
			'WCLSC_Customer_Widget',
			__( 'LightSpeed - Create Customer', 'wclsc' ),
			array( 'description' => __( 'Displays a subscribe form to create a new LightSpeed Customer', 'wclsc' ), )
		);
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		$widget_location = isset( $_POST['wcslc_widget_location'] ) ? $_POST['wcslc_widget_location'] : NULL;

		if( $widget_location && $widget_location == $args['id'] ){

			$errors = $this->_check_lightspeed_form_errors( $instance );

			if( $this->wclsc_error )
				$errors[] = '__other__';

			$processed = count( $errors ) <= 0 ? $this->_process_lightspeed_form( $instance ) : false;

		} else {
			$errors = array();
			$processed = false;
		}

		$output = $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			$output .= $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		$form = locate_template( array( 'wclsc-customer-widget.php', 'wclsc/customer-widget.php' ) );
		if( ! $form )
			$form = WCLSC_PATH . 'views/widgets/customer-widget.php';

		$button_text = isset( $instance['button_text'] ) ? $instance['button_text'] : __( 'Submit', 'wclsc' );
		$display_name = isset( $instance['display_name'] ) ? $instance['display_name'] : false;
		$promo_html = isset( $instance['promo_html'] ) ? $instance['promo_html'] : '';
		$confirmation = isset( $instance['confirmation'] ) ? $instance['confirmation'] : '';
		$redirect = isset( $instance['redirect'] ) ? $instance['redirect'] : false;
		$post_url = $redirect ? '' : '#wclsc-customer-widget';

		$widget_option = $this->option_name;
		$widget_instance = str_replace( preg_replace( '/widget_/', '', $this->option_name, 1 ) . '-', '', $args['widget_id'] );
		$widget_location = $args['id'];

		// Labels
		$email_label = apply_filters( 'wclsc_customer_email_label', __( 'Email Address', 'wclsc' ) );
		$fname_label = apply_filters( 'wclsc_customer_firstname_label', __( 'First Name', 'wclsc' ) );
		$lname_label = apply_filters( 'wclsc_customer_lastname_label', __( 'Last Name', 'wclsc' ) );

		ob_start();
		$view = include( $form );
		$output .= ob_get_clean();

		$output .= $args['after_widget'];

		echo $output;
	}

	public function _process_lightspeed_form( $instance ){
		$processed = false;

		if( $this->wclsc_processed )
			return $processed;

		if( isset( $_POST ) && is_array( $_POST ) && isset( $_POST['wcslc_create_customer'] ) ){

			if( ! isset( $_POST['wclsc_customer_email'] ) || ! $_POST['wclsc_customer_email'] )
				return $processed;

			$email = sanitize_email( $_POST['wclsc_customer_email'] );
			$fname = isset( $_POST['wclsc_customer_firstname'] ) && $_POST['wclsc_customer_firstname'] ? sanitize_text_field( $_POST['wclsc_customer_firstname'] ) : '';
			$lname = isset( $_POST['wclsc_customer_lastname'] ) && $_POST['wclsc_customer_lastname'] ? sanitize_text_field( $_POST['wclsc_customer_lastname'] ) : '';

			$exists = get_user_by( 'email', $email );
			if( $exists )
				return $processed;

			$password = wp_generate_password();
			$user_id = wp_create_user( $email, $password, $email );
			if( is_int( $user_id ) )
				wp_update_user( array( 'ID' => $user_id, 'role' => get_option('default_role') ) );

			if( class_exists( 'Lightspeed_Cloud_API' ) ){

				$lightspeed = new Lightspeed_Cloud_API();

				# this takes too long/unreliable
				/*if( $lightspeed->email_exists( $email ) ){
					$this->wclsc_error = __( 'Email Address is already subscribed.', 'wclsc' );
					return false;
				}*/

				$customer_data = array(
					'Contact' => array(
						'Emails' => array(
							'ContactEmail' => array(
								'address' => $email,
								'useType' => 'Primary'
							)
						)
					)
				);

				if( $fname )
					$customer_data['firstName'] = $fname;

				if( $lname )
					$customer_data['lastName'] = $lname;

				$customer = $lightspeed->create_customer( $customer_data );

				if( $customer )
					$processed = true;
				else
					$this->wclsc_error = __( 'There was an error creating the customer in LightSpeed', 'wclsc' );

				$this->wclsc_processed = true;

				if( ! headers_sent() ){
					if( isset( $instance['redirect'] ) && $instance['redirect'] ){
						wp_redirect( $instance['redirect'] );
						exit();
					}
				}

			}
		}

		return $processed;
	}

	function _check_lightspeed_form_errors( $instance ){
		$errors = array();

		if( isset( $_POST ) && is_array( $_POST ) && isset( $_POST['wcslc_create_customer'] ) ){

			if( ! isset( $_POST['wclsc_customer_email'] ) || ! $_POST['wclsc_customer_email'] ){
				$errors[] = 'email';
			} else {
				$email = sanitize_email( $_POST['wclsc_customer_email'] );
				$exists = get_user_by( 'email', $email );
				if( is_a( $exists, 'WP_User' ) )
					$this->wclsc_error = __( 'The email address you entered has already been registered.', 'wclsc' );
			}

			$display_name = isset( $instance['display_name'] ) ? $instance['display_name'] : false;

			if( $display_name ){
				if( ! isset( $_POST['wclsc_customer_fname'] ) || ! $_POST['wclsc_customer_fname'] )
					$errors[] = 'fname';

				if( ! isset( $_POST['wclsc_customer_lname'] ) || ! $_POST['wclsc_customer_lname'] )
					$errors[] = 'lname';
			}

		}

		return $errors;
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$title = isset( $instance[ 'title' ] ) ? $instance[ 'title' ] : __( 'Subscribe', 'wclsc' );
		$display_name = isset( $instance[ 'display_name' ] ) ? $instance['display_name'] : false;
		$button_text = isset( $instance[ 'button_text' ] ) ? $instance['button_text'] : __( 'Submit', 'wclsc' );
		$promo_html = isset( $instance[ 'promo_html' ] ) ? $instance['promo_html'] : '';
		$redirect = isset( $instance[ 'redirect' ] ) ? $instance['redirect'] : '';
		$confirmation = isset( $instance[ 'confirmation' ] ) ? $instance['confirmation'] : __( 'Thank you for subscribing.', 'wclsc' );
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'wclsc' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'promo_html' ); ?>"><?php _e( 'Promo HTML:', 'wclsc' ); ?></label>
			<textarea class="widefat" id="<?php echo $this->get_field_id( 'promo_html' ); ?>" name="<?php echo $this->get_field_name( 'promo_html' ); ?>"><?php echo esc_textarea( $promo_html ); ?></textarea>
		</p>
		<p>
			<label><input type="checkbox" id="<?php echo $this->get_field_id( 'display_name' ); ?>" name="<?php echo $this->get_field_name( 'display_name' ); ?>" value="1"<?php if( $display_name ) echo ' checked="checked"'; ?> /> <?php _e( 'Display Name Field', 'wclsc' ); ?></label>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'button_text' ); ?>"><?php _e( 'Button Text:', 'wclsc' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'button_text' ); ?>" name="<?php echo $this->get_field_name( 'button_text' ); ?>" type="text" value="<?php echo esc_attr( $button_text ); ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'redirect' ); ?>"><?php _e( 'Redirect URL:', 'wclsc' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'redirect' ); ?>" name="<?php echo $this->get_field_name( 'redirect' ); ?>" type="text" value="<?php echo esc_attr( $redirect ); ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id( 'confirmation' ); ?>"><?php _e( 'Confirmation:', 'wclsc' ); ?></label>
			<textarea class="widefat" id="<?php echo $this->get_field_id( 'confirmation' ); ?>" name="<?php echo $this->get_field_name( 'confirmation' ); ?>"><?php echo esc_textarea( $confirmation ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = $new_instance['title'] ? strip_tags( $new_instance['title'] ) : '';
		$instance['display_name'] = isset( $new_instance['display_name'] ) ? $new_instance['display_name'] : 0;
		$instance['button_text'] = isset( $new_instance['button_text'] ) ? $new_instance['button_text'] : __( 'Submit', 'wclsc' );
		$instance['promo_html'] = isset( $new_instance['promo_html'] ) ? $new_instance['promo_html'] : '';
		$instance['redirect'] = isset( $new_instance['redirect'] ) ? $new_instance['redirect'] : '';
		$instance['confirmation'] = isset( $new_instance['confirmation'] ) ? $new_instance['confirmation'] : '';

		return $instance;
	}
}
