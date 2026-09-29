<?php
/**
 * Serialize-safe search & replace.
 *
 * Instead of unserialize() → replace → serialize() (which instantiates objects,
 * breaks on classes that are not loaded and is an object-injection risk), this
 * walks the serialized grammar directly, rewrites string tokens and recomputes
 * their byte lengths. Objects, private/protected properties, nested serialized
 * strings and JSON with escaped slashes all survive intact.
 *
 * strtr() is used for the actual replacement: one pass, longest match first, so
 * a new URL that contains the old one is never replaced twice.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serialize-safe search and replace.
 */
class SwiftMigrate_Search_Replace {

	private $pairs;
	private $needles;
	private $s;
	private $p;
	private $depth = 0;

	public function __construct( array $pairs ) {
		$this->pairs   = array_filter( $pairs, 'strlen', ARRAY_FILTER_USE_KEY );
		$this->needles = array_keys( $this->pairs );
	}

	/** Builds the replacement pairs for an old → new site move. */
	public static function build_pairs( array $from, array $to, array $custom = array() ) {
		$pairs = array();
		$add   = function ( $a, $b ) use ( &$pairs ) {
			if ( '' === $a || $a === $b ) {
				return;
			}
			$pairs[ $a ] = $b;
			// JSON-encoded form (Gutenberg blocks, page builders): https:\/\/site.com
			$pairs[ str_replace( '/', '\\/', $a ) ] = str_replace( '/', '\\/', $b );
			// URL-encoded form (query strings, some builders).
			$pairs[ rawurlencode( $a ) ] = rawurlencode( $b );
		};
		foreach ( array( 'home', 'siteurl' ) as $k ) {
			if ( empty( $from[ $k ] ) || empty( $to[ $k ] ) ) {
				continue;
			}
			$old = untrailingslashit( $from[ $k ] );
			$new = untrailingslashit( $to[ $k ] );
			$add( $old, $new );
			// Protocol variants and protocol-relative references.
			$old_host = preg_replace( '#^https?:#i', '', $old );
			$new_host = preg_replace( '#^https?:#i', '', $new );
			$new_scheme = strtolower( (string) wp_parse_url( $new, PHP_URL_SCHEME ) );
			$add( 'http:' . $old_host, $new_scheme . ':' . $new_host );
			$add( 'https:' . $old_host, $new_scheme . ':' . $new_host );
			$add( $old_host, $new_host );
		}
		if ( ! empty( $from['abspath'] ) && ! empty( $to['abspath'] ) ) {
			$add( untrailingslashit( $from['abspath'] ), untrailingslashit( $to['abspath'] ) );
		}
		if ( ! empty( $from['content_dir'] ) && ! empty( $to['content_dir'] ) ) {
			$add( untrailingslashit( $from['content_dir'] ), untrailingslashit( $to['content_dir'] ) );
		}
		foreach ( $custom as $row ) {
			if ( isset( $row[0], $row[1] ) && '' !== $row[0] ) {
				$pairs[ $row[0] ] = $row[1];
			}
		}
		return $pairs;
	}

	public function has_work() {
		return ! empty( $this->pairs );
	}

	public function needles() {
		return $this->needles;
	}

