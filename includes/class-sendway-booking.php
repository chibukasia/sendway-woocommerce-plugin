<?php
/**
 * Books the parcel when an order reaches the configured status.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Booking {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_book' ), 20, 4 );
		add_action( 'woocommerce_order_action_sendway_book', array( __CLASS__, 'book_now' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_action' ) );
	}

	/** A manual "Send with SendWay" action, for orders that need a retry. */
	public static function order_action( $actions ) {
		global $theorder;
		if ( $theorder && ! $theorder->get_meta( '_sendway_parcel_id' ) ) {
			$actions['sendway_book'] = __( 'Send with SendWay', 'sendway' );
		}
		return $actions;
	}

	public static function book_now( $order ) {
		self::book( $order, true );
	}

	public static function maybe_book( $order_id, $from, $to, $order ) {
		$trigger = get_option( 'sendway_book_on_status', 'processing' );
		if ( $to !== $trigger ) {
			return;
		}
		self::book( $order, false );
	}

	/**
	 * Create the parcel. Idempotent on the order id, so a status flapping
	 * between values — or a merchant pressing the manual action twice — cannot
	 * produce two parcels for one order.
	 */
	public static function book( $order, $manual ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( $order->get_meta( '_sendway_parcel_id' ) ) {
			return; // Already booked.
		}
		if ( ! SendWay_API::has_key() ) {
			return;
		}

		$town_id = $order->get_meta( '_sendway_town_id' );
		if ( ! $town_id ) {
			if ( $manual ) {
				$order->add_order_note( __( 'SendWay: no collection town on this order, so nothing was booked.', 'sendway' ) );
			}
			return;
		}

		$weight = self::order_weight_kg( $order );

		$payload = array(
			'recipient_name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(),
			'recipient_phone' => $order->get_billing_phone(),
			'description'     => self::describe( $order ),
			'package_size'    => SendWay_Shipping_Method::size_for_weight( $weight ),
			'weight_kg'       => $weight,
			'declared_value'  => (float) $order->get_subtotal(),
			'delivery_mode'   => 'STATION',
			'dest_town_id'    => $town_id,
			'origin_address'  => get_option( 'sendway_origin_address', '' ),
			'origin_lat'      => (float) get_option( 'sendway_origin_lat', -1.2864 ),
			'origin_lng'      => (float) get_option( 'sendway_origin_lng', 36.8172 ),
			'reference'       => 'wc-' . $order->get_id(),
			// Derived from the order id so a retry replays rather than duplicates.
			'_idempotency_key' => 'wc-order-' . $order->get_id(),
		);

		if ( $order->get_billing_email() ) {
			$payload['recipient_email'] = $order->get_billing_email();
		}

		$res = SendWay_API::create_parcel( $payload );

		if ( is_wp_error( $res ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message from SendWay */
					__( 'SendWay could not book this parcel: %s', 'sendway' ),
					$res->get_error_message()
				)
			);
			$order->save();
			return;
		}

		$order->update_meta_data( '_sendway_parcel_id', isset( $res['id'] ) ? $res['id'] : '' );
		$order->update_meta_data( '_sendway_tracking', isset( $res['tracking_number'] ) ? $res['tracking_number'] : '' );
		$order->update_meta_data( '_sendway_status', isset( $res['status'] ) ? $res['status'] : '' );
		$order->update_meta_data( '_sendway_livemode', ! empty( $res['livemode'] ) ? 'yes' : 'no' );

		$order->add_order_note(
			sprintf(
				/* translators: 1: tracking number, 2: a note when the parcel was simulated */
				__( 'SendWay parcel booked. Tracking %1$s.%2$s', 'sendway' ),
				isset( $res['tracking_number'] ) ? $res['tracking_number'] : '?',
				empty( $res['livemode'] ) ? ' ' . __( '(Test mode — nothing will be collected.)', 'sendway' ) : ''
			)
		);
		$order->save();
	}

	private static function describe( $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
			if ( count( $names ) >= 3 ) {
				break;
			}
		}
		$text = implode( ', ', $names );
		if ( count( $order->get_items() ) > 3 ) {
			$text .= ' …';
		}
		return $text ? mb_substr( $text, 0, 280 ) : __( 'Online order', 'sendway' );
	}

	public static function order_weight_kg( $order ) {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->has_weight() ) {
				$total += (float) wc_get_weight( $product->get_weight(), 'kg' ) * (int) $item->get_quantity();
			}
		}
		return $total > 0 ? round( $total, 2 ) : 1.0;
	}
}
