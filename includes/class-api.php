<?php
/**
 * Inbound REST API used by the source site to talk to this (destination) site.
 *
 * Route: POST /wp-json/swiftmigrate/v1/transfer (or ?rest_route=/swiftmigrate/v1/transfer).
 *
 * The route is not tied to a logged-in user: the two sites are different
 * WordPress installs. Instead, every request must carry an HMAC-SHA256
 * signature made with the one-off secret from the migration key, a fresh
 * timestamp and a single-use nonce. See SwiftMigrate_Security.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for migration traffic.
 */
class SwiftMigrate_Api {

	const REST_NAMESPACE = 'swiftmigrate/v1';
	const REST_ROUTE     = '/transfer';

	/** Actions whose body is JSON. "put" carries a binary payload instead. */
	const JSON_ACTIONS = array( 'handshake', 'init', 'verify', 'restore', 'rollback', 'purge', 'abort', 'status' );

	/**
	 * Registers the REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Pretty REST URL of the transfer route on this site.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Same route addressed through ?rest_route=, which works even when
	 * pretty permalinks are off or /wp-json/ is blocked by the server.
	 *
	 * @return string
	 */
	public static function fallback_url() {
		return add_query_arg( 'rest_route', '/' . self::REST_NAMESPACE . self::REST_ROUTE, trailingslashit( home_url() ) );
	}

	/**
	 * Returns the requested action name, validated.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string Empty string when missing or invalid.
	 */
	private static function action( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_header( 'x_swiftmigrate_action' ) );
		if ( in_array( $action, self::JSON_ACTIONS, true ) || 'put' === $action ) {
			return $action;
		}
		return '';
	}

	/**
	 * Permission check: the request must be signed with this site's active migration key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$action = self::action( $request );
		if ( '' === $action ) {
			return new WP_Error( 'swiftmigrate_bad_action', __( 'Unknown action.', 'swiftmigrate' ), array( 'status' => 400 ) );
		}
		try {
			SwiftMigrate_Security::verify_request(
				$action,
				(string) $request->get_body(),
				(int) $request->get_header( 'x_swiftmigrate_ts' ),
				sanitize_key( (string) $request->get_header( 'x_swiftmigrate_nonce' ) ),
				sanitize_key( (string) $request->get_header( 'x_swiftmigrate_sig' ) )
			);
		} catch ( Exception $e ) {
			$code = (int) $e->getCode();
			return new WP_Error( 'swiftmigrate_forbidden', $e->getMessage(), array( 'status' => $code >= 400 && $code < 500 ? $code : 403 ) );
		}
		return true;
	}

	/**
	 * Handles a verified request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		// Migration requests are long-running by nature; the limits below apply to this request only.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to this signed migration request only.
		}
		wp_raise_memory_limit( 'admin' );

		$action = self::action( $request );
		$body   = (string) $request->get_body();

		try {
			if ( 'b64' === $request->get_header( 'x_swiftmigrate_enc' ) ) {
				$body = base64_decode( $body, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding for firewall-friendly mode; the body is HMAC-verified.
				if ( false === $body ) {
					throw new RuntimeException( esc_html( __( 'Invalid base64 body.', 'swiftmigrate' ) ), 400 );
				}
			}

			$receiver = new SwiftMigrate_Receiver();
			if ( 'put' === $action ) {
				$gz   = '1' === $request->get_header( 'x_swiftmigrate_gz' );
				$mig  = SwiftMigrate_Storage::sanitize_id( (string) $request->get_header( 'x_swiftmigrate_mig' ) );
				$data = $receiver->put( $mig, $body, $gz );
			} else {
				$in = json_decode( $body, true );
				if ( ! is_array( $in ) ) {
					$in = array();
				}
				$data = $receiver->$action( $in );
			}
			return self::response( 200, array( 'ok' => true, 'data' => $data ) );
		} catch ( Throwable $e ) {
			$code = (int) $e->getCode();
			if ( $code < 400 || $code > 599 ) {
				$code = 500;
			}
			return self::response( $code, array( 'ok' => false, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Builds a non-cacheable response in the envelope the source expects.
	 *
	 * @param int   $status  HTTP status.
	 * @param array $payload Payload.
	 * @return WP_REST_Response
	 */
	private static function response( $status, array $payload ) {
		$response = new WP_REST_Response( array( 'swiftmigrate' => SWIFTMIGRATE_PROTOCOL ) + $payload, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}
}
