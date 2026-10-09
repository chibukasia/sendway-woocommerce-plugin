<?php
/**
 * The pickup-town picker at checkout.
 *
 * This is the piece a generic shipping plugin cannot express: a Kenyan buyer
 * does not want a courier guessing their street, they want to name the town
 * they will collect from. Choosing it also drives the rate.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Checkout {

	const FIELD   = 'sendway_town_id';
	const SESSION = 'sendway_town_id';

	public static function init() {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render_picker' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_from_review' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_to_order' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function towns() {
		return SendWay_API::towns();
	}

	public static function town_by_id( $id ) {
		foreach ( self::towns() as $town ) {
			if ( isset( $town['id'] ) && $town['id'] === $id ) {
				return $town;
			}
		}
		return null;
	}

	/** The town currently chosen, from the posted form or the session. */
	public static function chosen_town_id() {
		if ( isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			return WC()->session->get( self::SESSION );
		}
		return null;
	}

	/**
	 * WooCommerce posts the whole checkout form as a serialised string when the
	 * order review refreshes, so the town has to be pulled out of there for the
	 * rate to recalculate as the customer changes it.
	 */
	public static function capture_from_review( $post_data ) {
		parse_str( (string) $post_data, $fields );
		if ( ! empty( $fields[ self::FIELD ] ) && WC()->session ) {
			WC()->session->set( self::SESSION, sanitize_text_field( $fields[ self::FIELD ] ) );
			// The chosen town changes the price, so the cached rate must go.
			WC()->session->set( 'shipping_for_package_0', null );
		}
	}

	/**
	 * The picker, matching the Shopinn checkout: a trigger showing the chosen
	 * town, and a modal with one search box over the whole network. A grouped
	 * <select> of 60+ towns is not something anyone wants to scroll on a phone.
	 */
	public static function render_picker( $checkout ) {
		$towns = self::towns();
		if ( empty( $towns ) ) {
			return; // Not configured, or SendWay unreachable — stay out of the way.
		}

		$selected_id = self::chosen_town_id();
		$selected    = $selected_id ? self::town_by_id( $selected_id ) : null;

		echo '<div id="sendway-town-field" class="sendway-field">';
		echo '<h3>' . esc_html__( 'Where should we deliver?', 'sendway' ) . '</h3>';

		printf(
			'<input type="hidden" name="%1$s" id="%1$s" value="%2$s" />',
			esc_attr( self::FIELD ),
			esc_attr( $selected_id )
		);

		// Unchosen: a dashed call to action. Chosen: the place, plus Change.
		echo '<div id="sendway-trigger-wrap">';
		echo self::trigger_html( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<p class="sendway-help">'
			. esc_html__( 'You will collect your parcel from a SendWay agent in this town, using a PIN sent by SMS.', 'sendway' )
			. '</p>';
		echo '</div>';

		// The modal lives at the end of the form so checkout's own markup is
		// untouched; it is hidden until the trigger opens it.
		echo '<div id="sendway-modal" class="sendway-modal" hidden>
			<div class="sendway-modal__backdrop" data-sendway-close></div>
			<div class="sendway-modal__panel" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Choose your pickup town', 'sendway' ) . '">
				<div class="sendway-modal__head">
					<strong>' . esc_html__( 'Choose your pickup town', 'sendway' ) . '</strong>
					<button type="button" class="sendway-modal__x" data-sendway-close aria-label="' . esc_attr__( 'Close', 'sendway' ) . '">&times;</button>
				</div>
				<input type="search" id="sendway-search" class="sendway-search" placeholder="' . esc_attr__( 'Search by name, town or county...', 'sendway' ) . '" autocomplete="off" />
				<div id="sendway-results" class="sendway-results"></div>
			</div>
		</div>';
	}

	/** Markup for the trigger, re-rendered by JS when the choice changes. */
	public static function trigger_html( $town ) {
		if ( empty( $town ) ) {
			return '<button type="button" class="sendway-trigger sendway-trigger--empty" data-sendway-open>'
				. '<span class="sendway-trigger__icon">&#128230;</span>'
				. '<span class="sendway-trigger__body">'
				. '<span class="sendway-trigger__title">' . esc_html__( 'Choose your pickup town', 'sendway' ) . '</span>'
				. '<span class="sendway-trigger__sub">' . esc_html__( 'We courier to the town and you collect there', 'sendway' ) . '</span>'
				. '</span>'
				. '<span class="sendway-trigger__chev">&rsaquo;</span>'
				. '</button>';
		}

		$sub = trim( ( isset( $town['sub_county'] ) ? $town['sub_county'] : '' ) . ', ' . ( isset( $town['county'] ) ? $town['county'] : '' ), ', ' );

		return '<div class="sendway-chosen">'
			. '<span class="sendway-trigger__icon">&#128230;</span>'
			. '<span class="sendway-chosen__body">'
			. '<span class="sendway-chosen__name">' . esc_html( $town['name'] ) . '</span>'
			. '<span class="sendway-chosen__sub">' . esc_html( $sub ) . '</span>'
			. '</span>'
			. '<button type="button" class="sendway-change" data-sendway-open>' . esc_html__( 'Change', 'sendway' ) . '</button>'
			. '</div>';
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$towns = self::towns();
		if ( empty( $towns ) ) {
			return;
		}

		// The town list is already fetched server-side and cached, so the search
		// filters it in the browser — no extra round trip per keystroke, and it
		// keeps working if the API is briefly unreachable.
		wp_localize_script(
			'wc-checkout',
			'SendWayTowns',
			array(
				'towns'    => array_values( $towns ),
				'noResult' => __( 'No pickup towns found', 'sendway' ),
				'tryAgain' => __( 'Try a different search term', 'sendway' ),
				'change'   => __( 'Change', 'sendway' ),
			)
		);

		wp_add_inline_style( 'woocommerce-general', self::css() );
		wp_add_inline_script( 'wc-checkout', self::js() );
	}

	private static function css() {
		return '
.sendway-field{margin:1.5em 0}
.sendway-field h3{margin-bottom:.5em}
.sendway-help{font-size:.8em;color:#666;margin-top:.5em}
.sendway-trigger{display:flex;align-items:center;gap:12px;width:100%;padding:16px;border:2px dashed #cbd5e1;border-radius:12px;background:transparent;text-align:left;cursor:pointer}
.sendway-trigger:hover{border-color:#e51015;background:rgba(229,16,21,.04)}
.sendway-trigger__icon{flex:0 0 auto;font-size:20px}
.sendway-trigger__body{flex:1 1 auto;display:block}
.sendway-trigger__title{display:block;font-weight:700;font-size:.95em}
.sendway-trigger__sub{display:block;font-size:.8em;color:#64748b;margin-top:2px}
.sendway-trigger__chev{color:#94a3b8;font-size:22px;line-height:1}
.sendway-chosen{display:flex;align-items:flex-start;gap:12px;padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
.sendway-chosen__body{flex:1 1 auto;min-width:0}
.sendway-chosen__name{display:block;font-weight:700;font-size:.95em}
.sendway-chosen__sub{display:block;font-size:.8em;color:#64748b;margin-top:2px}
.sendway-change{background:none;border:0;color:#e51015;font-weight:700;cursor:pointer;padding:0;font-size:.85em}
.sendway-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px}
.sendway-modal[hidden]{display:none}
.sendway-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45)}
.sendway-modal__panel{position:relative;display:flex;flex-direction:column;width:100%;max-width:520px;max-height:80vh;background:#fff;border-radius:16px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.sendway-modal__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.sendway-modal__x{background:none;border:0;font-size:26px;line-height:1;cursor:pointer;color:#94a3b8}
.sendway-search{width:100%;height:44px;padding:0 12px;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:12px}
.sendway-results{flex:1 1 auto;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
.sendway-row{display:flex;align-items:flex-start;gap:12px;width:100%;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;text-align:left;cursor:pointer}
.sendway-row:hover{border-color:#e51015;background:rgba(229,16,21,.04)}
.sendway-row__name{display:block;font-weight:700;font-size:.92em}
.sendway-row__sub{display:block;font-size:.8em;color:#64748b;margin-top:2px}
.sendway-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.sendway-chip{font-size:.72em;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px}
.sendway-empty{text-align:center;padding:40px 0;color:#64748b}
';
	}

	private static function js() {
		return <<<'JS'
jQuery(function ($) {
  var data = window.SendWayTowns || { towns: [] };
  var $modal = $('#sendway-modal');
  var $field = $('#sendway_town_id');

  function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

  function render(list) {
    if (!list.length) {
      $('#sendway-results').html(
        '<div class="sendway-empty"><strong>' + esc(data.noResult) + '</strong><br><span>' + esc(data.tryAgain) + '</span></div>'
      );
      return;
    }
    $('#sendway-results').html(list.map(function (t) {
      var sub = [t.sub_county, t.county].filter(Boolean).join(', ');
      return '<button type="button" class="sendway-row" data-id="' + esc(t.id) + '">' +
        '<span class="sendway-trigger__icon">&#128205;</span>' +
        '<span style="flex:1 1 auto;min-width:0">' +
          '<span class="sendway-row__name">' + esc(t.name) + '</span>' +
          '<span class="sendway-row__sub">' + esc(sub) + '</span>' +
          '<span class="sendway-chips">' +
            '<span class="sendway-chip">' + esc(t.county) + '</span>' +
            (t.zone ? '<span class="sendway-chip">Zone ' + esc(t.zone) + '</span>' : '') +
          '</span>' +
        '</span>' +
      '</button>';
    }).join(''));
  }

  function filter(q) {
    q = (q || '').toLowerCase().trim();
    if (!q) return data.towns;
    return data.towns.filter(function (t) {
      return ((t.name || '') + ' ' + (t.sub_county || '') + ' ' + (t.county || '')).toLowerCase().indexOf(q) > -1;
    });
  }

  function open() {
    $modal.prop('hidden', false);
    $('#sendway-search').val('').trigger('focus');
    render(data.towns);
  }
  function close() { $modal.prop('hidden', true); }

  $(document.body).on('click', '[data-sendway-open]', open);
  $(document.body).on('click', '[data-sendway-close]', close);
  $(document).on('keydown', function (e) { if (e.key === 'Escape') close(); });
  $(document.body).on('input', '#sendway-search', function () { render(filter($(this).val())); });

  $(document.body).on('click', '.sendway-row', function () {
    var id = $(this).data('id');
    var town = data.towns.filter(function (t) { return String(t.id) === String(id); })[0];
    if (!town) return;
    $field.val(town.id);
    var sub = [town.sub_county, town.county].filter(Boolean).join(', ');
    $('#sendway-trigger-wrap').html(
      '<div class="sendway-chosen">' +
        '<span class="sendway-trigger__icon">&#128230;</span>' +
        '<span class="sendway-chosen__body">' +
          '<span class="sendway-chosen__name">' + esc(town.name) + '</span>' +
          '<span class="sendway-chosen__sub">' + esc(sub) + '</span>' +
        '</span>' +
        '<button type="button" class="sendway-change" data-sendway-open>' + esc(data.change) + '</button>' +
      '</div>'
    );
    close();
    // The chosen town changes the price, so the order review must re-fetch.
    $(document.body).trigger('update_checkout');
  });
});
JS;
	}

	public static function validate() {
		if ( empty( self::towns() ) ) {
			return;
		}
		$chosen = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Only required when the customer actually picked SendWay shipping.
		$methods = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		$uses_sendway = false;
		foreach ( $methods as $method ) {
			if ( 0 === strpos( (string) $method, 'sendway' ) ) {
				$uses_sendway = true;
				break;
			}
		}
		if ( ! $uses_sendway ) {
			return;
		}

		if ( '' === $chosen ) {
			wc_add_notice( __( 'Please choose the town you will collect your parcel from.', 'sendway' ), 'error' );
			return;
		}
		if ( ! self::town_by_id( $chosen ) ) {
			wc_add_notice( __( 'That town is no longer available. Please choose another.', 'sendway' ), 'error' );
		}
	}

	public static function persist_to_order( $order, $data ) {
		$chosen = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $chosen ) {
			return;
		}
		$town = self::town_by_id( $chosen );
		$order->update_meta_data( '_sendway_town_id', $chosen );
		if ( $town ) {
			$order->update_meta_data( '_sendway_town_name', $town['name'] );
			$order->update_meta_data( '_sendway_town_county', isset( $town['county'] ) ? $town['county'] : '' );
		}
	}
}
