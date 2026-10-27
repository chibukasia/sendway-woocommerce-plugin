<?php
/**
 * Door delivery inside the Nairobi metro: the customer searches their address
 * and SendWay confirms a rider can reach it before the rate is shown.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Door {

	const NONCE   = 'sendway_door';
	const SESSION = 'sendway_door';

	const SEARCH_PER_MINUTE = 40;

	public static function init() {
		foreach ( array( 'sendway_door_search', 'sendway_door_resolve' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( 'sendway_door_', 'ajax_', $action ) ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, str_replace( 'sendway_door_', 'ajax_', $action ) ) );
		}
	}

	public static function enabled() {
		return 'yes' === get_option( 'sendway_offer_door', 'yes' );
	}

	/** The door address chosen at checkout: array( lat, lng, address, directions ) or null. */
	public static function chosen() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$door = WC()->session->get( self::SESSION );
		return is_array( $door ) && isset( $door['lat'], $door['lng'] ) ? $door : null;
	}

	public static function remember( $door ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION, $door );
			WC()->session->set( 'shipping_for_package_0', null );
		}
	}

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::enabled() || ! SendWay_API::has_key() ) {
			wp_send_json_error( array( 'message' => __( 'Door delivery is not available.', 'sendway' ) ), 400 );
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'sendway_door_rl_' . md5( $ip . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::SEARCH_PER_MINUTE ) {
			wp_send_json_error( array( 'message' => __( 'Too many searches. Please wait a moment and try again.', 'sendway' ) ), 429 );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS * 2 );
	}

	public static function ajax_search() {
		self::guard();

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$token = isset( $_POST['session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['session_token'] ) ) : '';
		if ( mb_strlen( $query ) < 3 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$cache_key = 'sendway_door_q_' . md5( mb_strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$res = SendWay_API::search_addresses( $query, $token );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		$payload = array(
			'results'     => isset( $res['data'] ) ? $res['data'] : array(),
			'attribution' => isset( $res['attribution'] ) ? $res['attribution'] : 'Powered by Google',
		);
		set_transient( $cache_key, $payload, HOUR_IN_SECONDS * 6 );
		wp_send_json_success( $payload );
	}

	public static function ajax_resolve() {
		self::guard();

		$place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		$token    = isset( $_POST['session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['session_token'] ) ) : '';
		if ( '' === $place_id ) {
			wp_send_json_error( array( 'message' => __( 'Choose your address from the list.', 'sendway' ) ), 400 );
		}

		$res = SendWay_API::resolve_address( $place_id, $token );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		if ( empty( $res['door_delivery'] ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'We do not deliver to the door at that address yet. Choose "Collect in my town" instead, or try a nearby address.', 'sendway' ),
					'outside'  => true,
				),
				400
			);
		}

		$door = array(
			'lat'        => (float) $res['latitude'],
			'lng'        => (float) $res['longitude'],
			'address'    => isset( $res['address'] ) ? (string) $res['address'] : '',
			'directions' => '',
		);
		$previous = self::chosen();
		if ( $previous && isset( $previous['directions'] ) ) {
			$door['directions'] = $previous['directions'];
		}
		self::remember( $door );

		wp_send_json_success( $door );
	}

	public static function panel_html() {
		$door = self::chosen();
		$has  = (bool) $door;

		$html  = '<div id="sendway-door-panel" class="sendway-door">';
		$html .= '<div class="sendway-door__search"' . ( $has ? ' hidden' : '' ) . '>';
		$html .= '<input type="search" id="sendway-door-input" class="sendway-search" autocomplete="off" placeholder="' . esc_attr__( 'Search your building, street or estate', 'sendway' ) . '" />';
		$html .= '<div id="sendway-door-results" class="sendway-door__results" hidden></div>';
		$html .= '</div>';
		$html .= '<div id="sendway-door-chosen" class="sendway-chosen"' . ( $has ? '' : ' hidden' ) . '>';
		$html .= self::pin_svg( '#16a34a' );
		$html .= '<span class="sendway-chosen__body"><span class="sendway-chosen__name" id="sendway-door-address">' . esc_html( $has ? $door['address'] : '' ) . '</span>';
		$html .= '<span class="sendway-chosen__sub">' . esc_html__( 'A rider brings it to your door', 'sendway' ) . '</span></span>';
		$html .= '<button type="button" class="sendway-change" id="sendway-door-change">' . esc_html__( 'Change', 'sendway' ) . '</button>';
		$html .= '</div>';
		$html .= '<input type="text" id="sendway-door-directions" name="sendway_door_directions" class="sendway-search sendway-door__directions" maxlength="120" value="' . esc_attr( $has ? $door['directions'] : '' ) . '" placeholder="' . esc_attr__( 'House, floor or gate details for the rider (optional)', 'sendway' ) . '"' . ( $has ? '' : ' hidden' ) . ' />';
		$html .= '<p id="sendway-door-error" class="sendway-door__error" hidden></p>';
		$html .= '<p class="sendway-help">' . esc_html__( 'Door delivery covers the Nairobi metro. Large items and bulk orders go to a pickup town.', 'sendway' ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	public static function pin_svg( $color ) {
		return '<span class="sendway-trigger__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>';
	}

	public static function box_svg() {
		return '<span class="sendway-trigger__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e51015" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/></svg></span>';
	}

	public static function script_data() {
		return array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'searching' => __( 'Searching…', 'sendway' ),
			'noMatch'   => __( 'No match. Try a nearby building, road or estate.', 'sendway' ),
			'checking'  => __( 'Checking we deliver there…', 'sendway' ),
		);
	}

	public static function js() {
		return <<<'JS'
jQuery(function ($) {
  var cfg = window.SendWayDoor;
  if (!cfg) return;
  var token = '';
  var timer = null;
  function newToken() { token = 'wc' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
  function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
  function showError(msg) { $('#sendway-door-error').text(msg || '').prop('hidden', !msg); }
  newToken();

  $(document.body).on('input', '#sendway-door-input', function () {
    var q = $(this).val().trim();
    clearTimeout(timer);
    showError('');
    if (q.length < 3) { $('#sendway-door-results').prop('hidden', true).empty(); return; }
    timer = setTimeout(function () {
      $('#sendway-door-results').prop('hidden', false).html('<div class="sendway-door__hint">' + esc(cfg.searching) + '</div>');
      $.post(cfg.ajax, { action: 'sendway_door_search', nonce: cfg.nonce, query: q, session_token: token }).done(function (res) {
        var list = (res && res.success && res.data.results) || [];
        if (!list.length) { $('#sendway-door-results').html('<div class="sendway-door__hint">' + esc(cfg.noMatch) + '</div>'); return; }
        $('#sendway-door-results').html(list.map(function (r) {
          return '<button type="button" class="sendway-door__row" data-place="' + esc(r.place_id) + '">' +
            '<span class="sendway-row__name">' + esc(r.main_text || r.description) + '</span>' +
            '<span class="sendway-row__sub">' + esc(r.secondary_text || '') + '</span></button>';
        }).join('') + '<div class="sendway-door__google">' + esc(res.data.attribution || 'Powered by Google') + '</div>');
      }).fail(function (xhr) {
        var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
        $('#sendway-door-results').prop('hidden', true).empty();
        showError(msg || '');
      });
    }, 350);
  });

  $(document.body).on('click', '.sendway-door__row', function () {
    var place = $(this).data('place');
    $('#sendway-door-results').html('<div class="sendway-door__hint">' + esc(cfg.checking) + '</div>');
    $.post(cfg.ajax, { action: 'sendway_door_resolve', nonce: cfg.nonce, place_id: place, session_token: token }).done(function (res) {
      newToken();
      if (!res || !res.success) return;
      $('#sendway-door-address').text(res.data.address);
      $('#sendway_door_lat').val(res.data.lat);
      $('#sendway_door_lng').val(res.data.lng);
      $('#sendway_door_address').val(res.data.address);
      $('#sendway-door-results').prop('hidden', true).empty();
      $('.sendway-door__search').prop('hidden', true);
      $('#sendway-door-chosen, #sendway-door-directions').prop('hidden', false);
      $(document.body).trigger('update_checkout');
    }).fail(function (xhr) {
      newToken();
      var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
      $('#sendway-door-results').prop('hidden', true).empty();
      showError(msg || '');
    });
  });

  $(document.body).on('click', '#sendway-door-change', function () {
    $('#sendway-door-chosen').prop('hidden', true);
    $('.sendway-door__search').prop('hidden', false);
    $('#sendway-door-input').val('').trigger('focus');
  });
});
JS;
	}

	public static function css() {
		return '
.sendway-door{margin-top:4px}
.sendway-door__search{position:relative}
.sendway-door__results{display:flex;flex-direction:column;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-top:-4px;margin-bottom:8px;background:#fff}
.sendway-door__row{display:block;width:100%;padding:10px 12px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;text-align:left;cursor:pointer}
.sendway-door__row:hover{background:rgba(229,16,21,.04)}
.sendway-door__hint{padding:10px 12px;font-size:.85em;color:#64748b}
.sendway-door__google{padding:6px 12px;font-size:.7em;color:#94a3b8;text-align:right}
.sendway-door__directions{margin-top:8px}
.sendway-door__error{margin:6px 0 0;font-size:.85em;color:#b45309}
';
	}
}
