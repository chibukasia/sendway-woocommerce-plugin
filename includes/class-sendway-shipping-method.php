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
		$this->method_description = __( 'Live rates from SendWay, priced on the destination town and the weight of the cart.', 'sendway' );
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

		$weight = self::cart_weight_kg( $package );

		$quote = SendWay_API::quote(
			array(
				'package_size'  => self::size_for_weight( $weight ),
				'weight_kg'     => $weight,
				'delivery_mode' => 'STATION',
				'origin_lat'    => (float) get_option( 'sendway_origin_lat', -1.2864 ),
				'origin_lng'    => (float) get_option( 'sendway_origin_lng', 36.8172 ),
				'dest_town_id'  => $town_id,
			)
		);

		if ( is_wp_error( $quote ) || ! isset( $quote['total'] ) ) {
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
					'sendway_town_id'     => $town_id,
					'sendway_quoted_total' => $quote['total'],
				),
			)
		);
	}

	/** Cart weight in kg, normalised from whatever unit the shop uses. */
	public static function cart_weight_kg( $package ) {
		$total = 0.0;
		if ( empty( $package['contents'] ) ) {
			return 1.0;
		}
		foreach ( $package['contents'] as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product || ! $product->has_weight() ) {
				continue;
			}
			$total += (float) wc_get_weight( $product->get_weight(), 'kg' ) * (int) $item['quantity'];
		}
		// Shops often leave weights blank; assume a small parcel rather than
		// quoting zero, which would price everything at the cheapest band.
		return $total > 0 ? round( $total, 2 ) : 1.0;
	}

	/** SendWay's size bands, so the quote matches what will be collected. */
	public static function size_for_weight( $kg ) {
		if ( $kg <= 2 ) {
			return 'S';
		}
		if ( $kg <= 5 ) {
			return 'M';
		}
		if ( $kg <= 10 ) {
			return 'ML';
		}
		if ( $kg <= 20 ) {
			return 'L';
		}
		return 'BULKY';
	}
}
