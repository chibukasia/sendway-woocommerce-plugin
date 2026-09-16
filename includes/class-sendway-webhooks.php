<?php
/**
 * Receives delivery events from SendWay.
 *
 * The shop cannot poll every order every minute, so SendWay pushes here. Every
 * request is signature-checked before it is allowed to touch an order.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Webhooks {

	/** Reject anything signed more than five minutes ago, so a captured call cannot be replayed. */
	const TOLERANCE = 300;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			'sendway/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is the HMAC signature, not a WordPress user.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$secret = trim( (string) get_option( 'sendway_webhook_secret', '' ) );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'not_configured' ), 503 );
		}

		$body      = $request->get_body();
		$signature = $request->get_header( 'sendway_signature' );

		if ( ! self::verify( $secret, $body, (string) $signature ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'malformed' ), 400 );
		}

		// Delivery is at-least-once, so the same event may arrive twice.
		// Recording the id and checking it first is what keeps that harmless.
		$seen_key = 'sendway_evt_' . md5( (string) $event['id'] );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		set_transient( $seen_key, 1, DAY_IN_SECONDS );

		self::apply( $event );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Constant-time check of `t=...,v1=...` over `${timestamp}.${body}`.
	 * Mirrors verifySignature in the SendWay API.
	 */
	public static function verify( $secret, $body, $header ) {
		if ( '' === $header ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $chunk ) {
			$kv = explode( '=', trim( $chunk ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}
		if ( abs( time() - (int) $parts['t'] ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $body, $secret );
		return hash_equals( $expected, $parts['v1'] );
	}

	/** Find the order this event belongs to and move it along. */
	private static function apply( $event ) {
		$parcel = isset( $event['data']['parcel'] ) ? $event['data']['parcel'] : null;
		if ( ! $parcel || empty( $parcel['tracking_number'] ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_sendway_tracking', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $parcel['tracking_number'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( empty( $orders ) ) {
			return;
		}
		$order = $orders[0];

		$order->update_meta_data( '_sendway_status', isset( $parcel['status'] ) ? $parcel['status'] : '' );

		switch ( $event['type'] ) {
			case 'parcel.in_transit':
				$order->add_order_note( __( 'SendWay: parcel collected and on its way.', 'sendway' ) );
				break;

			case 'parcel.at_pickup_station':
				$order->add_order_note(
					sprintf(
						/* translators: %s: town name */
						__( 'SendWay: parcel has arrived in %s and is ready for collection. The customer has been sent a PIN.', 'sendway' ),
						$order->get_meta( '_sendway_town_name' )
					)
				);
				break;

			case 'parcel.delivered':
				$order->add_order_note( __( 'SendWay: parcel collected by the customer.', 'sendway' ) );
				if ( $order->has_status( array( 'processing', 'on-hold' ) ) ) {
					$order->update_status( 'completed', __( 'Delivered by SendWay.', 'sendway' ) );
				}
				break;

			case 'parcel.returned':
				$order->add_order_note( __( 'SendWay: parcel was returned. It was not collected in time.', 'sendway' ) );
				break;

			case 'parcel.cancelled':
				$order->add_order_note( __( 'SendWay: parcel cancelled.', 'sendway' ) );
				break;

			case 'cod.settled':
				$cod = isset( $event['data']['cod'] ) ? $event['data']['cod'] : array();
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount collected, 2: fee, 3: amount credited */
						__( 'SendWay collected KES %1$s on delivery. After a KES %2$s fee, KES %3$s was credited to your SendWay wallet.', 'sendway' ),
						isset( $cod['collected'] ) ? $cod['collected'] : '?',
						isset( $cod['fee'] ) ? $cod['fee'] : '?',
						isset( $cod['credited'] ) ? $cod['credited'] : '?'
					)
				);
				break;
		}

		$order->save();
	}
}
