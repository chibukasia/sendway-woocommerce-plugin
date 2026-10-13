<?php
/**
 * Keeps installed copies up to date from our GitHub releases.
 *
 * WordPress only knows about updates for plugins it can find on wordpress.org.
 * For anything else you have to answer two questions yourself: "is there a
 * newer version?" (the update_plugins transient) and "tell me about it"
 * (plugins_api). This does both from a small JSON manifest published beside the
 * zip on each release, so pushing a release is all it takes for merchants to be
 * offered the update in wp-admin.
 *
 * @package SendWay
 */

defined( 'ABSPATH' ) || exit;

class SendWay_Updater {

	/** Both URLs redirect to the latest GitHub release asset. */
	const MANIFEST = 'https://sendway.co.ke/sendway-woocommerce.json';

	const CACHE_KEY = 'sendway_update_manifest';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
	}

	/** The plugin's folder/file key, e.g. sendway-woocommerce/sendway.php. */
	public static function basename() {
		return plugin_basename( SENDWAY_PATH . 'sendway.php' );
	}

	public static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * The published manifest, cached. A failed fetch returns null and is cached
	 * briefly too, so an outage at our end does not mean an HTTP request on
	 * every single admin page load.
	 */
	public static function manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}
		}

		$res = wp_remote_get(
			self::MANIFEST,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::CACHE_KEY, 'none', MINUTE_IN_SECONDS * 15 );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			set_transient( self::CACHE_KEY, 'none', MINUTE_IN_SECONDS * 15 );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/** Tell WordPress an update exists, in the shape it expects. */
	public static function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $transient;
		}

		$file = self::basename();

		if ( version_compare( $manifest['version'], SENDWAY_VERSION, '>' ) ) {
			$transient->response[ $file ] = (object) array(
				'slug'         => self::slug(),
				'plugin'       => $file,
				'new_version'  => $manifest['version'],
				'package'      => $manifest['download_url'],
				'url'          => isset( $manifest['homepage'] ) ? $manifest['homepage'] : 'https://sendway.co.ke',
				'tested'       => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
				'requires'     => isset( $manifest['requires'] ) ? $manifest['requires'] : '',
				'requires_php' => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '',
			);
			unset( $transient->no_update[ $file ] );
		} else {
			// Saying "no update" explicitly is what makes the plugin eligible
			// for WordPress's auto-update toggle.
			$transient->no_update[ $file ] = (object) array(
				'slug'        => self::slug(),
				'plugin'      => $file,
				'new_version' => SENDWAY_VERSION,
				'package'     => '',
				'url'         => 'https://sendway.co.ke',
			);
		}

		return $transient;
	}

	/** The "View details" panel in wp-admin. */
	public static function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$manifest = self::manifest();
		if ( ! $manifest ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $manifest['name'] ) ? $manifest['name'] : 'SendWay for WooCommerce',
			'slug'          => self::slug(),
			'version'       => $manifest['version'],
			'author'        => '<a href="https://sendway.co.ke">Fecn Technologies Ltd</a>',
			'homepage'      => isset( $manifest['homepage'] ) ? $manifest['homepage'] : 'https://sendway.co.ke',
			'requires'      => isset( $manifest['requires'] ) ? $manifest['requires'] : '',
			'requires_php'  => isset( $manifest['requires_php'] ) ? $manifest['requires_php'] : '',
			'tested'        => isset( $manifest['tested'] ) ? $manifest['tested'] : '',
			'last_updated'  => isset( $manifest['last_updated'] ) ? $manifest['last_updated'] : '',
			'download_link' => $manifest['download_url'],
			'sections'      => array(
				'description' => isset( $manifest['description'] ) ? $manifest['description'] : '',
				'changelog'   => isset( $manifest['changelog'] ) ? $manifest['changelog'] : '',
			),
		);
	}

	/** After any plugin update, next check should be fresh. */
	public static function flush( $upgrader, $options ) {
		unset( $upgrader );
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}
