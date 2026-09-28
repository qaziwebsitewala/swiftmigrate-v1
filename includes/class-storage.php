<?php
/**
 * File-based storage. State lives on disk (not in the database) on purpose:
 * the destination database is replaced during restore, so anything kept in
 * wp_options would disappear halfway through the migration.
 *
 * The folder is wp-content/swiftmigrate-data (not the plugin folder): it must
 * be on the same filesystem as themes, plugins and uploads so that restoring
 * is a fast rename, and it is protected from web access (see protect()).
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Private working folder.
 */
class SwiftMigrate_Storage {

	const DIR_NAME = 'swiftmigrate-data';

	public static function base() {
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::DIR_NAME;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( $dir );
		return $dir;
	}

	/** Deny web access on Apache, LiteSpeed, IIS; index.php for everything else. */
	public static function protect( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/web.config' ) ) {
			file_put_contents( $dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
	}

	public static function path( $rel ) {
		$path = self::base() . '/' . ltrim( $rel, '/' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $path;
	}

	public static function migration_dir( $id ) {
		$id = self::sanitize_id( $id );
		$dir = self::base() . '/m-' . $id;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function sanitize_id( $id ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
	}

	public static function read_json( $file, $default = array() ) {
		if ( ! is_readable( $file ) ) {
			return $default;
		}
		$raw = file_get_contents( $file );
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : $default;
	}

	/** Atomic write: temp file + rename, so a crash never leaves half a JSON document. */
	public static function write_json( $file, $data ) {
		$tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) {
			throw new RuntimeException( esc_html( 'Cannot write ' . basename( $file ) . '. Check that wp-content is writable.' ) );
		}
		if ( ! SwiftMigrate_Fs::replace( $tmp, $file ) ) {
			SwiftMigrate_Fs::delete( $file );
			if ( ! SwiftMigrate_Fs::replace( $tmp, $file ) ) {
				SwiftMigrate_Fs::delete( $tmp );
				throw new RuntimeException( esc_html( 'Cannot replace ' . basename( $file ) ) );
			}
		}
	}

	/**
	 * Read-modify-write under an exclusive lock. Used where parallel requests
	 * may touch the same state (counters, receipts).
	 */
	public static function update_json( $file, callable $fn ) {
		$lock = SwiftMigrate_Fs::open( $file . '.lock', 'c' );
		if ( ! $lock ) {
			throw new RuntimeException( esc_html( 'Cannot open lock file.' ) );
		}
		flock( $lock, LOCK_EX );
		try {
			$data = self::read_json( $file );
			$data = $fn( $data );
			self::write_json( $file, $data );
		} finally {
			flock( $lock, LOCK_UN );
			SwiftMigrate_Fs::close( $lock );
		}
		return $data;
	}

	/**
	 * Validates a relative path from the wire. Rejects traversal, absolute paths,
	 * NUL bytes and stream wrappers. Returns the normalised path or false.
	 */
	public static function safe_rel( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || preg_match( '#^[a-z]+://#i', $rel ) ) {
			return false;
		}
		if ( '/' === $rel[0] || preg_match( '#^[a-zA-Z]:#', $rel ) ) {
			return false;
		}
		$out = array();
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return false;
			}
			$out[] = $part;
		}
		return $out ? implode( '/', $out ) : false;
	}

	public static function rrmdir_step( $dir, $deadline ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( microtime( true ) > $deadline ) {
				return false;
			}
			if ( $item->isDir() && ! $item->isLink() ) {
				SwiftMigrate_Fs::rmdir( $item->getPathname() );
			} else {
				SwiftMigrate_Fs::delete( $item->getPathname() );
			}
		}
		SwiftMigrate_Fs::rmdir( $dir );
		return ! is_dir( $dir );
	}

	public static function free_space( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts; null means "unknown".
		return false === $free ? null : (float) $free;
	}
}
