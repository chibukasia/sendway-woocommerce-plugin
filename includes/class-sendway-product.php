<?php
/**
 * Per-product SendWay settings: fragile, a large item such as a fridge, or a bulk unit such as a sack.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Product {

	const FRAGILE    = '_sendway_fragile';
	const LARGE_ITEM = '_sendway_large_item';
	const BULK_UNIT  = '_sendway_bulk_unit';

	const FALLBACK_BULK_UNITS = array(
		'CARTON_S' => array( 'label' => 'Carton, small (up to 10 kg)', 'max_kg' => 10 ),
		'CARTON_M' => array( 'label' => 'Carton, medium (up to 20 kg)', 'max_kg' => 20 ),
		'CARTON_L' => array( 'label' => 'Carton, large (up to 30 kg)', 'max_kg' => 30 ),
		'SACK_S'   => array( 'label' => 'Sack or bale, small (up to 50 kg)', 'max_kg' => 50 ),
		'SACK_L'   => array( 'label' => 'Sack or bale, large (up to 90 kg)', 'max_kg' => 90 ),
		'JERRYCAN' => array( 'label' => 'Jerrycan, 20 L', 'max_kg' => 22 ),
	);

	const CATALOG_TRANSIENT = 'sendway_catalog';

	/** Used when the catalog cannot be fetched; mirrors GET /v1/catalog. */
	const FALLBACK_ITEMS = array(
		'fridge_small'        => 'Small fridge (up to 100 L)',
		'fridge_single'       => 'Single-door fridge',
		'fridge_double'       => 'Double-door fridge',
		'fridge_side_by_side' => 'Side-by-side fridge',
		'display_fridge'      => 'Display fridge or cooler',
		'chest_freezer'       => 'Chest freezer',
		'washing_machine'     => 'Washing machine',
		'cooker'              => 'Cooker or oven',
		'microwave'           => 'Microwave',
		'water_dispenser'     => 'Water dispenser',
		'tv_43'               => 'TV up to 43"',
		'tv_55'               => 'TV 50" to 55"',
		'tv_65'               => 'TV 65" or bigger',
		'mattress_single'     => 'Single mattress (3×6)',
		'mattress_4x6'        => 'Mattress 4×6',
		'mattress_5x6'        => 'Mattress 5×6 or 6×6',
		'sofa_small'          => 'Sofa, 1 or 2 seats',
		'sofa_large'          => 'Sofa, 3 seats or more',
		'bed'                 => 'Bed frame',
		'other_large'         => 'Something else over 2 m or 100 kg',
	);

	public static function init() {
		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'render_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_fields' ) );
	}

	/** Large items SendWay knows, keyed by catalog key. Cached for a day. */
	public static function large_items() {
		$cached = get_transient( self::CATALOG_TRANSIENT );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		if ( SendWay_API::has_key() ) {
			$res = SendWay_API::request( 'GET', '/catalog' );
			if ( ! is_wp_error( $res ) && ! empty( $res['large_items'] ) ) {
				$items = array();
				foreach ( $res['large_items'] as $item ) {
					$items[ $item['key'] ] = $item['label'] . ( empty( $item['priced'] ) ? ' — ' . __( 'quoted by SendWay', 'sendway' ) : '' );
				}
				set_transient( self::CATALOG_TRANSIENT, $items, DAY_IN_SECONDS );
				return $items;
			}
		}
		return self::FALLBACK_ITEMS;
	}

	/** Bulk units SendWay prices, keyed by unit, each with a label and its weight allowance. */
	public static function bulk_units() {
		$catalog = SendWay_API::has_key() ? SendWay_API::catalog() : array();
		if ( ! empty( $catalog['bulk_units'] ) ) {
			$units = array();
			foreach ( $catalog['bulk_units'] as $unit ) {
				$units[ $unit['key'] ] = array(
					'label'  => $unit['label'],
					'max_kg' => (float) $unit['max_kg'],
				);
			}
			return $units;
		}
		return self::FALLBACK_BULK_UNITS;
	}

	public static function bulk_min_units() {
		$catalog = SendWay_API::has_key() ? SendWay_API::catalog() : array();
		return isset( $catalog['bulk_limits']['min_units'] ) ? max( 1, (int) $catalog['bulk_limits']['min_units'] ) : 2;
	}

	public static function render_fields() {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::FRAGILE,
				'label'       => __( 'SendWay: fragile', 'sendway' ),
				'description' => __( 'Glass, ceramics, screens. Carried with extra care and a fragile surcharge.', 'sendway' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => self::LARGE_ITEM,
				'label'       => __( 'SendWay: large item', 'sendway' ),
				'options'     => array( '' => __( 'Not a large item', 'sendway' ) ) + self::large_items(),
				'desc_tip'    => true,
				'description' => __( 'Fridges, TVs, furniture and mattresses are priced per item rather than by weight.', 'sendway' ),
			)
		);
		$units = array( '' => __( 'Not a bulk unit', 'sendway' ) );
		foreach ( self::bulk_units() as $key => $unit ) {
			$units[ $key ] = $unit['label'];
		}
		woocommerce_wp_select(
			array(
				'id'          => self::BULK_UNIT,
				'label'       => __( 'SendWay: bulk unit', 'sendway' ),
				'options'     => $units,
				'desc_tip'    => true,
				'description' => __( 'For goods sold by the sack, carton or jerrycan. Orders of two or more units travel as a bulk load, priced per unit with discounts for larger loads.', 'sendway' ),
			)
		);
		echo '</div>';
	}

	public static function save_fields( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product form nonce.
		$product->update_meta_data( self::FRAGILE, isset( $_POST[ self::FRAGILE ] ) ? 'yes' : 'no' );
		$item = isset( $_POST[ self::LARGE_ITEM ] ) ? sanitize_key( wp_unslash( $_POST[ self::LARGE_ITEM ] ) ) : '';
		// phpcs:enable
		$product->update_meta_data( self::LARGE_ITEM, array_key_exists( $item, self::large_items() ) ? $item : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product form nonce.
		$unit = isset( $_POST[ self::BULK_UNIT ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::BULK_UNIT ] ) ) ) : '';
		$product->update_meta_data( self::BULK_UNIT, array_key_exists( $unit, self::bulk_units() ) ? $unit : '' );
	}

	/** The setting on a variation falls back to its parent product. */
	public static function meta( $product, $key ) {
		if ( ! $product ) {
			return '';
		}
		$value = $product->get_meta( $key );
		if ( '' === $value && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$value  = $parent ? $parent->get_meta( $key ) : '';
		}
		return $value;
	}
}
