<?php
/**
 * Outbound, signed requests from the source to the destination site.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the destination's SwiftMigrate REST route.
 */
class SwiftMigrate_Client {

	/**
	 * Candidate endpoint URLs, preferred first.
	 *
	 * @var string[]
	 */
	private $urls;

	/**
	 * Shared HMAC secret.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Constructor.
	 *
	 * @param string|string[] $urls   Endpoint URL, or a list of equivalent URLs to fail over between.
	 * @param string          $secret Shared secret.
	 */
	public function __construct( $urls, $secret ) {
		$this->urls   = array_values( array_unique( array_filter( (array) $urls ) ) );
		$this->secret = $secret;
	}

	/**
	 * Endpoint currently in use.
	 *
	 * @return string
	 */
	public function url() {
		return (string) reset( $this->urls );
	}

	/**
	 * JSON request, JSON response.
	 *
	 * @param string   $action  Action.
	 * @param array    $data    Payload.
	 * @param int|null $timeout Timeout in seconds.
	 * @return array
	 */
	public function call( $action, array $data = array(), $timeout = null ) {
		return $this->raw( $action, wp_json_encode( $data, JSON_UNESCAPED_SLASHES ), 'application/json', array(), $timeout );
	}

	/**
	 * Binary request. The body is signed exactly as sent (after optional
	 * encryption and base64), so the destination verifies the very bytes it receives.
	 *
	 * @param string   $action  Action.
	 * @param string   $body    Body.
	 * @param string   $type    Content type.
	 * @param array    $extra   Extra headers.
	 * @param int|null $timeout Timeout in seconds.
	 * @return array
	 * @throws RuntimeException On transport or remote errors.
	 */
	public function raw( $action, $body, $type = 'application/octet-stream', array $extra = array(), $timeout = null ) {
		$last = null;
		foreach ( $this->urls as $i => $url ) {
			try {
				$result = $this->send( $url, $action, $body, $type, $extra, $timeout );
				if ( $i > 0 ) {
					// This URL works where the preferred one did not: keep using it.
					unset( $this->urls[ $i ] );
					array_unshift( $this->urls, $url );
				}
				return $result;
			} catch ( SwiftMigrate_Transport_Exception $e ) {
				$last = $e;
			}
		}
		throw new RuntimeException( esc_html( $last ? $last->getMessage() : __( 'No endpoint configured.', 'swiftmigrate' ) ), $last ? (int) $last->getCode() : 0 );
	}

