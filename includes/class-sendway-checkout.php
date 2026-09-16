<?php
/**
 * The pickup-town picker at checkout.
 *
 * This is the piece a generic shipping plugin cannot express: a Kenyan buyer
 * does not want a courier guessing their street, they want to name the town
 * they will collect from. Choosing it also drives the rate.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Checkout {

	const FIELD   = 'sendway_town_id';
	const SESSION = 'sendway_town_id';

	public static function init() {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_picker' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_from_review' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_to_order' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function towns() {
		return SendWay_API::towns();
	}

	public static function town_by_id( $id ) {
		foreach ( self::towns() as $town ) {
			if ( isset( $town['id'] ) && $town['id'] === $id ) {
				return $town;
			}
		}
		return null;
	}

	/** The town currently chosen, from the posted form or the session. */
	public static function chosen_town_id() {
		if ( isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			return WC()->session->get( self::SESSION );
		}
		return null;
	}

	/**
	 * WooCommerce posts the whole checkout form as a serialised string when the
	 * order review refreshes, so the town has to be pulled out of there for the
	 * rate to recalculate as the customer changes it.
	 */
	public static function capture_from_review( $post_data ) {
		parse_str( (string) $post_data, $fields );
		if ( ! empty( $fields[ self::FIELD ] ) && WC()->session ) {
			WC()->session->set( self::SESSION, sanitize_text_field( $fields[ self::FIELD ] ) );
			// The chosen town changes the price, so the cached rate must go.
			WC()->session->set( 'shipping_for_package_0', null );
		}
	}

	public static function render_picker( $checkout ) {
		$towns = self::towns();
		if ( empty( $towns ) ) {
			return; // Not configured, or SendWay unreachable — stay out of the way.
		}

		$grouped = array();
		foreach ( $towns as $town ) {
			$county               = isset( $town['county'] ) ? $town['county'] : __( 'Other', 'sendway' );
			$grouped[ $county ][] = $town;
		}
		ksort( $grouped );

		echo '<div id="sendway-town-field" class="sendway-town-field">';
		echo '<h3>' . esc_html__( 'Where should we deliver?', 'sendway' ) . '</h3>';
		echo '<p class="form-row form-row-wide">';
		echo '<label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Collection town', 'sendway' )
			. ' <abbr class="required" title="' . esc_attr__( 'required', 'sendway' ) . '">*</abbr></label>';
		echo '<select name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" class="select sendway-town-select">';
		echo '<option value="">' . esc_html__( 'Choose your town…', 'sendway' ) . '</option>';

		$selected = self::chosen_town_id();
		foreach ( $grouped as $county => $list ) {
			echo '<optgroup label="' . esc_attr( $county ) . '">';
			foreach ( $list as $town ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $town['id'] ),
					selected( $selected, $town['id'], false ),
					esc_html( $town['name'] )
				);
			}
			echo '</optgroup>';
		}

		echo '</select>';
		echo '<span class="description">' . esc_html__( 'You will collect your parcel from a SendWay agent in this town, using a PIN sent by SMS.', 'sendway' ) . '</span>';
		echo '</p></div>';
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Changing the town must re-price the order, which means asking
		// WooCommerce to refresh the review block.
		$script = <<<'JS'
jQuery(function ($) {
  $(document.body).on('change', '#sendway_town_id', function () {
    $(document.body).trigger('update_checkout');
  });
});
JS;
		wp_add_inline_script( 'wc-checkout', $script );
	}

	public static function validate() {
		if ( empty( self::towns() ) ) {
			return;
		}
		$chosen = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Only required when the customer actually picked SendWay shipping.
		$methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$uses_sendway = false;
		foreach ( $methods as $method ) {
			if ( 0 === strpos( (string) $method, 'sendway' ) ) {
				$uses_sendway = true;
				break;
			}
		}
		if ( ! $uses_sendway ) {
			return;
		}

		if ( '' === $chosen ) {
			wc_add_notice( __( 'Please choose the town you will collect your parcel from.', 'sendway' ), 'error' );
			return;
		}
		if ( ! self::town_by_id( $chosen ) ) {
			wc_add_notice( __( 'That town is no longer available. Please choose another.', 'sendway' ), 'error' );
		}
	}

	public static function persist_to_order( $order, $data ) {
		$chosen = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $chosen ) {
			return;
		}
		$town = self::town_by_id( $chosen );
		$order->update_meta_data( '_sendway_town_id', $chosen );
		if ( $town ) {
			$order->update_meta_data( '_sendway_town_name', $town['name'] );
			$order->update_meta_data( '_sendway_town_county', isset( $town['county'] ) ? $town['county'] : '' );
		}
	}
}
