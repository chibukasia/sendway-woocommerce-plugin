<?php
/**
 * SendWay in the WooCommerce Checkout block.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Blocks {

	const NS = 'sendway';

	public static function init() {
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'checkout' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor' ) );
		add_filter( '__experimental_woocommerce_blocks_add_data_attributes_to_namespace', array( __CLASS__, 'data_attributes' ) );
	}

	public static function ready() {
		return SendWay_API::has_key() && SendWay_API::origin() && ! empty( SendWay_Checkout::towns() );
	}

	public static function data_attributes( $namespaces ) {
		$namespaces[] = self::NS;
		return $namespaces;
	}

	public static function register() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NS,
				'callback'  => array( __CLASS__, 'update' ),
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::NS,
				'data_callback'   => array( __CLASS__, 'cart_data' ),
				'schema_callback' => array( __CLASS__, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $registry ) {
				require_once SENDWAY_PATH . 'includes/class-sendway-blocks-integration.php';
				$registry->register( new SendWay_Blocks_Integration() );
			}
		);
	}

	public static function update( $data ) {
		$action = isset( $data['action'] ) ? sanitize_key( $data['action'] ) : '';

		if ( 'town' === $action ) {
			SendWay_Checkout::set_mode( 'STATION' );
			$town = SendWay_Checkout::set_town( isset( $data['town_id'] ) ? sanitize_text_field( $data['town_id'] ) : '' );
			if ( $town ) {
				self::fill_address(
					array(
						'city'  => $town['name'],
						'state' => SendWay_Checkout::state_code( isset( $town['county'] ) ? $town['county'] : '' ),
					)
				);
			}
			return;
		}

		if ( 'mode' === $action ) {
			SendWay_Checkout::set_mode( isset( $data['mode'] ) ? sanitize_text_field( $data['mode'] ) : '' );
			return;
		}

		if ( 'door' === $action ) {
			SendWay_Checkout::set_mode( 'DOOR' );
			$door = SendWay_Door::chosen();
			if ( $door ) {
				WC()->session->set( 'shipping_for_package_0', null );
				self::fill_address( array( 'address_1' => $door['address'] ), true );
			}
			return;
		}

		if ( 'directions' === $action ) {
			SendWay_Checkout::set_directions( isset( $data['directions'] ) ? $data['directions'] : '' );
		}
	}

	private static function fill_address( $fields, $only_if_empty = false ) {
		$customer = WC()->customer;
		if ( ! $customer ) {
			return;
		}
		foreach ( $fields as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$getter = 'get_shipping_' . $key;
			if ( $only_if_empty && '' !== (string) $customer->$getter() ) {
				continue;
			}
			$billing_getter = 'get_billing_' . $key;
			$same           = (string) $customer->$billing_getter() === (string) $customer->$getter();
			$customer->{'set_shipping_' . $key}( $value );
			if ( $same || '' === (string) $customer->$billing_getter() ) {
				$customer->{'set_billing_' . $key}( $value );
			}
		}
		$customer->save();
	}

	public static function cart_data() {
		if ( ! self::ready() ) {
			return array(
				'ready'   => false,
				'mode'    => 'STATION',
				'town_id' => null,
				'door'    => null,
			);
		}
		$door = SendWay_Door::chosen();
		return array(
			'ready'   => true,
			'mode'    => SendWay_Checkout::chosen_mode(),
			'town_id' => SendWay_Checkout::chosen_town_id(),
			'door'    => $door
				? array(
					'address'    => (string) $door['address'],
					'directions' => isset( $door['directions'] ) ? (string) $door['directions'] : '',
				)
				: null,
		);
	}

	public static function cart_schema() {
		return array(
			'ready'   => array(
				'type'     => 'boolean',
				'context'  => array( 'view', 'edit' ),
				'readonly' => true,
			),
			'mode'    => array(
				'type'     => 'string',
				'context'  => array( 'view', 'edit' ),
				'readonly' => true,
			),
			'town_id' => array(
				'type'     => array( 'string', 'null' ),
				'context'  => array( 'view', 'edit' ),
				'readonly' => true,
			),
			'door'    => array(
				'type'     => array( 'object', 'null' ),
				'context'  => array( 'view', 'edit' ),
				'readonly' => true,
			),
		);
	}

	public static function checkout( $order, $request ) {
		if ( ! self::ready() ) {
			return;
		}
		$methods = array();
		foreach ( $order->get_shipping_methods() as $item ) {
			$methods[] = $item->get_method_id();
		}
		if ( ! SendWay_Checkout::uses_sendway( $methods ) ) {
			return;
		}

		$placing = 'POST' === $request->get_method();
		$mode    = SendWay_Checkout::chosen_mode();

		if ( 'DOOR' === $mode ) {
			$door = SendWay_Door::chosen();
			if ( ! $door ) {
				if ( $placing ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sendway_no_address', __( 'Please choose your delivery address from the list so our rider can find you.', 'sendway' ), 400 );
				}
				return;
			}
			$order->update_meta_data( '_sendway_delivery_mode', 'DOOR' );
			$order->update_meta_data( '_sendway_door_lat', $door['lat'] );
			$order->update_meta_data( '_sendway_door_lng', $door['lng'] );
			$order->update_meta_data( '_sendway_door_address', $door['address'] );
			$order->update_meta_data( '_sendway_door_directions', mb_substr( (string) ( isset( $door['directions'] ) ? $door['directions'] : '' ), 0, 120 ) );
		} else {
			$town_id = SendWay_Checkout::chosen_town_id();
			$town    = $town_id ? SendWay_Checkout::town_by_id( $town_id ) : null;
			if ( ! $town ) {
				if ( $placing ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sendway_no_town', __( 'Please choose the town you will collect your parcel from.', 'sendway' ), 400 );
				}
				return;
			}
			$order->update_meta_data( '_sendway_delivery_mode', 'STATION' );
			$order->update_meta_data( '_sendway_town_id', $town['id'] );
			$order->update_meta_data( '_sendway_town_name', $town['name'] );
			$order->update_meta_data( '_sendway_town_county', isset( $town['county'] ) ? $town['county'] : '' );
		}

		if ( $placing && '' === trim( $order->get_billing_phone() . $order->get_shipping_phone() ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sendway_no_phone', __( 'Please add a phone number. Our rider calls it on delivery, and your collection PIN is sent to it by SMS.', 'sendway' ), 400 );
		}
	}

	public static function editor() {
		wp_enqueue_script(
			'sendway-blocks-editor',
			SENDWAY_URL . 'assets/js/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
			SENDWAY_VERSION,
			true
		);
	}
}
