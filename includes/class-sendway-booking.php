<?php
/**
 * Books the parcel when an order reaches the configured status, or asks SendWay
 * for a price when it is something SendWay prices by hand.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Booking {

	const MAX_COD = 250000;

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_book' ), 20, 4 );
		add_action( 'woocommerce_order_action_sendway_book', array( __CLASS__, 'book_now' ) );
		add_action( 'woocommerce_order_action_sendway_accept_quote', array( __CLASS__, 'accept_quote' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_action' ) );
	}

	/** "Send with SendWay" for orders that need a retry, and "Accept" once a quote is ready. */
	public static function order_action( $actions ) {
		global $theorder;
		if ( ! $theorder || $theorder->get_meta( '_sendway_parcel_id' ) ) {
			return $actions;
		}
		if ( 'QUOTED' === $theorder->get_meta( '_sendway_quote_status' ) ) {
			$actions['sendway_accept_quote'] = sprintf(
				/* translators: %s: quoted total in KES */
				__( 'Accept SendWay quote (KES %s)', 'sendway' ),
				number_format_i18n( (float) $theorder->get_meta( '_sendway_quote_total' ) )
			);
			return $actions;
		}
		if ( ! in_array( $theorder->get_meta( '_sendway_quote_status' ), array( 'PENDING' ), true ) ) {
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

	/** The shipping line the customer chose, when it is SendWay. */
	private static function shipping_item( $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( 0 === strpos( (string) $item->get_method_id(), 'sendway' ) ) {
				return $item;
			}
		}
		return null;
	}

	/** Destination fields for the API, or a WP_Error saying what is missing. */
	private static function destination( $order ) {
		if ( 'DOOR' === $order->get_meta( '_sendway_delivery_mode' ) ) {
			$lat = $order->get_meta( '_sendway_door_lat' );
			$lng = $order->get_meta( '_sendway_door_lng' );
			if ( '' === $lat || '' === $lng ) {
				return new WP_Error( 'sendway_no_address', __( 'SendWay: no delivery address on this order, so nothing was booked.', 'sendway' ) );
			}
			$dest = array(
				'delivery_mode' => 'DOOR',
				'dest_lat'      => (float) $lat,
				'dest_lng'      => (float) $lng,
				'dest_address'  => (string) $order->get_meta( '_sendway_door_address' ),
			);
			$directions = trim( (string) $order->get_meta( '_sendway_door_directions' ) );
			if ( '' !== $directions ) {
				$dest['dest_directions'] = mb_substr( $directions, 0, 120 );
			}
			return $dest;
		}

		$town_id = $order->get_meta( '_sendway_town_id' );
		if ( ! $town_id ) {
			return new WP_Error( 'sendway_no_town', __( 'SendWay: no collection town on this order, so nothing was booked.', 'sendway' ) );
		}
		return array(
			'delivery_mode' => 'STATION',
			'dest_town_id'  => $town_id,
		);
	}

	private static function payload( $order, $parcel, $destination ) {
		$payload = array_merge(
			$parcel,
			$destination,
			array(
				'recipient_name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(),
				'recipient_phone' => $order->get_billing_phone(),
				'description'     => self::describe( $order ),
				'origin_address'  => get_option( 'sendway_origin_address', '' ),
				'origin_lat'      => (float) get_option( 'sendway_origin_lat', -1.2864 ),
				'origin_lng'      => (float) get_option( 'sendway_origin_lng', 36.8172 ),
				'reference'       => 'wc-' . $order->get_id(),
			)
		);

		if ( $order->get_billing_email() ) {
			$payload['recipient_email'] = $order->get_billing_email();
		}

		$sender_phone = preg_replace( '/[\s-]/', '', (string) get_option( 'sendway_sender_phone', '' ) );
		if ( '' !== $sender_phone ) {
			$payload['sender_phone'] = $sender_phone;
		}
		$shop_name = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( mb_strlen( $shop_name ) >= 2 ) {
			$payload['sender_name'] = mb_substr( $shop_name, 0, 120 );
		}

		if ( 'cod' === $order->get_payment_method() && 'yes' === get_option( 'sendway_collect_cod', 'yes' ) ) {
			$payload['cod_amount'] = min( self::MAX_COD, round( (float) $order->get_total(), 2 ) );
		}

		$directions = trim( (string) get_option( 'sendway_origin_directions', '' ) );
		if ( '' !== $directions ) {
			$payload['origin_directions'] = mb_substr( $directions, 0, 120 );
		}
		return $payload;
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
		if ( in_array( $order->get_meta( '_sendway_quote_status' ), array( 'PENDING', 'QUOTED' ), true ) ) {
			return; // Waiting on, or holding, a price from SendWay.
		}

		$destination = self::destination( $order );
		if ( is_wp_error( $destination ) ) {
			if ( $manual ) {
				$order->add_order_note( $destination->get_error_message() );
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

		$payload  = self::payload( $order, $parcel, $destination );
		$shipping = self::shipping_item( $order );
		if ( $shipping && 'yes' === $shipping->get_meta( 'sendway_needs_quote' ) ) {
			self::request_quote( $order, $payload );
			return;
		}

		$res = SendWay_API::create_parcel( $payload + array( '_idempotency_key' => 'wc-order-' . $order->get_id() ) );

		if ( is_wp_error( $res ) ) {
			if ( 'quote_required' === $res->get_error_code() ) {
				self::request_quote( $order, $payload );
				return;
			}
			/* translators: %s: error message from SendWay */
			$order->add_order_note( sprintf( __( 'SendWay could not book this parcel: %s', 'sendway' ), $res->get_error_message() ) );
			$order->save();
			return;
		}

		self::record_parcel( $order, $res );
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

	private static function request_quote( $order, $payload ) {
		$attempt = (int) $order->get_meta( '_sendway_quote_attempts' ) + 1;
		$order->update_meta_data( '_sendway_quote_attempts', $attempt );
		$res = SendWay_API::create_quote_request( $payload + array( '_idempotency_key' => 'wc-quote-order-' . $order->get_id() . '-' . $attempt ) );
		if ( is_wp_error( $res ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message from SendWay */
					__( 'SendWay prices this parcel by hand, but the quote request did not go through: %s Contact SendWay on +254 117 390922.', 'sendway' ),
					$res->get_error_message()
				)
			);
			$order->save();
			return;
		}

		$order->update_meta_data( '_sendway_quote_id', isset( $res['id'] ) ? $res['id'] : '' );
		$order->update_meta_data( '_sendway_quote_reference', isset( $res['reference'] ) ? $res['reference'] : '' );
		$order->update_meta_data( '_sendway_quote_status', empty( $res['livemode'] ) ? 'TEST' : 'PENDING' );
		$order->add_order_note(
			empty( $res['livemode'] )
				? __( 'SendWay prices this parcel by hand. Test mode: the quote request was checked but not sent to the SendWay team.', 'sendway' )
				: sprintf(
					/* translators: %s: quote reference */
					__( 'SendWay prices this parcel by hand, so we asked for a quote (%s). You will get a note here with the price, then accept it from Order actions.', 'sendway' ),
					isset( $res['reference'] ) ? $res['reference'] : '?'
				)
		);
		$order->save();
	}

	public static function apply_quote( $order, $quote ) {
		$status = isset( $quote['status'] ) ? $quote['status'] : '';
		$order->update_meta_data( '_sendway_quote_status', $status );
		if ( 'QUOTED' === $status ) {
			$order->update_meta_data( '_sendway_quote_total', isset( $quote['total'] ) ? $quote['total'] : '' );
			$order->update_meta_data( '_sendway_quote_expires', isset( $quote['expires_at'] ) ? $quote['expires_at'] : '' );
		}
	}

	public static function accept_quote( $order ) {
		$quote_id = $order->get_meta( '_sendway_quote_id' );
		if ( ! $quote_id || $order->get_meta( '_sendway_parcel_id' ) ) {
			return;
		}

		$res = SendWay_API::accept_quote_request( $quote_id );
		if ( is_wp_error( $res ) ) {
			$latest = SendWay_API::quote_request( $quote_id );
			if ( ! is_wp_error( $latest ) ) {
				self::apply_quote( $order, $latest );
			}
			/* translators: %s: error message from SendWay */
			$order->add_order_note( sprintf( __( 'SendWay could not book at the quoted price: %s', 'sendway' ), $res->get_error_message() ) );
			$order->save();
			return;
		}

		$order->update_meta_data( '_sendway_quote_status', 'ACCEPTED' );
		self::record_parcel( $order, $res );
		$order->add_order_note(
			sprintf(
				/* translators: 1: total in KES, 2: tracking number */
				__( 'SendWay quote accepted at KES %1$s and the parcel is booked. Tracking %2$s.', 'sendway' ),
				isset( $res['total_fee'] ) ? number_format_i18n( (float) $res['total_fee'] ) : '?',
				isset( $res['tracking_number'] ) ? $res['tracking_number'] : '?'
			)
		);
		$order->save();
	}

	private static function record_parcel( $order, $res ) {
		$order->update_meta_data( '_sendway_parcel_id', isset( $res['id'] ) ? $res['id'] : '' );
		$order->update_meta_data( '_sendway_tracking', isset( $res['tracking_number'] ) ? $res['tracking_number'] : '' );
		$order->update_meta_data( '_sendway_status', isset( $res['status'] ) ? $res['status'] : '' );
		$order->update_meta_data( '_sendway_livemode', ! empty( $res['livemode'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_sendway_total', isset( $res['total_fee'] ) ? $res['total_fee'] : '' );
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