	/**
	 * One signed request to one URL.
	 *
	 * Over plain HTTP the body is encrypted end-to-end with a key derived from
	 * the migration secret (XChaCha20-Poly1305), and the destination encrypts
	 * its answer the same way, so site data never crosses the network in clear text.
	 *
	 * @param string   $url     Endpoint.
	 * @param string   $action  Action.
	 * @param string   $body    Plain body.
	 * @param string   $type    Content type of the plain body.
	 * @param array    $extra   Extra headers.
	 * @param int|null $timeout Timeout.
	 * @return array
	 * @throws SwiftMigrate_Transport_Exception When the endpoint could not be reached or did not answer as SwiftMigrate.
	 * @throws RuntimeException When SwiftMigrate on the destination reported an error.
	 */
	private function send( $url, $action, $body, $type, array $extra, $timeout ) {
		$headers = array( 'Content-Type' => $type );
		$boxed   = SwiftMigrate_Security::needs_encryption( $url );
		if ( $boxed ) {
			$body                          = SwiftMigrate_Security::encrypt( $this->secret, $body, $action );
			$headers['Content-Type']       = 'application/octet-stream';
			$headers['X-SwiftMigrate-Box'] = '1';
		}
		if ( SwiftMigrate_Settings::get( 'firewall_safe' ) && 'application/json' !== $headers['Content-Type'] ) {
			$body                          = base64_encode( $body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding for firewall-friendly mode.
			$headers['Content-Type']       = 'text/plain';
			$headers['X-SwiftMigrate-Enc'] = 'b64';
		}
		foreach ( $extra as $k => $v ) {
			$headers[ $k ] = $v;
		}

		$ts    = time();
		$nonce = SwiftMigrate_Security::nonce();

		$headers['X-SwiftMigrate-Ts']     = (string) $ts;
		$headers['X-SwiftMigrate-Nonce']  = $nonce;
		$headers['X-SwiftMigrate-Sig']    = SwiftMigrate_Security::sign( $this->secret, $action, $ts, $nonce, $body );
		$headers['X-SwiftMigrate-Action'] = $action;
		$headers['X-SwiftMigrate-Proto']  = (string) SWIFTMIGRATE_PROTOCOL;

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout ? $timeout : (int) SwiftMigrate_Settings::get( 'request_timeout' ),
				'redirection' => 0,
				'sslverify'   => (bool) SwiftMigrate_Settings::get( 'sslverify' ),
				'headers'     => $headers,
				'body'        => $body,
				'user-agent'  => 'SwiftMigrate/' . SWIFTMIGRATE_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: network error message. */
			throw new SwiftMigrate_Transport_Exception( esc_html( sprintf( __( 'Cannot reach the destination: %s', 'swiftmigrate' ), $response->get_error_message() ) ), 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = self::extract_json( wp_remote_retrieve_body( $response ) );

		if ( null !== $json && isset( $json['box'] ) ) {
			$json = $this->open_box( $json['box'], $action );
		}

		if ( null === $json ) {
			$hint = '';
			if ( 413 === $code ) {
				$hint = __( 'The request was too large for the destination server; lower "Maximum request size" in Settings.', 'swiftmigrate' );
			} elseif ( 403 === $code || 406 === $code ) {
				$hint = __( 'A firewall may be blocking the request; enable "Firewall-friendly mode" in Settings.', 'swiftmigrate' );
			} elseif ( $code >= 300 && $code < 400 ) {
				$hint = __( 'The destination redirected the request. Generate the key again on the destination, using its final address (https, www).', 'swiftmigrate' );
			} elseif ( $code >= 500 ) {
				$hint = __( 'The destination server reported an internal error; check its PHP error log.', 'swiftmigrate' );
			}
			/* translators: %d: HTTP status code. */
			$msg = sprintf( __( 'The destination returned an unexpected response (HTTP %d).', 'swiftmigrate' ), $code );
			throw new SwiftMigrate_Transport_Exception( esc_html( trim( $msg . ' ' . $hint ) ), (int) $code );
		}
		if ( empty( $json['ok'] ) ) {
			$msg = isset( $json['error'] ) ? (string) $json['error'] : __( 'Unknown error on destination.', 'swiftmigrate' );
			if ( ! empty( $json['rest_error'] ) && 'rest_no_route' === $json['rest_error'] ) {
				// WordPress answered, but SwiftMigrate is not active there (or the route is unreachable at this URL).
				/* translators: %s: endpoint URL. */
				throw new SwiftMigrate_Transport_Exception( esc_html( sprintf( __( 'SwiftMigrate is not active on the destination (or its REST route is unavailable at %s).', 'swiftmigrate' ), $url ) ), 404 );
			}
			throw new RuntimeException( esc_html( wp_specialchars_decode( $msg, ENT_QUOTES ) ), (int) ( $code ? $code : 500 ) );
		}
		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}

	/**
	 * Decrypts an encrypted response envelope.
	 *
	 * @param string $b64    Base64 of nonce + ciphertext.
	 * @param string $action Action the response belongs to.
	 * @return array|null
	 */
	private function open_box( $b64, $action ) {
		$raw = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encrypted response payload.
		if ( false === $raw ) {
			return null;
		}
		try {
			$inner = json_decode( SwiftMigrate_Security::decrypt( $this->secret, $raw, 'response:' . $action ), true );
		} catch ( RuntimeException $e ) {
			return null;
		}
		return is_array( $inner ) ? $inner : null;
	}

	/**
	 * Finds SwiftMigrate's JSON envelope in a response body. Tolerates notices
	 * printed by other plugins before the JSON, and maps WordPress REST API error
	 * objects (e.g. blocked REST API) to the same envelope.
	 *
	 * @param string $raw Raw body.
	 * @return array|null
	 */
	private static function extract_json( $raw ) {
		$raw  = (string) $raw;
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$pos  = strpos( $raw, '{"swiftmigrate":' );
			$json = false !== $pos ? json_decode( substr( $raw, $pos ), true ) : null;
		}
		if ( ! is_array( $json ) ) {
			return null;
		}
		if ( isset( $json['swiftmigrate'] ) ) {
			return $json;
		}
		if ( isset( $json['code'], $json['message'] ) && is_string( $json['code'] ) ) {
			return array(
				'swiftmigrate' => 0,
				'ok'           => false,
				'error'        => (string) $json['message'],
				'rest_error'   => $json['code'],
			);
		}
		return null;
	}
}
