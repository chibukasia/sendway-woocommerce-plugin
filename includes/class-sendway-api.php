<?php
/**
 * Thin client for the SendWay v1 API.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_API {

	const BASE = 'https://api.shopinn.co.ke/api/sendway/v1';

	/** Towns change rarely and are needed on every checkout render. */
	const TOWNS_TRANSIENT = 'sendway_towns';
	const TOWNS_TTL       = HOUR_IN_SECONDS * 6;

	/** A quote depends only on the destination and the cart weight. */
	const QUOTE_TTL = MINUTE_IN_SECONDS * 15;

	public static function key() {
		return trim( (string) get_option( 'sendway_api_key', '' ) );
	}

	public static function has_key() {
		return '' !== self::key();
	}

	public static function is_test_mode() {
		return 0 === strpos( self::key(), 'sw_test_' );
	}

	/**
	 * @param string $method  GET or POST.
	 * @param string $path    Path under /v1, with a leading slash.
	 * @param array  $body    Request body for POST.
	 * @param string $key     Override the stored key (used when validating a new one).
	 * @return array|WP_Error Decoded body, or WP_Error describing what a shop owner should do.
	 */
	public static function request( $method, $path, $body = array(), $key = null ) {
		$key = null === $key ? self::key() : trim( $key );
		if ( '' === $key ) {
			return new WP_Error( 'sendway_no_key', __( 'Add your SendWay API key in the settings first.', 'sendway' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'SendWay-WooCommerce/' . SENDWAY_VERSION,
			),
		);

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $body );
			// Booking is the one call worth making replay-safe: a network drop
			// after the parcel was created must not create a second one.
			if ( isset( $body['_idempotency_key'] ) ) {
				$args['headers']['X-Idempotency-Key'] = $body['_idempotency_key'];
				unset( $body['_idempotency_key'] );
				$args['body'] = wp_json_encode( $body );
			}
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sendway_unreachable', __( 'Could not reach SendWay. Please try again.', 'sendway' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		// The API returns { error: { type, code, message } } — surface the
		// message, because it is written for the person reading it.
		$message = isset( $data['error']['message'] )
			? $data['error']['message']
			: __( 'SendWay could not complete that request.', 'sendway' );
		$slug    = isset( $data['error']['code'] ) ? $data['error']['code'] : 'sendway_error';

		return new WP_Error( $slug, $message, array( 'status' => $code ) );
	}

	/** Pickup towns, cached — the checkout dropdown reads this on every render. */
	public static function towns() {
		$cached = get_transient( self::TOWNS_TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$res = self::request( 'GET', '/towns' );
		if ( is_wp_error( $res ) || empty( $res['data'] ) ) {
			return array();
		}

		set_transient( self::TOWNS_TRANSIENT, $res['data'], self::TOWNS_TTL );
		return $res['data'];
	}

	public static function clear_towns_cache() {
		delete_transient( self::TOWNS_TRANSIENT );
	}

	/**
	 * Price a parcel. Cached per destination and weight so a customer editing
	 * their address does not fire a request per keystroke.
	 */
	public static function quote( $args ) {
		$cache_key = 'sendway_q_' . md5( wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$res = self::request( 'POST', '/quotes', $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		set_transient( $cache_key, $res, self::QUOTE_TTL );
		return $res;
	}

	public static function create_parcel( $args ) {
		return self::request( 'POST', '/parcels', $args );
	}

	public static function current_run() {
		return self::request( 'GET', '/pickup-runs/current' );
	}
}
