<?php
/**
 * Settings screen under WooCommerce → Settings → Shipping → SendWay.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Settings {

	const OPTIONS = array(
		'sendway_api_key',
		'sendway_origin_address',
		'sendway_origin_lat',
		'sendway_origin_lng',
		'sendway_book_on_status',
		'sendway_webhook_secret',
	);

	public static function init() {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'add_section' ) );
		add_action( 'woocommerce_settings_shipping', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_shipping_sendway', array( __CLASS__, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SENDWAY_PATH . 'sendway.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=sendway' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sendway' ) . '</a>' );
		return $links;
	}

	public static function add_section( $sections ) {
		$sections['sendway'] = __( 'SendWay', 'sendway' );
		return $sections;
	}

	public static function render() {
		global $current_section;
		if ( 'sendway' !== $current_section ) {
			return;
		}
		self::connection_notice();
		WC_Admin_Settings::output_fields( self::fields() );
	}

	/** Shows at a glance whether the key works, so nobody debugs this at checkout. */
	private static function connection_notice() {
		if ( ! SendWay_API::has_key() ) {
			echo '<div class="notice notice-info inline"><p>';
			echo wp_kses_post(
				__( 'Paste an API key below to connect. Get one from your <strong>SendWay account → Developers</strong>. Test keys work straight away; live keys need your account approved.', 'sendway' )
			);
			echo '</p></div>';
			return;
		}

		$towns = SendWay_API::towns();
		if ( empty( $towns ) ) {
			echo '<div class="notice notice-error inline"><p>';
			esc_html_e( 'That API key is not working. Check it was copied in full, and that your SendWay account is active.', 'sendway' );
			echo '</p></div>';
			return;
		}

		$mode = SendWay_API::is_test_mode()
			? __( 'Test mode — parcels are simulated and nothing is charged.', 'sendway' )
			: __( 'Live mode — parcels are real and will be collected.', 'sendway' );

		echo '<div class="notice notice-success inline"><p>';
		printf(
			/* translators: 1: number of towns, 2: mode description */
			esc_html__( 'Connected. Delivering to %1$d towns. %2$s', 'sendway' ),
			count( $towns ),
			esc_html( $mode )
		);
		echo '</p></div>';
	}

	public static function fields() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$statuses[ str_replace( 'wc-', '', $slug ) ] = $label;
		}

		return array(
			array(
				'title' => __( 'SendWay', 'sendway' ),
				'type'  => 'title',
				'desc'  => __( 'Collect from your shop in Nairobi and deliver across Kenya. Rates appear at checkout and parcels are booked automatically.', 'sendway' ),
				'id'    => 'sendway_settings',
			),
			array(
				'title'    => __( 'API key', 'sendway' ),
				'id'       => 'sendway_api_key',
				'type'     => 'password',
				'desc_tip' => __( 'From your SendWay account under Developers. Starts with sw_test_ or sw_live_.', 'sendway' ),
				'desc'     => __( 'A test key lets you place practice orders without anything being collected or charged.', 'sendway' ),
			),
			array(
				'title'    => __( 'Collection address', 'sendway' ),
				'id'       => 'sendway_origin_address',
				'type'     => 'text',
				'desc_tip' => __( 'Where a rider comes to collect — your shop or warehouse in Nairobi.', 'sendway' ),
				'css'      => 'width:400px',
			),
			array(
				'title'    => __( 'Collection latitude', 'sendway' ),
				'id'       => 'sendway_origin_lat',
				'type'     => 'text',
				'default'  => '-1.2864',
				'desc_tip' => __( 'Right-click your shop in Google Maps and copy the first number.', 'sendway' ),
			),
			array(
				'title'    => __( 'Collection longitude', 'sendway' ),
				'id'       => 'sendway_origin_lng',
				'type'     => 'text',
				'default'  => '36.8172',
				'desc_tip' => __( 'The second number from the same Google Maps click.', 'sendway' ),
			),
			array(
				'title'    => __( 'Book the parcel when an order is', 'sendway' ),
				'id'       => 'sendway_book_on_status',
				'type'     => 'select',
				'options'  => $statuses,
				'default'  => 'processing',
				'desc_tip' => __( 'The parcel is created when an order reaches this status. Most shops use Processing — the point where payment has cleared.', 'sendway' ),
			),
			array(
				'title'    => __( 'Webhook signing secret', 'sendway' ),
				'id'       => 'sendway_webhook_secret',
				'type'     => 'password',
				'desc_tip' => __( 'From the webhook endpoint you registered with SendWay. Without it, delivery updates are rejected.', 'sendway' ),
				'desc'     => self::webhook_url_hint(),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_settings',
			),
		);
	}

	private static function webhook_url_hint() {
		$url = rest_url( 'sendway/v1/webhook' );
		return sprintf(
			/* translators: %s: the webhook URL to register */
			__( 'Register this URL with SendWay to receive delivery updates: %s', 'sendway' ),
			'<code>' . esc_url( $url ) . '</code>'
		);
	}

	public static function save() {
		$previous = SendWay_API::key();
		WC_Admin_Settings::save_fields( self::fields() );

		// A changed key means a different account, so cached towns are stale.
		if ( SendWay_API::key() !== $previous ) {
			SendWay_API::clear_towns_cache();
			self::validate_key();
		}
	}

	/**
	 * Check the key against the live API on save, so a wrong paste fails here
	 * rather than in front of a customer at checkout.
	 */
	private static function validate_key() {
		if ( ! SendWay_API::has_key() ) {
			return;
		}
		$res = SendWay_API::request( 'GET', '/towns' );
		if ( is_wp_error( $res ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message from SendWay */
					__( 'SendWay rejected that API key: %s', 'sendway' ),
					$res->get_error_message()
				)
			);
			return;
		}
		WC_Admin_Settings::add_message( __( 'SendWay connected.', 'sendway' ) );
	}
}
