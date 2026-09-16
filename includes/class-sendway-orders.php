<?php
/**
 * Shows the parcel on the order — for the shop owner in admin, and for the
 * customer wherever they look for it.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Orders {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'customer_tracking' ) );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email_tracking' ), 10, 3 );
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( __CLASS__, 'preview' ), 10, 2 );
	}

	public static function track_url( $tracking ) {
		return 'https://sendway.co.ke/track?code=' . rawurlencode( $tracking );
	}

	public static function meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'sendway_parcel',
				__( 'SendWay parcel', 'sendway' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$tracking = $order->get_meta( '_sendway_tracking' );
		$town     = $order->get_meta( '_sendway_town_name' );

		if ( ! $tracking ) {
			echo '<p>' . esc_html__( 'Not booked yet.', 'sendway' ) . '</p>';
			if ( $town ) {
				echo '<p>' . sprintf(
					/* translators: %s: town name */
					esc_html__( 'Collection town: %s', 'sendway' ),
					esc_html( $town )
				) . '</p>';
				echo '<p>' . esc_html__( 'Use Order actions → Send with SendWay to book it now.', 'sendway' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This order has no SendWay collection town, so it cannot be booked.', 'sendway' ) . '</p>';
			}
			return;
		}

		if ( 'no' === $order->get_meta( '_sendway_livemode' ) ) {
			echo '<p><strong>' . esc_html__( 'Test mode — this parcel is not real.', 'sendway' ) . '</strong></p>';
		}

		echo '<p><strong>' . esc_html__( 'Tracking', 'sendway' ) . '</strong><br>';
		echo '<a href="' . esc_url( self::track_url( $tracking ) ) . '" target="_blank" rel="noopener">' . esc_html( $tracking ) . '</a></p>';

		if ( $town ) {
			echo '<p><strong>' . esc_html__( 'Collect at', 'sendway' ) . '</strong><br>' . esc_html( $town ) . '</p>';
		}

		$status = $order->get_meta( '_sendway_status' );
		if ( $status ) {
			echo '<p><strong>' . esc_html__( 'Status', 'sendway' ) . '</strong><br>' . esc_html( self::humanise( $status ) ) . '</p>';
		}
	}

	/** Parcel statuses read like machine names; these do not. */
	public static function humanise( $status ) {
		$map = array(
			'AWAITING_PAYMENT'   => __( 'Waiting to be collected from you', 'sendway' ),
			'AWAITING_DROPOFF'   => __( 'Waiting to be collected from you', 'sendway' ),
			'AWAITING_PICKUP'    => __( 'Waiting to be collected from you', 'sendway' ),
			'IN_TRANSIT'         => __( 'On its way', 'sendway' ),
			'AT_PICKUP_STATION'  => __( 'Ready for collection', 'sendway' ),
			'DELIVERED'          => __( 'Collected by the customer', 'sendway' ),
			'CANCELLED'          => __( 'Cancelled', 'sendway' ),
			'RETURNED'           => __( 'Returned', 'sendway' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	public static function customer_tracking( $order ) {
		$tracking = $order->get_meta( '_sendway_tracking' );
		if ( ! $tracking ) {
			return;
		}
		$town = $order->get_meta( '_sendway_town_name' );

		echo '<section class="sendway-tracking">';
		echo '<h2>' . esc_html__( 'Delivery', 'sendway' ) . '</h2>';
		echo '<p>';
		printf(
			/* translators: 1: tracking link, 2: town name */
			esc_html__( 'Your parcel is on its way with SendWay. Track it with %1$s%2$s', 'sendway' ),
			'<a href="' . esc_url( self::track_url( $tracking ) ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $tracking ) . '</strong></a>',
			$town ? '. ' . sprintf(
				/* translators: %s: town name */
				esc_html__( 'You will collect it in %s — we will text you a PIN when it arrives.', 'sendway' ),
				esc_html( $town )
			) : '.'
		);
		echo '</p></section>';
	}

	public static function email_tracking( $order, $sent_to_admin, $plain_text ) {
		$tracking = $order->get_meta( '_sendway_tracking' );
		if ( ! $tracking || $sent_to_admin ) {
			return;
		}
		$url = self::track_url( $tracking );

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Track your parcel:', 'sendway' ) . ' ' . esc_url( $url ) . "\n";
			return;
		}
		echo '<p style="margin:16px 0"><strong>' . esc_html__( 'Track your parcel', 'sendway' ) . ':</strong> ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $tracking ) . '</a></p>';
	}

	public static function preview( $details, $order ) {
		$tracking = $order->get_meta( '_sendway_tracking' );
		if ( $tracking ) {
			$details['sendway'] = $tracking;
		}
		return $details;
	}
}
