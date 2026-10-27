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
		$weight      = 0.0;
		$fragile     = false;
		$large       = array();
		$bulk        = array();
		$bulk_units  = 0;
		$loose_kg    = 0.0;
		$loose_count = 0;
		$volume      = 0.0;
		$longest     = 0.0;

		foreach ( $lines as $line ) {
			$product = $line['product'];
			$qty     = max( 1, (int) $line['qty'] );
			if ( ! $product ) {
				continue;
			}
			$each = $product->has_weight() ? (float) wc_get_weight( $product->get_weight(), 'kg' ) : 0.0;
			$weight += $each * $qty;
			if ( $product->has_dimensions() ) {
				$sides = array(
					(float) wc_get_dimension( (float) $product->get_length(), 'cm' ),
					(float) wc_get_dimension( (float) $product->get_width(), 'cm' ),
					(float) wc_get_dimension( (float) $product->get_height(), 'cm' ),
				);
				if ( min( $sides ) > 0 ) {
					$volume += $sides[0] * $sides[1] * $sides[2] * $qty;
					$longest = max( $longest, max( $sides ) );
				}
			}
			$item = SendWay_Product::meta( $product, SendWay_Product::LARGE_ITEM );
			$unit = SendWay_Product::meta( $product, SendWay_Product::BULK_UNIT );
			if ( $item ) {
				$large[ $item ] = ( isset( $large[ $item ] ) ? $large[ $item ] : 0 ) + $qty;
			} elseif ( $unit ) {
				if ( ! isset( $bulk[ $unit ] ) ) {
					$bulk[ $unit ] = array(
						'count' => 0,
						'kg'    => 0.0,
					);
				}
				$bulk[ $unit ]['count'] += $qty;
				$bulk[ $unit ]['kg']    += $each * $qty;
				$bulk_units             += $qty;
			} else {
				$loose_kg    += $each * $qty;
				$loose_count += $qty;
				if ( 'yes' === SendWay_Product::meta( $product, SendWay_Product::FRAGILE ) ) {
					$fragile = true;
				}
			}
		}

		$weight = $weight > 0 ? round( $weight, 2 ) : 1.0;
		$value  = max( 0, round( (float) $value, 2 ) );

		if ( $large ) {
			if ( count( $large ) > 1 || $bulk ) {
				return new WP_Error( 'sendway_mixed_large', __( 'This order mixes a large item with other bulky goods, so SendWay needs to quote it.', 'sendway' ) );
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

		if ( $bulk && $bulk_units >= SendWay_Product::bulk_min_units() ) {
			if ( $fragile ) {
				return new WP_Error( 'sendway_fragile_bulk', __( 'Fragile items travelling with a bulk load need a quote from SendWay.', 'sendway' ) );
			}
			$bulk_lines = array();
			foreach ( $bulk as $unit => $row ) {
				$line = array(
					'unit'  => $unit,
					'count' => $row['count'],
				);
				if ( $row['kg'] > 0 ) {
					$line['weight_kg'] = round( $row['kg'] / $row['count'], 2 );
				}
				$bulk_lines[] = $line;
			}
			if ( $loose_count > 0 ) {
				$carton = self::carton_for( $loose_kg );
				if ( ! $carton ) {
					return new WP_Error( 'sendway_loose_too_heavy', __( 'The other items in this bulk order are too heavy for one carton, so SendWay needs to quote it.', 'sendway' ) );
				}
				$extra = array(
					'unit'  => $carton,
					'count' => 1,
				);
				if ( $loose_kg > 0 ) {
					$extra['weight_kg'] = round( $loose_kg, 2 );
				}
				$bulk_lines[] = $extra;
			}
			return array(
				'category'       => 'BULK',
				'package_size'   => 'BULKY',
				'bulk_lines'     => $bulk_lines,
				'declared_value' => $value,
			);
		}

		return array_merge(
			array(
				'category'       => $fragile ? 'FRAGILE' : 'STANDARD',
				'package_size'   => self::size_for_weight( $weight ),
				'weight_kg'      => $weight,
				'is_fragile'     => $fragile,
				'declared_value' => $value,
			),
			self::box_for( $volume, $longest )
		);
	}

	private static function carton_for( $kg ) {
		$units = SendWay_Product::bulk_units();
		foreach ( array( 'CARTON_S', 'CARTON_M', 'CARTON_L' ) as $key ) {
			$max = isset( $units[ $key ]['max_kg'] ) ? (float) $units[ $key ]['max_kg'] : 0;
			if ( $kg <= $max ) {
				return $key;
			}
		}
		return null;
	}

	private static function box_for( $volume, $longest ) {
		if ( $volume <= 0 || $longest <= 0 ) {
			return array();
		}
		$length = min( 400, $longest );
		$side   = min( 400, sqrt( $volume / $length ) );
		return array(
			'length_cm' => round( $length, 1 ),
			'width_cm'  => round( max( 1, $side ), 1 ),
			'height_cm' => round( max( 1, $side ), 1 ),
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
