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
		$this->method_description = __( 'Live rates from SendWay to a pickup town or to the door in Nairobi, priced on the weight and size of the cart, fragile items, large items such as fridges, and bulk loads.', 'sendway' );
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

		$door_mode = 'DOOR' === SendWay_Checkout::chosen_mode();
		$door      = $door_mode ? SendWay_Door::chosen() : null;
		$town_id   = $door_mode ? null : SendWay_Checkout::chosen_town_id();
		if ( $door_mode ? ! $door : ! $town_id ) {
			return; // No destination picked yet; the picker prompts for one.
		}

		$parcel = SendWay_Parcel::from_cart_package( $package );
		if ( is_wp_error( $parcel ) ) {
			return; // One parcel cannot carry this cart; other methods still show.
		}
		if ( $door_mode && in_array( $parcel['category'], array( 'LARGE_ITEM', 'BULK' ), true ) ) {
			return; // Large items and bulk loads only go to a pickup town.
		}

		$destination = $door_mode
			? array(
				'delivery_mode' => 'DOOR',
				'dest_lat'      => (float) $door['lat'],
				'dest_lng'      => (float) $door['lng'],
			)
			: array(
				'delivery_mode' => 'STATION',
				'dest_town_id'  => $town_id,
			);

		$quote = SendWay_API::quote(
			array_merge(
				$parcel,
				$destination,
				array(
					'origin_lat' => (float) get_option( 'sendway_origin_lat', -1.2864 ),
					'origin_lng' => (float) get_option( 'sendway_origin_lng', 36.8172 ),
				)
			)
		);
		if ( is_wp_error( $quote ) ) {
			return;
		}
		if ( $door_mode && ! empty( $quote['requires_pickup_station'] ) ) {
			return; // Too far for a rider; the customer can switch to collecting in town.
		}

		$town  = $town_id ? SendWay_Checkout::town_by_id( $town_id ) : null;
		$where = $door_mode ? __( 'to your door', 'sendway' ) : ( $town ? $town['name'] : '' );
		$label = $where ? sprintf( '%s — %s', $this->title, $where ) : $this->title;
		$meta  = array(
			'sendway_delivery_mode' => $door_mode ? 'DOOR' : 'STATION',
			'sendway_town_id'       => $town_id,
			'sendway_category'      => $parcel['category'],
		);

		if ( ! empty( $quote['requires_quote'] ) || ! isset( $quote['total'] ) ) {
			if ( 'yes' !== get_option( 'sendway_offer_quoted', 'no' ) ) {
				return; // A rate we would not honour is worse than no rate.
			}
			$this->add_rate(
				array(
					'id'        => $this->id . ':quote',
					'label'     => sprintf( '%s — %s', $label, __( 'price confirmed after you order', 'sendway' ) ),
					'cost'      => 0,
					'package'   => $package,
					'meta_data' => $meta + array( 'sendway_needs_quote' => 'yes' ),
				)
			);
			return;
		}

		$handling = (float) $this->get_option( 'handling', 0 );
		$this->add_rate(
			array(
				'id'        => $this->id . ':' . ( $door_mode ? 'door' : $town_id ),
				'label'     => $label,
				'cost'      => (float) $quote['total'] + $handling,
				'package'   => $package,
				'meta_data' => $meta + array( 'sendway_quoted_total' => $quote['total'] ),
			)
		);
	}

	/** Kept for older callers; the logic now lives in SendWay_Parcel. */
	public static function size_for_weight( $kg ) {
		return SendWay_Parcel::size_for_weight( $kg );
	}
}
