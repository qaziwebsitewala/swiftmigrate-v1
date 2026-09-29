<?php
/**
 * Migration keys and request signing.
 *
 * A key is generated on the destination and pasted on the source. It carries the
 * destination endpoint and a 256-bit secret. Every request is signed with
 * HMAC-SHA256 over action, timestamp, nonce and the SHA-256 of the body, so a
 * captured request can neither be modified nor replayed.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keys, signatures and replay protection.
 */
class SwiftMigrate_Security {

	const KEY_PREFIX   = 'SMG1-';
	const CLOCK_WINDOW = 600;

	public static function receive_file() {
		return SwiftMigrate_Storage::path( 'receive.json' );
	}

	public static function get_receive() {
		return SwiftMigrate_Storage::read_json( self::receive_file(), array() );
	}

	public static function generate_key( $hours = 24 ) {
		$secret = bin2hex( random_bytes( 32 ) );
		$data   = array(
			'secret'  => $secret,
			'created' => time(),
			'expires' => time() + max( 1, (int) $hours ) * HOUR_IN_SECONDS,
			'enabled' => true,
		);
		SwiftMigrate_Storage::write_json( self::receive_file(), $data );
		return self::encode_key( $secret );
	}

	public static function encode_key( $secret ) {
		$payload = array(
			'v' => SWIFTMIGRATE_PROTOCOL,
			'u' => self::endpoint_url(),
			'f' => self::fallback_url(),
			's' => $secret,
		);
		return self::KEY_PREFIX . rtrim( strtr( base64_encode( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
	}

	public static function current_key() {
		$r = self::get_receive();
		if ( empty( $r['secret'] ) || empty( $r['enabled'] ) || $r['expires'] < time() ) {
			return '';
		}
		return self::encode_key( $r['secret'] );
	}

	public static function revoke() {
		SwiftMigrate_Storage::write_json( self::receive_file(), array( 'enabled' => false ) );
	}

	/** Extends key lifetime (used after restore so a rollback stays possible). */
	public static function extend( $hours ) {
		SwiftMigrate_Storage::update_json(
			self::receive_file(),
			function ( $r ) use ( $hours ) {
				if ( ! empty( $r['secret'] ) ) {
					$r['expires'] = max( (int) $r['expires'], time() + $hours * HOUR_IN_SECONDS );
				}
				return $r;
			}
		);
	}

	/**
	 * REST endpoint of this site (pretty permalink form).
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return SwiftMigrate_Api::url();
	}

	/**
	 * Same endpoint via ?rest_route=, for sites without pretty permalinks or with /wp-json/ blocked.
	 *
	 * @return string
	 */
	public static function fallback_url() {
		return SwiftMigrate_Api::fallback_url();
	}

	/** @return array{url:string,secret:string} */
	public static function decode_key( $key ) {
		$key = trim( preg_replace( '/\s+/', '', (string) $key ) );
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			throw new InvalidArgumentException( esc_html__( 'This is not a SwiftMigrate key. Copy the full key from the destination site\'s "Receive a site" page.', 'swiftmigrate' ) );
		}
		$b64  = strtr( substr( $key, strlen( self::KEY_PREFIX ) ), '-_', '+/' );
		$json = base64_decode( $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 ), true );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['s'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $data['s'] ) ) {
			throw new InvalidArgumentException( esc_html__( 'The key is incomplete or damaged. Copy it again.', 'swiftmigrate' ) );
		}
		if ( ! wp_http_validate_url( $data['u'] ) && ! preg_match( '#^https?://#', $data['u'] ) ) {
			throw new InvalidArgumentException( esc_html__( 'The key contains an invalid site address.', 'swiftmigrate' ) );
		}
		return array(
			'url'      => $data['u'],
			'fallback' => isset( $data['f'] ) ? $data['f'] : '',
			'secret'   => $data['s'],
		);
	}

	public static function sign( $secret, $action, $ts, $nonce, $body ) {
		return hash_hmac( 'sha256', $action . "\n" . $ts . "\n" . $nonce . "\n" . hash( 'sha256', $body ), $secret );
	}

