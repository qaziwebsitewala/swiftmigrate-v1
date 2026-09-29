<?php
/**
 * File-based working storage.
 *
 * State lives on disk (not only in the database) on purpose: the destination
 * database is replaced during restore, so anything kept only in wp_options
 * would disappear halfway through the migration.
 *
 * Location: <uploads>/swiftmigrate/data-<random>/, resolved at runtime with
 * wp_upload_dir(). The random part makes the folder unguessable; on top of
 * that every level carries index.php, .htaccess and web.config rules that deny
 * web access. The absolute path is remembered in the swiftmigrate_storage
 * option, which the restore carries over into the incoming database so the
 * folder stays the same while the database is switched.
 *
 * Nothing executable is ever stored here: incoming files are kept under
 * hashed names with a .bin extension until they are moved into place (see
 * SwiftMigrate_Receiver::staged()).
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Private working folder.
 */
class SwiftMigrate_Storage {

	/** Folder in the uploads directory, named after the plugin slug. */
	const DIR_NAME = 'swiftmigrate';

	/** Option holding the absolute path of the private working folder. */
	const OPTION = 'swiftmigrate_storage';

	/**
	 * Resolved base folder for this request.
	 *
	 * @var string|null
	 */
	private static $base;

	/**
	 * <uploads>/swiftmigrate, without trailing slash.
	 *
	 * @return string
	 * @throws RuntimeException When the uploads directory is unavailable.
	 */
	public static function parent_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			throw new RuntimeException( esc_html__( 'The uploads folder is not available. Check that it exists and is writable by PHP.', 'swiftmigrate' ) );
		}
		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/' . self::DIR_NAME;
	}

	/**
	 * Whether a path has the shape of a SwiftMigrate data folder.
	 *
	 * @param string $dir Path.
	 * @return bool
	 */
	private static function is_data_dir( $dir ) {
		$dir = wp_normalize_path( (string) $dir );
		return (bool) preg_match( '/^data-[a-f0-9]{24}$/', basename( $dir ) ) && self::DIR_NAME === basename( dirname( $dir ) );
	}

	/**
	 * The data folder if it already exists; never creates anything.
	 *
	 * @return string Path, or '' when there is none yet.
	 */
	public static function existing_base() {
		$saved = get_option( self::OPTION );
		if ( is_string( $saved ) && self::is_data_dir( $saved ) && is_dir( $saved ) ) {
			return untrailingslashit( wp_normalize_path( $saved ) );
		}
		try {
			$parent = self::parent_dir();
		} catch ( RuntimeException $e ) {
			return '';
		}
		foreach ( (array) glob( $parent . '/data-*', GLOB_ONLYDIR ) as $dir ) {
			if ( self::is_data_dir( $dir ) ) {
				return untrailingslashit( wp_normalize_path( $dir ) );
			}
		}
		return '';
	}

	/**
	 * Returns (and creates on first use) the private data folder.
	 *
	 * @return string
	 * @throws RuntimeException When the folder cannot be created.
	 */
	public static function base() {
		if ( self::$base && is_dir( self::$base ) ) {
			return self::$base;
		}
		$dir = self::existing_base();
		if ( '' === $dir ) {
			$dir = self::parent_dir() . '/data-' . bin2hex( random_bytes( 12 ) );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'Cannot create the SwiftMigrate folder in the uploads directory. Check that the uploads folder is writable by PHP.', 'swiftmigrate' ) );
		}
		self::protect( dirname( $dir ) );
		self::protect( $dir );
		if ( get_option( self::OPTION ) !== $dir ) {
			update_option( self::OPTION, $dir, false );
		}
		self::$base = $dir;
		return $dir;
	}

	/**
	 * Denies web access on Apache, LiteSpeed and IIS; an empty index file for
	 * everything else. The folder name is random, so it cannot be guessed on
	 * servers that ignore these rules (for example nginx).
	 *
	 * @param string $dir Folder.
	 */
	public static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				SwiftMigrate_Fs::put( $dir . '/' . $name, $content );
			}
		}
	}

	/**
	 * Path of a file inside the data folder; parent folders are created.
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	public static function path( $rel ) {
		$path = self::base() . '/' . ltrim( $rel, '/' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $path;
	}

	/**
	 * Folder of one migration.
	 *
	 * @param string $id Migration id.
	 * @return string
	 */
	public static function migration_dir( $id ) {
		$id  = self::sanitize_id( $id );
		$dir = self::base() . '/m-' . $id;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Reduces a migration id to [a-z0-9].
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) );
	}

	/**
	 * Reads a JSON state file.
	 *
	 * @param string $file    Path.
	 * @param mixed  $default Returned when missing or invalid.
	 * @return mixed
	 */
	public static function read_json( $file, $default = array() ) {
		$raw = SwiftMigrate_Fs::get( $file );
		if ( false === $raw ) {
			return $default;
		}
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : $default;
	}

	/**
	 * Atomic write: temp file + rename, so a crash never leaves half a JSON document.
	 *
	 * @param string $file Path.
	 * @param mixed  $data Data.
	 * @throws RuntimeException When the file cannot be written.
	 */
	public static function write_json( $file, $data ) {
		$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( ! SwiftMigrate_Fs::put( $tmp, $json, true ) ) {
			/* translators: %s: file name. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot write %s. Check that the uploads folder is writable.', 'swiftmigrate' ), basename( $file ) ) ) );
		}
		if ( ! SwiftMigrate_Fs::replace( $tmp, $file ) ) {
			SwiftMigrate_Fs::delete( $file );
			if ( ! SwiftMigrate_Fs::replace( $tmp, $file ) ) {
				SwiftMigrate_Fs::delete( $tmp );
				/* translators: %s: file name. */
				throw new RuntimeException( esc_html( sprintf( __( 'Cannot replace %s.', 'swiftmigrate' ), basename( $file ) ) ) );
			}
		}
	}

	/**
	 * Read-modify-write under an exclusive lock. Used where parallel requests
	 * may touch the same state (counters, receipts).
	 *
	 * @param string   $file Path.
	 * @param callable $fn   Receives the current data, returns the new data.
	 * @return mixed New data.
	 * @throws RuntimeException When the lock file cannot be opened.
	 */
	public static function update_json( $file, callable $fn ) {
		$lock = SwiftMigrate_Fs::open( $file . '.lock', 'c' );
		if ( ! $lock ) {
			throw new RuntimeException( esc_html__( 'Cannot open lock file.', 'swiftmigrate' ) );
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
	 *
	 * @param string $rel Relative path.
	 * @return string|false
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

	/**
	 * Removes a folder tree in time-boxed steps.
	 *
	 * @param string $dir      Folder.
	 * @param float  $deadline microtime() deadline.
	 * @return bool Whether the folder is gone.
	 */
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

	/**
	 * Free disk space, or null when the host does not tell.
	 *
	 * @param string $dir Folder on the disk to check.
	 * @return float|null
	 */
	public static function free_space( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) || ! is_dir( $dir ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some hosts; null means "unknown".
		return false === $free ? null : (float) $free;
	}
}