	/** Returns the value with all replacements applied (or the same value if untouched). */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->contains( $value ) ) {
			return $value;
		}
		return $this->replace_string( $value );
	}

	private function contains( $str ) {
		foreach ( $this->needles as $n ) {
			if ( false !== strpos( $str, $n ) ) {
				return true;
			}
		}
		return false;
	}

	private function replace_string( $str ) {
		if ( $this->depth < 20 && self::looks_serialized( $str ) ) {
			$saved = array( $this->s, $this->p );
			try {
				$this->depth++;
				$this->s = $str;
				$this->p = 0;
				$out     = $this->value( true );
				if ( $this->p === strlen( $str ) ) {
					return $out;
				}
			} catch ( Exception $e ) {
				// Not valid serialized data after all; fall through.
			} finally {
				$this->depth--;
				list( $this->s, $this->p ) = $saved;
			}
			// Looked serialized but did not parse cleanly: do not risk making it worse.
			if ( preg_match( '/^(a|O):\d+:/', $str ) ) {
				return $str;
			}
		}
		return strtr( $str, $this->pairs );
	}

	public static function looks_serialized( $str ) {
		$str = (string) $str;
		if ( strlen( $str ) < 4 || ':' !== $str[1] ) {
			return 'N;' === $str;
		}
		$last = substr( $str, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return (bool) preg_match( '/^[aOsibdCE]:/', $str );
	}

	// ---- Grammar walker ------------------------------------------------------
	// The UnexpectedValueException messages below are internal parser signals:
	// they are always caught in replace_string() and never shown to users.

	private function value( $replace ) {
		$s = $this->s;
		if ( ! isset( $s[ $this->p ] ) ) {
			throw new UnexpectedValueException( esc_html( 'eof' ) );
		}
		$t = $s[ $this->p ];
		switch ( $t ) {
			case 'N':
				$this->expect( 'N;' );
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $this->p );
				if ( false === $end ) {
					throw new UnexpectedValueException( esc_html( 'scalar' ) );
				}
				$tok     = substr( $s, $this->p, $end - $this->p + 1 );
				$this->p = $end + 1;
				return $tok;
			case 's':
				$this->expect( 's:' );
				$len = $this->int_until( ':' );
				$this->expect( '"' );
				$str = substr( $s, $this->p, $len );
				if ( strlen( $str ) !== $len ) {
					throw new UnexpectedValueException( esc_html( 'strlen' ) );
				}
				$this->p += $len;
				$this->expect( '";' );
				if ( $replace && $this->contains( $str ) ) {
					$str = $this->replace_string( $str );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';
			case 'E':
				$start = $this->p;
				$this->expect( 'E:' );
				$len = $this->int_until( ':' );
				$this->expect( '"' );
				$this->p += $len;
				$this->expect( '";' );
				return substr( $s, $start, $this->p - $start );
			case 'a':
				$this->expect( 'a:' );
				$n = $this->int_until( ':' );
				$this->expect( '{' );
				$out = 'a:' . $n . ':{';
				for ( $i = 0; $i < $n; $i++ ) {
					$out .= $this->value( false ); // Key.
					$out .= $this->value( $replace );
				}
				$this->expect( '}' );
				return $out . '}';
			case 'O':
				$this->expect( 'O:' );
				$clen = $this->int_until( ':' );
				$this->expect( '"' );
				$class    = substr( $s, $this->p, $clen );
				$this->p += $clen;
				$this->expect( '":' );
				$n = $this->int_until( ':' );
				$this->expect( '{' );
				$out = 'O:' . $clen . ':"' . $class . '":' . $n . ':{';
				for ( $i = 0; $i < $n; $i++ ) {
					$out .= $this->value( false );
					$out .= $this->value( $replace );
				}
				$this->expect( '}' );
				return $out . '}';
			case 'C':
				// Custom-serialized object: opaque payload, copied verbatim.
				$start = $this->p;
				$this->expect( 'C:' );
				$clen = $this->int_until( ':' );
				$this->expect( '"' );
				$this->p += $clen;
				$this->expect( '":' );
				$dlen = $this->int_until( ':' );
				$this->expect( '{' );
				$this->p += $dlen;
				$this->expect( '}' );
				return substr( $s, $start, $this->p - $start );
		}
		throw new UnexpectedValueException( esc_html( 'token' ) );
	}

	private function expect( $lit ) {
		$l = strlen( $lit );
		if ( substr( $this->s, $this->p, $l ) !== $lit ) {
			throw new UnexpectedValueException( esc_html( 'expect ' . $lit ) );
		}
		$this->p += $l;
	}

	private function int_until( $ch ) {
		$end = strpos( $this->s, $ch, $this->p );
		if ( false === $end ) {
			throw new UnexpectedValueException( esc_html( 'int' ) );
		}
		$num = substr( $this->s, $this->p, $end - $this->p );
		if ( ! preg_match( '/^-?\d+$/', $num ) ) {
			throw new UnexpectedValueException( esc_html( 'int' ) );
		}
		$this->p = $end + 1;
		return (int) $num;
	}
}