	/**
	 * Verifies an inbound request. Throws on failure.
	 *
	 * @param string $action Action name.
	 * @param string $body   Raw request body, exactly as received.
	 * @param int    $ts     Request timestamp.
	 * @param string $nonce  Request nonce (32 hex characters).
	 * @param string $sig    HMAC-SHA256 signature (64 hex characters).
	 * @throws RuntimeException When the request is not authentic.
	 */
	public static function verify_request( $action, $body, $ts, $nonce, $sig ) {
		$r = self::get_receive();
		if ( empty( $r['secret'] ) || empty( $r['enabled'] ) ) {
			throw new RuntimeException( esc_html__( 'This site is not accepting migrations. Generate a key on its Receive page.', 'swiftmigrate' ), 403 );
		}
		if ( (int) $r['expires'] < time() ) {
			throw new RuntimeException( esc_html__( 'The migration key has expired. Generate a new key on the destination.', 'swiftmigrate' ), 403 );
		}
		if ( abs( time() - (int) $ts ) > self::CLOCK_WINDOW ) {
			throw new RuntimeException( esc_html__( 'Request timestamp is outside the allowed window. Check both servers\' clocks.', 'swiftmigrate' ), 401 );
		}
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $nonce ) ) {
			throw new RuntimeException( esc_html__( 'Missing request nonce.', 'swiftmigrate' ), 401 );
		}
		$expected = self::sign( $r['secret'], $action, (int) $ts, $nonce, $body );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			self::throttle_failure();
			throw new RuntimeException( esc_html__( 'Signature mismatch. The key on the source does not match this site.', 'swiftmigrate' ), 401 );
		}
		self::consume_nonce( $nonce );
	}

	/** Each nonce can be used once. Files are created with mode "x" which is atomic. */
	private static function consume_nonce( $nonce ) {
		$dir = SwiftMigrate_Storage::base() . '/nonces/' . substr( $nonce, 0, 2 );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! SwiftMigrate_Fs::create_exclusive( $dir . '/' . $nonce ) ) {
			throw new RuntimeException( esc_html__( 'Replayed request rejected.', 'swiftmigrate' ), 401 );
		}
		if ( 0 === random_int( 0, 400 ) ) {
			self::prune_nonces();
		}
	}

	private static function prune_nonces() {
		$root = SwiftMigrate_Storage::base() . '/nonces';
		$cut  = time() - 2 * self::CLOCK_WINDOW;
		foreach ( (array) glob( $root . '/*/*' ) as $f ) {
			if ( (int) filemtime( $f ) < $cut ) {
				SwiftMigrate_Fs::delete( $f );
			}
		}
	}

	/** Slows brute force: after repeated bad signatures, add delay. */
	private static function throttle_failure() {
		$file = SwiftMigrate_Storage::path( 'failures.json' );
		$data = SwiftMigrate_Storage::update_json(
			$file,
			function ( $d ) {
				$now = time();
				$d   = array_values( array_filter( (array) $d, function ( $t ) use ( $now ) {
					return $t > $now - 900;
				} ) );
				$d[] = $now;
				return $d;
			}
		);
		if ( count( $data ) > 20 ) {
			sleep( 3 );
		}
	}

	public static function nonce() {
		return bin2hex( random_bytes( 16 ) );
	}

	// ---------------------------------------------------------------- transport encryption

	/**
	 * Loads WordPress's bundled sodium_compat library when the PHP sodium
	 * extension is missing, and reports whether authenticated encryption is available.
	 *
	 * @return bool
	 */
	public static function can_encrypt() {
		if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && is_readable( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' );
	}

	/**
	 * Whether traffic to this URL must be encrypted by SwiftMigrate itself:
	 * plain HTTP does not encrypt it, and HTTPS without certificate
	 * verification cannot be trusted to.
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	public static function needs_encryption( $url ) {
		return 'https' !== strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) ) || ! SwiftMigrate_Settings::get( 'sslverify' );
	}

	/**
	 * 256-bit encryption key derived from the migration secret (distinct from the signing key).
	 *
	 * @param string $secret Migration secret (64 hex characters).
	 * @return string Raw 32-byte key.
	 */
	private static function box_key( $secret ) {
		return hash_hmac( 'sha256', 'swiftmigrate-transport-encryption-v1', (string) $secret, true );
	}

	/**
	 * Encrypts a message with XChaCha20-Poly1305 (random 192-bit nonce prepended).
	 *
	 * @param string $secret  Migration secret.
	 * @param string $plain   Message.
	 * @param string $context Associated data (the action name), authenticated but not encrypted.
	 * @return string Nonce + ciphertext.
	 * @throws RuntimeException When encryption is unavailable.
	 */
	public static function encrypt( $secret, $plain, $context ) {
		if ( ! self::can_encrypt() ) {
			throw new RuntimeException( esc_html__( 'Encryption is not available on this server, so data cannot be sent over plain HTTP. Enable HTTPS on the destination site.', 'swiftmigrate' ) );
		}
		$nonce = random_bytes( 24 );
		return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( (string) $plain, (string) $context, $nonce, self::box_key( $secret ) );
	}

	/**
	 * Decrypts a message produced by encrypt().
	 *
	 * @param string $secret  Migration secret.
	 * @param string $box     Nonce + ciphertext.
	 * @param string $context Associated data used when encrypting.
	 * @return string
	 * @throws RuntimeException When the message cannot be decrypted.
	 */
	public static function decrypt( $secret, $box, $context ) {
		$box = (string) $box;
		if ( ! self::can_encrypt() || strlen( $box ) < 40 ) {
			throw new RuntimeException( esc_html__( 'Cannot decrypt the transferred data.', 'swiftmigrate' ), 400 );
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $box, 24 ), (string) $context, substr( $box, 0, 24 ), self::box_key( $secret ) );
		if ( false === $plain ) {
			throw new RuntimeException( esc_html__( 'Cannot decrypt the transferred data.', 'swiftmigrate' ), 400 );
		}
		return $plain;
	}

	/**
	 * The active migration secret of this (destination) site, or ''.
	 *
	 * @return string
	 */
	public static function receive_secret() {
		$r = self::get_receive();
		return empty( $r['secret'] ) ? '' : (string) $r['secret'];
	}
}
