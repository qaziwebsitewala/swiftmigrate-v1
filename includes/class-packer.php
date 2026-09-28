<?php
/**
 * Wire container: "SMGP" | uint32 header length | JSON header | raw payload.
 * Raw bytes, no base64, no multipart parsing: lowest CPU and size overhead.
 * Every item carries its own SHA-1; the whole body is covered by the HMAC.
 */

defined( 'ABSPATH' ) || exit;

class SwiftMigrate_Packer {

	const MAGIC = 'SMGP';

	/** Extensions whose content is already compressed; gzip would only burn CPU. */
	const INCOMPRESSIBLE = '/\.(jpe?g|png|gif|webp|avif|heic|mp[34]|m4[av]|mov|avi|mkv|webm|ogg|zip|gz|tgz|bz2|xz|7z|rar|woff2?|pdf|docx|xlsx|pptx|jar)$/i';

	public static function pack( array $header, $payload, $gzip ) {
		$h    = wp_json_encode( $header, JSON_UNESCAPED_SLASHES );
		$body = self::MAGIC . pack( 'N', strlen( $h ) ) . $h . $payload;
		if ( $gzip && function_exists( 'gzencode' ) ) {
			return array( gzencode( $body, 1 ), true );
		}
		return array( $body, false );
	}

	public static function unpack( $body, $gz ) {
		if ( $gz ) {
			$body = @gzdecode( $body );
			if ( false === $body ) {
				throw new RuntimeException( esc_html( 'Corrupt compressed payload.' ) );
			}
		}
		if ( strlen( $body ) < 8 || self::MAGIC !== substr( $body, 0, 4 ) ) {
			throw new RuntimeException( esc_html( 'Invalid payload container.' ) );
		}
		$len    = unpack( 'N', substr( $body, 4, 4 ) );
		$len    = (int) $len[1];
		$header = json_decode( substr( $body, 8, $len ), true );
		if ( ! is_array( $header ) ) {
			throw new RuntimeException( esc_html( 'Invalid payload header.' ) );
		}
		return array( $header, (string) substr( $body, 8 + $len ) );
	}

	public static function compressible( $path ) {
		return ! preg_match( self::INCOMPRESSIBLE, $path );
	}
}
