<?php
/**
 * Plugin Name:       SendWay for WooCommerce
 * Plugin URI:        https://sendway.co.ke
 * Description:       Live SendWay delivery rates at checkout, a pickup-town picker for your customers, automatic parcel booking, and tracking on the order — for shops delivering out of Nairobi.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fecn Technologies Ltd
 * Author URI:        https://sendway.co.ke
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendway
 * WC requires at least: 7.0
 *
 * This plugin talks to the SendWay API at api.shopinn.co.ke. Your API key,
 * order destinations and parcel details are sent there so parcels can be priced
 * and booked. Nothing is sent anywhere else.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

define( 'SENDWAY_VERSION', '1.0.0' );
define( 'SENDWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'SENDWAY_URL', plugin_dir_url( __FILE__ ) );

require_once SENDWAY_PATH . 'includes/class-sendway-api.php';
require_once SENDWAY_PATH . 'includes/class-sendway-settings.php';
require_once SENDWAY_PATH . 'includes/class-sendway-checkout.php';
require_once SENDWAY_PATH . 'includes/class-sendway-booking.php';
require_once SENDWAY_PATH . 'includes/class-sendway-webhooks.php';
require_once SENDWAY_PATH . 'includes/class-sendway-orders.php';

/**
 * WooCommerce is a hard dependency: without it there is no checkout to attach
 * to. Fail with a notice rather than a fatal error on activation.
 */
function sendway_requires_woocommerce() {
	if ( class_exists( 'WooCommerce' ) ) {
		return true;
	}
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'SendWay needs WooCommerce to be installed and active.', 'sendway' );
			echo '</p></div>';
		}
	);
	return false;
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! sendway_requires_woocommerce() ) {
			return;
		}

		SendWay_Settings::init();
		SendWay_Checkout::init();
		SendWay_Booking::init();
		SendWay_Webhooks::init();
		SendWay_Orders::init();

		// The shipping method class extends WC_Shipping_Method, which only
		// exists once WooCommerce has loaded — hence the late require.
		add_action(
			'woocommerce_shipping_init',
			function () {
				require_once SENDWAY_PATH . 'includes/class-sendway-shipping-method.php';
			}
		);
		add_filter(
			'woocommerce_shipping_methods',
			function ( $methods ) {
				$methods['sendway'] = 'SendWay_Shipping_Method';
				return $methods;
			}
		);
	}
);

/** Declare compatibility with WooCommerce High-Performance Order Storage. */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		// Flush so the webhook REST route resolves immediately rather than
		// after the merchant happens to re-save permalinks.
		flush_rewrite_rules();
	}
);
