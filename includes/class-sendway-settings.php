<?php
/**
 * Settings screen under WooCommerce → Settings → Shipping → SendWay.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Settings {

	const SKIP_STATUSES = array( 'pending', 'on-hold', 'cancelled', 'refunded', 'failed', 'checkout-draft' );

	public static function init() {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'add_section' ) );
		add_action( 'woocommerce_settings_shipping', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_shipping_sendway', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_admin_field_sendway_location', array( __CLASS__, 'render_location' ) );
		add_action( 'woocommerce_admin_field_sendway_webhook', array( __CLASS__, 'render_webhook' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SENDWAY_PATH . 'sendway.php' ), array( __CLASS__, 'action_links' ) );
		add_filter( 'option_woocommerce_checkout_phone_field', array( __CLASS__, 'require_phone' ) );
		add_filter( 'default_option_woocommerce_checkout_phone_field', array( __CLASS__, 'require_phone' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=wc-settings&tab=shipping&section=sendway' );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'sendway' ) . '</a>' );
		return $links;
	}

	public static function add_section( $sections ) {
		$sections['sendway'] = __( 'SendWay', 'sendway' );
		return $sections;
	}

	public static function require_phone( $value ) {
		return SendWay_API::has_key() ? 'required' : $value;
	}

	public static function render() {
		global $current_section;
		if ( 'sendway' !== $current_section ) {
			return;
		}
		self::notices();
		WC_Admin_Settings::output_fields( self::fields() );
	}

	private static function notice( $type, $html ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . wp_kses_post( $html ) . '</p></div>';
	}

	private static function notices() {
		if ( ! SendWay_API::has_key() ) {
			self::notice( 'info', __( 'Paste an API key below to connect. Get one from your <strong>SendWay account → Developers</strong>. Test keys work straight away; live keys need your account approved.', 'sendway' ) );
			return;
		}

		$towns = SendWay_API::towns();
		if ( empty( $towns ) ) {
			self::notice( 'error', esc_html__( 'That API key is not working. Check it was copied in full, and that your SendWay account is active.', 'sendway' ) );
			return;
		}

		$mode = SendWay_API::is_test_mode()
			? __( 'Test mode: parcels are simulated and nothing is charged.', 'sendway' )
			: __( 'Live mode: parcels are real and will be collected.', 'sendway' );
		self::notice(
			'success',
			esc_html(
				sprintf(
					/* translators: 1: number of towns, 2: mode description */
					__( 'Connected. Delivering to %1$d towns. %2$s', 'sendway' ),
					count( $towns ),
					$mode
				)
			)
		);

		if ( ! SendWay_API::origin() ) {
			self::notice( 'error', esc_html__( 'Set your collection address below. SendWay prices every delivery from it and sends the rider there, so no SendWay rate is shown at checkout until it is set.', 'sendway' ) );
		}

		if ( ! self::in_a_zone() ) {
			self::notice(
				'warning',
				sprintf(
					/* translators: %s: link to the shipping zones screen */
					__( 'SendWay is not added to any shipping zone yet, so customers will not see it at checkout. Open %s, edit the zone that covers Kenya and add SendWay as a shipping method.', 'sendway' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ) . '">' . esc_html__( 'Shipping zones', 'sendway' ) . '</a>'
				)
			);
		}
	}

	private static function in_a_zone() {
		$zones   = WC_Shipping_Zones::get_zones();
		$methods = array();
		foreach ( $zones as $zone ) {
			$methods = array_merge( $methods, $zone['shipping_methods'] );
		}
		$rest    = new WC_Shipping_Zone( 0 );
		$methods = array_merge( $methods, $rest->get_shipping_methods() );
		foreach ( $methods as $method ) {
			if ( 'sendway' === $method->id && 'yes' === $method->enabled ) {
				return true;
			}
		}
		return false;
	}

	private static function statuses() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = str_replace( 'wc-', '', $slug );
			if ( ! in_array( $slug, self::SKIP_STATUSES, true ) ) {
				$statuses[ $slug ] = $label;
			}
		}
		return $statuses;
	}

	public static function fields() {
		return array(
			array(
				'title' => __( 'Connection', 'sendway' ),
				'type'  => 'title',
				'desc'  => __( 'Collect from your shop and deliver across Kenya. Rates appear at checkout and parcels are booked automatically.', 'sendway' ),
				'id'    => 'sendway_connection',
			),
			array(
				'title'    => __( 'API key', 'sendway' ),
				'id'       => 'sendway_api_key',
				'type'     => 'password',
				'desc_tip' => __( 'From your SendWay account under Developers. Starts with sw_test_ or sw_live_.', 'sendway' ),
				'desc'     => __( 'A test key lets you place practice orders without anything being collected or charged.', 'sendway' ),
				'css'      => 'width:400px',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_connection',
			),

			array(
				'title' => __( 'Collection point', 'sendway' ),
				'type'  => 'title',
				'desc'  => __( 'Where our rider collects your parcels. Prices are worked out from here.', 'sendway' ),
				'id'    => 'sendway_collection',
			),
			array(
				'title'    => __( 'Collection address', 'sendway' ),
				'id'       => 'sendway_origin_address',
				'type'     => 'sendway_location',
				'desc_tip' => __( 'Start typing and pick your address from the list. We look it up for you, no Google account needed.', 'sendway' ),
			),
			array(
				'title'             => __( 'Directions for the rider', 'sendway' ),
				'id'                => 'sendway_origin_directions',
				'type'              => 'text',
				'placeholder'       => __( 'e.g. 2nd floor, shop B12, next to the lifts', 'sendway' ),
				'desc_tip'          => __( 'Optional. Floor, shop number or a landmark so the rider finds you quickly. Up to 120 characters.', 'sendway' ),
				'css'               => 'width:400px',
				'custom_attributes' => array( 'maxlength' => 120 ),
			),
			array(
				'title'       => __( 'Phone for pickups', 'sendway' ),
				'id'          => 'sendway_sender_phone',
				'type'        => 'text',
				'placeholder' => '0712345678',
				'desc_tip'    => __( 'The number our rider calls when collecting. Needed to book parcels.', 'sendway' ),
				'css'         => 'width:200px',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_collection',
			),

			array(
				'title' => __( 'At checkout', 'sendway' ),
				'type'  => 'title',
				'desc'  => __( 'Customers choose a town to collect from, or their door in Nairobi, and see the SendWay price for it. While SendWay is connected, the phone number is required at checkout because our rider and the collection PIN both need it.', 'sendway' ),
				'id'    => 'sendway_checkout',
			),
			array(
				'title'   => __( 'Door delivery in Nairobi', 'sendway' ),
				'id'      => 'sendway_offer_door',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Let customers in the Nairobi metro have their order brought to their door, as well as collecting it in their town.', 'sendway' ),
			),
			array(
				'title'   => __( 'Items SendWay prices by hand', 'sendway' ),
				'id'      => 'sendway_offer_quoted',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Still offer SendWay at checkout for orders such as large sofas or very valuable goods, shown as "price confirmed after you order" at no charge. We send SendWay a quote request when the order comes in, and you accept the price from the order. You settle the delivery cost with your customer yourself.', 'sendway' ),
			),
			array(
				'title'   => __( 'Cash on delivery', 'sendway' ),
				'id'      => 'sendway_collect_cod',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'For orders paid by cash on delivery, SendWay collects the order total from your customer and credits your SendWay wallet, less a 1.5% handling fee.', 'sendway' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_checkout',
			),

			array(
				'title' => __( 'Booking', 'sendway' ),
				'type'  => 'title',
				'id'    => 'sendway_booking',
			),
			array(
				'title'    => __( 'Book the parcel when an order is', 'sendway' ),
				'id'       => 'sendway_book_on_status',
				'type'     => 'select',
				'options'  => self::statuses(),
				'default'  => 'processing',
				'desc_tip' => __( 'The parcel is created when an order reaches this status. Most shops use Processing, the point where payment has cleared.', 'sendway' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_booking',
			),

			array(
				'title' => __( 'Delivery updates', 'sendway' ),
				'type'  => 'title',
				'desc'  => __( 'SendWay tells your store when a parcel is collected, arrives or is picked up, and completes the order for you.', 'sendway' ),
				'id'    => 'sendway_updates',
			),
			array(
				'title' => __( 'Status', 'sendway' ),
				'type'  => 'sendway_webhook',
			),
			array(
				'title'    => __( 'Signing secret', 'sendway' ),
				'id'       => 'sendway_webhook_secret',
				'type'     => 'password',
				'desc_tip' => __( 'Filled in for you when updates are set up automatically. Only paste one here if you registered the webhook yourself.', 'sendway' ),
				'css'      => 'width:400px',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'sendway_updates',
			),
		);
	}

	public static function render_location( $field ) {
		$address  = (string) get_option( 'sendway_origin_address', '' );
		$verified = (bool) SendWay_API::origin();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="sendway_origin_address"><?php echo esc_html( $field['title'] ); ?> <?php echo wc_help_tip( $field['desc_tip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>
			<td class="forminp">
				<input type="text" id="sendway_origin_address" name="sendway_origin_address" value="<?php echo esc_attr( $address ); ?>" autocomplete="off" style="width:400px" placeholder="<?php esc_attr_e( 'Search your building, street or estate', 'sendway' ); ?>" />
				<input type="hidden" id="sendway_origin_place" name="sendway_origin_place" value="" />
				<p class="description sendway-addr__status <?php echo $verified ? 'sendway-addr__status--ok' : ''; ?>" id="sendway_origin_status" data-verified="<?php echo $verified ? '1' : '0'; ?>">
					<?php
					if ( $verified ) {
						esc_html_e( 'Location confirmed.', 'sendway' );
					} elseif ( '' !== $address ) {
						esc_html_e( 'This address has not been confirmed. Search again and pick it from the list.', 'sendway' );
					}
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	public static function render_webhook( $field ) {
		$secret = trim( (string) get_option( 'sendway_webhook_secret', '' ) );
		$error  = (string) get_option( 'sendway_webhook_error', '' );
		$url    = rest_url( 'sendway/v1/webhook' );

		if ( $secret && get_option( 'sendway_webhook_id' ) ) {
			$text  = __( 'Set up. Delivery updates arrive automatically.', 'sendway' );
			$class = 'sendway-addr__status--ok';
		} elseif ( $secret ) {
			$text  = __( 'Using the signing secret you pasted in.', 'sendway' );
			$class = 'sendway-addr__status--ok';
		} elseif ( 0 !== strpos( $url, 'https://' ) ) {
			$text  = __( 'Your site is not on https yet, for example a test site on localhost, so SendWay cannot send updates to it. They are set up automatically when you save these settings on your live https site.', 'sendway' );
			$class = '';
		} elseif ( 'insufficient_scope' === $error ) {
			$text  = __( 'Your API key cannot set up delivery updates. Create a new key in SendWay → Developers with the webhooks:manage permission ticked, paste it above and save.', 'sendway' );
			$class = 'sendway-addr__status--bad';
		} elseif ( $error ) {
			/* translators: %s: error from SendWay */
			$text  = sprintf( __( 'Delivery updates could not be set up: %s Save again to retry.', 'sendway' ), $error );
			$class = 'sendway-addr__status--bad';
		} else {
			$text  = __( 'Not set up yet. Save these settings with a working API key and it is done for you.', 'sendway' );
			$class = '';
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<p class="sendway-addr__status <?php echo esc_attr( $class ); ?>" style="margin-top:0"><?php echo esc_html( $text ); ?></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the webhook URL */
						esc_html__( 'Updates are sent to %s', 'sendway' ),
						'<code>' . esc_html( $url ) . '</code>'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	public static function normalize_phone( $raw ) {
		$digits = preg_replace( '/[^\d+]/', '', (string) $raw );
		if ( preg_match( '/^(?:\+?254|0)?([17]\d{8})$/', $digits, $m ) ) {
			return '0' . $m[1];
		}
		return null;
	}

	public static function save() {
		$previous = array(
			'key'     => SendWay_API::key(),
			'address' => (string) get_option( 'sendway_origin_address', '' ),
			'phone'   => (string) get_option( 'sendway_sender_phone', '' ),
			'status'  => (string) get_option( 'sendway_book_on_status', 'processing' ),
			'auto'    => (bool) get_option( 'sendway_webhook_id' ),
		);

		WC_Admin_Settings::save_fields( self::fields() );

		self::save_location( $previous['address'] );

		$phone = trim( (string) get_option( 'sendway_sender_phone', '' ) );
		if ( '' !== $phone ) {
			$normal = self::normalize_phone( $phone );
			if ( $normal ) {
				update_option( 'sendway_sender_phone', $normal );
			} else {
				update_option( 'sendway_sender_phone', $previous['phone'] );
				WC_Admin_Settings::add_error( __( 'The pickup phone must be a Kenyan mobile number, like 0712345678. The previous number was kept.', 'sendway' ) );
			}
		}

		if ( ! array_key_exists( get_option( 'sendway_book_on_status' ), self::statuses() ) ) {
			update_option( 'sendway_book_on_status', array_key_exists( $previous['status'], self::statuses() ) ? $previous['status'] : 'processing' );
		}

		$key_changed = SendWay_API::key() !== $previous['key'];
		if ( $key_changed ) {
			SendWay_API::clear_towns_cache();
			delete_option( 'sendway_webhook_id' );
			delete_option( 'sendway_webhook_error' );
			if ( $previous['auto'] ) {
				update_option( 'sendway_webhook_secret', '' );
			}
			if ( ! self::validate_key() ) {
				return;
			}
		}

		if ( SendWay_API::has_key() && '' === trim( (string) get_option( 'sendway_webhook_secret', '' ) ) ) {
			SendWay_Webhooks::connect();
		}
	}

	private static function save_location( $previous_address ) {
		$address = trim( (string) get_option( 'sendway_origin_address', '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$place = isset( $_POST['sendway_origin_place'] ) ? sanitize_text_field( wp_unslash( $_POST['sendway_origin_place'] ) ) : '';

		if ( '' === $address ) {
			update_option( 'sendway_origin_lat', '' );
			update_option( 'sendway_origin_lng', '' );
			update_option( 'sendway_origin_verified', 'no' );
			return;
		}

		if ( '' === $place ) {
			if ( $address !== $previous_address ) {
				update_option( 'sendway_origin_address', $previous_address );
				WC_Admin_Settings::add_error( __( 'Pick your collection address from the suggestions so we know exactly where to collect. The previous address was kept.', 'sendway' ) );
			}
			return;
		}

		$pick = SendWay_Address::picked( $place );
		if ( ! $pick ) {
			update_option( 'sendway_origin_address', $previous_address );
			WC_Admin_Settings::add_error( __( 'That address lookup expired. Search for your collection address again and pick it from the list.', 'sendway' ) );
			return;
		}
		if ( empty( $pick['deliverable'] ) ) {
			update_option( 'sendway_origin_address', $previous_address );
			WC_Admin_Settings::add_error( __( 'We do not collect from that address yet, because a rider cannot reach it. The previous address was kept.', 'sendway' ) );
			return;
		}

		update_option( 'sendway_origin_address', $pick['address'] );
		update_option( 'sendway_origin_lat', (string) $pick['latitude'] );
		update_option( 'sendway_origin_lng', (string) $pick['longitude'] );
		update_option( 'sendway_origin_verified', 'yes' );
		SendWay_Address::forget( $place );
	}

	private static function validate_key() {
		if ( ! SendWay_API::has_key() ) {
			return false;
		}
		$res = SendWay_API::request( 'GET', '/towns' );
		if ( is_wp_error( $res ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message from SendWay */
					__( 'SendWay rejected that API key: %s', 'sendway' ),
					$res->get_error_message()
				)
			);
			return false;
		}
		WC_Admin_Settings::add_message( __( 'SendWay connected.', 'sendway' ) );
		return true;
	}
}
