<?php
/**
 * Address search for the settings screen.
 *
 * The shop used to be asked to right-click its own premises in Google Maps and
 * paste two numbers. Now it types an address and picks it, with the lookup
 * running on SendWay's Google key — merchants need no Maps account at all.
 *
 * The browser talks to WordPress, which talks to SendWay. The SendWay key never
 * leaves the server, exactly as it does not for quotes or bookings.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Address {

	const NONCE = 'sendway_address';

	public static function init() {
		add_action( 'wp_ajax_sendway_address_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_sendway_address_resolve', array( __CLASS__, 'ajax_resolve' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'sendway' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function ajax_search() {
		self::guard();

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$token = isset( $_POST['session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['session_token'] ) ) : '';

		if ( strlen( $query ) < 3 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$res = SendWay_API::search_addresses( $query, $token );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'results'     => isset( $res['data'] ) ? $res['data'] : array(),
				// Google requires visible credit wherever its results are shown.
				'attribution' => isset( $res['attribution'] ) ? $res['attribution'] : 'Powered by Google',
			)
		);
	}

	public static function ajax_resolve() {
		self::guard();

		$place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '';
		$token    = isset( $_POST['session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['session_token'] ) ) : '';

		if ( '' === $place_id ) {
			wp_send_json_error( array( 'message' => __( 'Pick an address from the list.', 'sendway' ) ), 400 );
		}

		$res = SendWay_API::resolve_address( $place_id, $token );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		set_transient(
			self::pick_key( $place_id ),
			array(
				'address'     => isset( $res['address'] ) ? (string) $res['address'] : '',
				'latitude'    => isset( $res['latitude'] ) ? (float) $res['latitude'] : null,
				'longitude'   => isset( $res['longitude'] ) ? (float) $res['longitude'] : null,
				'deliverable' => ! empty( $res['deliverable'] ),
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success( $res );
	}

	private static function pick_key( $place_id ) {
		return 'sendway_pick_' . get_current_user_id() . '_' . md5( $place_id );
	}

	public static function picked( $place_id ) {
		$pick = get_transient( self::pick_key( $place_id ) );
		return is_array( $pick ) && null !== $pick['latitude'] && null !== $pick['longitude'] ? $pick : null;
	}

	public static function forget( $place_id ) {
		delete_transient( self::pick_key( $place_id ) );
	}

	public static function enqueue( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'shipping' !== $tab || 'sendway' !== $section ) {
			return;
		}

		wp_register_script( 'sendway-address', '', array( 'jquery' ), SENDWAY_VERSION, true );
		wp_enqueue_script( 'sendway-address' );
		wp_localize_script(
			'sendway-address',
			'SendWayAddress',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE ),
				'searching'  => __( 'Searching…', 'sendway' ),
				'noResults'  => __( 'No addresses found. Try adding the town.', 'sendway' ),
				'outside'    => __( 'We do not collect from this address yet, because a rider cannot reach it.', 'sendway' ),
				'confirmed'  => __( 'Location confirmed. Save changes to keep it.', 'sendway' ),
				'unconfirmed' => __( 'Pick your address from the list to confirm it.', 'sendway' ),
			)
		);
		wp_add_inline_script( 'sendway-address', self::js() );
		wp_register_style( 'sendway-address', false, array(), SENDWAY_VERSION );
		wp_enqueue_style( 'sendway-address' );
		wp_add_inline_style( 'sendway-address', self::css() );
	}

	private static function css() {
		return '
.sendway-addr{position:relative;max-width:520px}
.sendway-addr__list{position:absolute;z-index:20;left:0;right:0;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-top:4px;max-height:280px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.sendway-addr__item{display:block;width:100%;text-align:left;padding:10px 12px;border:0;background:none;cursor:pointer;border-bottom:1px solid #f0f0f1}
.sendway-addr__item:last-child{border-bottom:0}
.sendway-addr__item:hover{background:#f6f7f7}
.sendway-addr__main{display:block;font-weight:600}
.sendway-addr__sub{display:block;font-size:12px;color:#646970}
.sendway-addr__credit{display:block;font-size:11px;color:#646970;padding:6px 12px;border-top:1px solid #f0f0f1}
.sendway-addr__status{margin-top:6px;font-size:12px}
.sendway-addr__status--ok{color:#007017}
.sendway-addr__status--bad{color:#b32d2e}
';
	}

	private static function js() {
		return <<<'JS'
jQuery(function ($) {
  var cfg = window.SendWayAddress || {};
  var $addr = $('#sendway_origin_address');
  var $place = $('#sendway_origin_place');
  var $status = $('#sendway_origin_status');
  if (!$addr.length) return;

  var token = null;
  function newToken() {
    token = 'sw-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    return token;
  }
  newToken();

  $addr.wrap('<span class="sendway-addr"></span>');
  var $wrap = $addr.parent();
  var $list = $('<div class="sendway-addr__list" style="display:none"></div>').appendTo($wrap);
  var picked = $addr.val();

  function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
  function hide() { $list.hide().empty(); }
  function status(text, state) {
    $status.text(text || '').attr('class', 'description sendway-addr__status' + (state ? ' sendway-addr__status--' + state : ''));
  }

  var timer = null;
  $addr.on('input', function () {
    var q = $(this).val();
    if (q !== picked) {
      $place.val('');
      status(q ? cfg.unconfirmed : '', q ? 'bad' : '');
    } else if ($status.data('verified')) {
      status(cfg.confirmed, 'ok');
    }
    clearTimeout(timer);
    if (!q || q.length < 3) { hide(); return; }
    timer = setTimeout(function () {
      $list.show().html('<div class="sendway-addr__credit">' + esc(cfg.searching) + '</div>');
      $.post(cfg.ajaxUrl, {
        action: 'sendway_address_search', nonce: cfg.nonce, query: q, session_token: token
      }).done(function (res) {
        if (!res || !res.success) { hide(); return; }
        var rows = res.data.results || [];
        if (!rows.length) {
          $list.html('<div class="sendway-addr__credit">' + esc(cfg.noResults) + '</div>');
          return;
        }
        $list.html(rows.map(function (r) {
          return '<button type="button" class="sendway-addr__item" data-pid="' + esc(r.place_id) + '">' +
            '<span class="sendway-addr__main">' + esc(r.main_text) + '</span>' +
            '<span class="sendway-addr__sub">' + esc(r.secondary_text) + '</span>' +
          '</button>';
        }).join('') + '<span class="sendway-addr__credit">' + esc(res.data.attribution) + '</span>');
      }).fail(function (xhr) {
        var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
        hide();
        if (msg) status(msg, 'bad');
      });
    }, 300);
  });

  $list.on('click', '.sendway-addr__item', function () {
    var pid = $(this).data('pid');
    hide();
    $.post(cfg.ajaxUrl, {
      action: 'sendway_address_resolve', nonce: cfg.nonce, place_id: pid, session_token: token
    }).done(function (res) {
      newToken();
      if (!res || !res.success) return;
      var a = res.data;
      picked = a.address;
      $addr.val(a.address);
      if (a.deliverable) {
        $place.val(pid);
        status(cfg.confirmed, 'ok');
      } else {
        $place.val('');
        status(cfg.outside, 'bad');
      }
    }).fail(function (xhr) {
      newToken();
      var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
      status(msg || cfg.unconfirmed, 'bad');
    });
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.sendway-addr').length) hide();
  });
});
JS;
	}
}
