<?php
/**
 * Loads the SendWay picker into the Checkout block.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

class SendWay_Blocks_Integration implements IntegrationInterface {

	public function get_name() {
		return 'sendway';
	}

	public function initialize() {
		wp_register_script(
			'sendway-blocks-checkout',
			SENDWAY_URL . 'assets/js/blocks-checkout.js',
			array( 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element' ),
			SENDWAY_VERSION,
			true
		);
		wp_register_style( 'sendway-blocks-checkout', false, array(), SENDWAY_VERSION );
		wp_add_inline_style( 'sendway-blocks-checkout', SendWay_Checkout::css() . SendWay_Door::css() . self::css() );
		wp_enqueue_style( 'sendway-blocks-checkout' );
	}

	private static function css() {
		return '
.sendway-field--block{margin:0 0 16px}
.sendway-field__title.wc-block-components-title{margin:24px 0 4px}
.sendway-help--intro{margin:0 0 12px}
.sendway-row__body{flex:1 1 auto;min-width:0}
.sendway-mode,.sendway-trigger,.sendway-row,.sendway-door__row{font-family:inherit;font-size:inherit;line-height:1.4;color:inherit}
.sendway-change{font-family:inherit}
.sendway-field--block .sendway-search{box-sizing:border-box;max-width:100%;font-family:inherit;font-size:inherit}
.sendway-status{margin:10px 0 0;font-size:.9em}
.sendway-status--ok{color:#15803d;font-weight:600}
.sendway-status--bad{color:#b45309}
';
	}

	public function get_script_handles() {
		return array( 'sendway-blocks-checkout' );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		if ( ! SendWay_Blocks::ready() ) {
			return array( 'ready' => false );
		}
		return array(
			'ready'       => true,
			'towns'       => SendWay_Checkout::towns_for_script(),
			'doorEnabled' => SendWay_Door::enabled(),
			'door'        => SendWay_Door::script_data(),
			'strings'     => array(
				'title'          => __( 'SendWay delivery', 'sendway' ),
				'intro'          => __( 'Choose where you will get your parcel to see the SendWay price.', 'sendway' ),
				'station'        => __( 'Collect in my town', 'sendway' ),
				'stationSub'     => __( 'From a SendWay point near you, with a PIN', 'sendway' ),
				'door'           => __( 'Deliver to my door', 'sendway' ),
				'doorSub'        => __( 'Inside the Nairobi metro', 'sendway' ),
				'chooseTown'     => __( 'Choose your pickup town', 'sendway' ),
				'chooseTownSub'  => __( 'We courier to the town and you collect there', 'sendway' ),
				'change'         => __( 'Change', 'sendway' ),
				'close'          => __( 'Close', 'sendway' ),
				'search'         => __( 'Search by name, town or county', 'sendway' ),
				'noResult'       => __( 'No pickup towns found', 'sendway' ),
				'tryAgain'       => __( 'Try a different search term', 'sendway' ),
				'stationHelp'    => __( 'You will collect your parcel from a SendWay agent in this town, using a PIN sent by SMS.', 'sendway' ),
				'doorSearch'     => __( 'Search your building, street or estate', 'sendway' ),
				'doorHelp'       => __( 'Door delivery covers the Nairobi metro. Large items and bulk orders go to a pickup town.', 'sendway' ),
				'doorChosenSub'  => __( 'A rider brings it to your door', 'sendway' ),
				'directions'     => __( 'House, floor or gate details for the rider (optional)', 'sendway' ),
				'price'          => __( 'SendWay delivery: %s', 'sendway' ),
				'noRate'         => __( 'SendWay cannot deliver this order there. Try another option, or choose a different shipping method.', 'sendway' ),
				'updating'       => __( 'Updating the delivery price…', 'sendway' ),
				'failed'         => __( 'We could not update the delivery price. Please try again.', 'sendway' ),
				'editorNote'     => __( 'Customers choose their SendWay pickup town or door address here.', 'sendway' ),
			),
		);
	}
}
