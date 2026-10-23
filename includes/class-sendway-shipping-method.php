<?php
/**
 * Live SendWay rates at checkout.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sendway';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'SendWay', 'sendway' );
		$this->method_description = __( 'Live rates from SendWay, priced on the destination town, the weight of the cart, fragile items and large items such as fridges.', 'sendway' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', __( 'SendWay delivery', 'sendway' ) );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled'  => array(
				'title'   => __( 'Enable', 'sendway' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'title'    => array(
				'title'   => __( 'Name shown to customers', 'sendway' ),
				'type'    => 'text',
				'default' => __( 'SendWay delivery', 'sendway' ),
			),
			'handling' => array(
				'title'       => __( 'Handling fee', 'sendway' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Added to the SendWay rate. Leave at 0 to charge exactly what SendWay charges you.', 'sendway' ),
			),
		);
	}

	/**
	 * Quote the cart. Falls silent rather than erroring: if SendWay cannot be
	 * reached, this method simply does not offer a rate, and the customer sees
	 * whatever other methods the shop has — never a broken checkout.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( ! SendWay_API::has_key() ) {
			return;
		}

		$town_id = SendWay_Checkout::chosen_town_id();
		if ( ! $town_id ) {
			return; // No destination picked yet; the picker prompts for one.
		}

		$parcel = SendWay_Parcel::from_cart_package( $package );
		if ( is_wp_error( $parcel ) ) {
			return; // One parcel cannot carry this cart; other methods still show.
		}

		$quote = SendWay_API::quote(
			array_merge(
				$parcel,
				array(
					'delivery_mode' => 'STATION',
					'origin_lat'    => (float) get_option( 'sendway_origin_lat', -1.2864 ),
					'origin_lng'    => (float) get_option( 'sendway_origin_lng', 36.8172 ),
					'dest_town_id'  => $town_id,
				)
			)
		);

		// A parcel SendWay prices by hand has no total; offer nothing rather
		// than a rate we would not honour.
		if ( is_wp_error( $quote ) || ! empty( $quote['requires_quote'] ) || ! isset( $quote['total'] ) ) {
			return;
		}

		$handling = (float) $this->get_option( 'handling', 0 );
		$town     = SendWay_Checkout::town_by_id( $town_id );
		$label    = $town ? sprintf( '%s — %s', $this->title, $town['name'] ) : $this->title;

		$this->add_rate(
			array(
				'id'        => $this->id . ':' . $town_id,
				'label'     => $label,
				'cost'      => (float) $quote['total'] + $handling,
				'package'   => $package,
				'meta_data' => array(
					'sendway_town_id'      => $town_id,
					'sendway_quoted_total' => $quote['total'],
					'sendway_category'     => $parcel['category'],
				),
			)
		);
	}

	/** Kept for older callers; the logic now lives in SendWay_Parcel. */
	public static function size_for_weight( $kg ) {
		return SendWay_Parcel::size_for_weight( $kg );
	}
}
