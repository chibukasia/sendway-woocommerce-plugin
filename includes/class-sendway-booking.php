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

		$parcel = SendWay_Parcel::from_order( $order );
		if ( is_wp_error( $parcel ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: why SendWay could not book it online */
					__( 'SendWay did not book this order: %s Contact SendWay on +254 117 390922 to arrange it.', 'sendway' ),
					$parcel->get_error_message()
				)
			);
			$order->save();
			return;
		}

		$payload = array_merge(
			$parcel,
			array(
				'recipient_name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(),
				'recipient_phone' => $order->get_billing_phone(),
				'description'     => self::describe( $order ),
				'delivery_mode'   => 'STATION',
				'dest_town_id'    => $town_id,
				'origin_address'  => get_option( 'sendway_origin_address', '' ),
				'origin_lat'      => (float) get_option( 'sendway_origin_lat', -1.2864 ),
				'origin_lng'      => (float) get_option( 'sendway_origin_lng', 36.8172 ),
				'reference'       => 'wc-' . $order->get_id(),
				// Derived from the order id so a retry replays rather than duplicates.
				'_idempotency_key' => 'wc-order-' . $order->get_id(),
			)
		);

		if ( $order->get_billing_email() ) {
			$payload['recipient_email'] = $order->get_billing_email();
		}

		$res = SendWay_API::create_parcel( $payload );

		if ( is_wp_error( $res ) ) {
			$note = 'quote_required' === $res->get_error_code()
				? __( 'SendWay prices this parcel by hand, so it was not booked automatically: %s Contact SendWay on +254 117 390922 for a quote.', 'sendway' )
				/* translators: %s: error message from SendWay */
				: __( 'SendWay could not book this parcel: %s', 'sendway' );
			$order->add_order_note( sprintf( $note, $res->get_error_message() ) );
			$order->save();
			return;
		}

		$order->update_meta_data( '_sendway_parcel_id', isset( $res['id'] ) ? $res['id'] : '' );
		$order->update_meta_data( '_sendway_tracking', isset( $res['tracking_number'] ) ? $res['tracking_number'] : '' );
		$order->update_meta_data( '_sendway_status', isset( $res['status'] ) ? $res['status'] : '' );
		$order->update_meta_data( '_sendway_livemode', ! empty( $res['livemode'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_sendway_total', isset( $res['total_fee'] ) ? $res['total_fee'] : '' );

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

	/** Kept for older callers; the logic now lives in SendWay_Parcel. */
	public static function order_weight_kg( $order ) {
		$parcel = SendWay_Parcel::from_order( $order );
		return is_wp_error( $parcel ) || empty( $parcel['weight_kg'] ) ? 1.0 : $parcel['weight_kg'];
	}
}
