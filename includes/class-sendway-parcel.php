<?php
/**
 * Turns a cart or an order into the parcel fields SendWay prices on, so the
 * rate shown at checkout and the parcel booked afterwards are built the same
 * way and cost the same.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Parcel {

	const MAX_LARGE_ITEMS = 5;

	/**
	 * @param array $lines Each array( 'product' => WC_Product|null, 'qty' => int ).
	 * @param float $value Goods value, used for cover.
	 * @return array|WP_Error Parcel fields, or an error when one parcel cannot carry it.
	 */
	public static function from_lines( $lines, $value ) {
		$weight  = 0.0;
		$fragile = false;
		$large   = array();

		foreach ( $lines as $line ) {
			$product = $line['product'];
			$qty     = max( 1, (int) $line['qty'] );
			if ( ! $product ) {
				continue;
			}
			if ( $product->has_weight() ) {
				$weight += (float) wc_get_weight( $product->get_weight(), 'kg' ) * $qty;
			}
			$item = SendWay_Product::meta( $product, SendWay_Product::LARGE_ITEM );
			if ( $item ) {
				$large[ $item ] = ( isset( $large[ $item ] ) ? $large[ $item ] : 0 ) + $qty;
			}
			if ( 'yes' === SendWay_Product::meta( $product, SendWay_Product::FRAGILE ) ) {
				$fragile = true;
			}
		}

		$weight = $weight > 0 ? round( $weight, 2 ) : 1.0;
		$value  = max( 0, round( (float) $value, 2 ) );

		if ( $large ) {
			if ( count( $large ) > 1 ) {
				return new WP_Error( 'sendway_mixed_large', __( 'This order has more than one kind of large item, so SendWay needs to quote it.', 'sendway' ) );
			}
			$key = array_key_first( $large );
			$qty = $large[ $key ];
			if ( $qty > self::MAX_LARGE_ITEMS ) {
				return new WP_Error( 'sendway_too_many_large', __( 'More large items than SendWay prices online, so it needs a quote.', 'sendway' ) );
			}
			return array(
				'category'       => 'LARGE_ITEM',
				'large_item'     => $key,
				'quantity'       => $qty,
				'package_size'   => 'BULKY',
				'declared_value' => $value,
			);
		}

		return array(
			'category'       => $fragile ? 'FRAGILE' : 'STANDARD',
			'package_size'   => self::size_for_weight( $weight ),
			'weight_kg'      => $weight,
			'is_fragile'     => $fragile,
			'declared_value' => $value,
		);
	}

	public static function from_cart_package( $package ) {
		$lines = array();
		$value = 0.0;
		foreach ( isset( $package['contents'] ) ? $package['contents'] : array() as $item ) {
			$lines[] = array(
				'product' => isset( $item['data'] ) ? $item['data'] : null,
				'qty'     => isset( $item['quantity'] ) ? $item['quantity'] : 1,
			);
			$value  += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
		}
		return self::from_lines( $lines, $value );
	}

	public static function from_order( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = array(
				'product' => $item->get_product(),
				'qty'     => $item->get_quantity(),
			);
		}
		return self::from_lines( $lines, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
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
