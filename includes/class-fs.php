<?php
/**
 * Filesystem primitives.
 *
 * Moves, deletes and directory removal go through WP_Filesystem_Direct and
 * wp_delete_file(). SwiftMigrate always works on local paths of this server
 * (the WordPress install and its content folder, as reported by
 * WP_Filesystem_Base::abspath() / wp_content_dir()), so the direct method is
 * used explicitly: an FTP/SSH
 * transport configured for updates would be far too slow for tens of
 * thousands of files and cannot stream chunks.
 *
 * Two things WP_Filesystem cannot do are wrapped here, in one place:
 * - streaming reads/writes at an offset (large files are sent in chunks);
 * - atomic replacement of a state file (WP_Filesystem::move() deletes the
 *   destination first, which parallel requests would observe as missing).
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local filesystem helper.
 */
class SwiftMigrate_Fs {

	/**
	 * Direct filesystem instance.
	 *
	 * @var WP_Filesystem_Direct|null
	 */
	private static $fs;

	/**
	 * Returns a WP_Filesystem_Direct instance.
	 *
	 * @return WP_Filesystem_Direct
	 */
	public static function fs() {
		if ( ! self::$fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			// Only move(), copy() without $mode, rmdir() and delete() are used, which need no FS_CHMOD_* constants.
			self::$fs = new WP_Filesystem_Direct( null );
		}
		return self::$fs;
	}

	/**
	 * WordPress's own answer to "where is wp-content on this server"
	 * (WP_Filesystem_Base::wp_content_dir()), normalised, without trailing slash.
	 *
	 * @return string
	 */
	public static function content_dir() {
		return untrailingslashit( wp_normalize_path( (string) self::fs()->wp_content_dir() ) );
	}

	/**
	 * WordPress's own answer to "where is the WordPress install on this server"
	 * (WP_Filesystem_Base::abspath()), normalised, without trailing slash.
	 *
	 * @return string
	 */
	public static function root_dir() {
		return untrailingslashit( wp_normalize_path( (string) self::fs()->abspath() ) );
	}

	/**
	 * Reads a whole local file.
	 *
	 * @param string $file Path.
	 * @return string|false
	 */
	public static function get( $file ) {
		return is_file( $file ) && is_readable( $file ) ? self::fs()->get_contents( $file ) : false;
	}

	/**
	 * Writes a whole file (create or replace), optionally under an exclusive lock.
	 *
	 * @param string $file Path.
	 * @param string $data Contents.
	 * @param bool   $lock Take an exclusive lock while writing.
	 * @return bool
	 */
	public static function put( $file, $data, $lock = false ) {
		// WP_Filesystem_Direct::put_contents() cannot lock; parallel migration requests need LOCK_EX. Local path under the plugin's own storage folder.
		return false !== file_put_contents( $file, (string) $data, $lock ? LOCK_EX : 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Appends to a file under an exclusive lock (logs, job lists).
	 *
	 * @param string $file Path.
	 * @param string $data Data.
	 * @return bool
	 */
	public static function append( $file, $data ) {
		// WP_Filesystem has no append mode; parallel requests append to the same journal. Local path under the plugin's own storage folder.
		return false !== file_put_contents( $file, (string) $data, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Deletes a file.
	 *
	 * @param string $file Path.
	 * @return bool Whether the file is gone.
	 */
	public static function delete( $file ) {
		if ( file_exists( $file ) || is_link( $file ) ) {
			wp_delete_file( $file );
		}
		return ! file_exists( $file );
	}

	/**
	 * Removes an empty directory.
	 *
	 * @param string $dir Path.
	 * @return bool
	 */
	public static function rmdir( $dir ) {
		return self::fs()->rmdir( $dir, false );
	}

	/**
	 * Moves a file (rename, or copy + delete across filesystems).
	 *
	 * @param string $from      Source.
	 * @param string $to        Destination.
	 * @param bool   $overwrite Replace an existing destination.
	 * @return bool
	 */
	public static function move( $from, $to, $overwrite = false ) {
		return self::fs()->move( $from, $to, $overwrite );
	}

	/**
	 * Copies a file.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @return bool
	 */
	public static function copy( $from, $to ) {
		return self::fs()->copy( $from, $to, true );
	}

	/**
	 * Atomically replaces $file with $tmp (same directory, POSIX rename semantics).
	 *
	 * @param string $tmp  Freshly written temporary file.
	 * @param string $file Target.
	 * @return bool
	 */
	public static function replace( $tmp, $file ) {
		// Atomic replace required: readers in parallel requests must never see the file missing. Failure is handled by the caller.
		return @rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Opens a file handle for streaming.
	 *
	 * @param string $file Path.
	 * @param string $mode fopen() mode.
	 * @return resource|false
	 */
	public static function open( $file, $mode ) {
		// Streaming chunked reads/writes have no WP_Filesystem equivalent. Failure is handled by the caller.
		return @fopen( $file, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Reads up to $length bytes from a handle.
	 *
	 * @param resource $fh     Handle.
	 * @param int      $length Bytes.
	 * @return string|false
	 */
	public static function read( $fh, $length ) {
		return fread( $fh, max( 1, (int) $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming chunked read.
	}

	/**
	 * Writes to a handle.
	 *
	 * @param resource $fh   Handle.
	 * @param string   $data Data.
	 * @return int|false Bytes written.
	 */
	public static function write( $fh, $data ) {
		return fwrite( $fh, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming chunked write at an offset.
	}

	/**
	 * Closes a handle.
	 *
	 * @param resource $fh Handle.
	 */
	public static function close( $fh ) {
		if ( is_resource( $fh ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with open().
		}
	}

	/**
	 * Creates a file if it does not exist yet; fails if it does (atomic).
	 * Used to make request nonces single-use.
	 *
	 * @param string $file Path.
	 * @return bool True if this call created the file.
	 */
	public static function create_exclusive( $file ) {
		$fh = self::open( $file, 'x' );
		if ( ! $fh ) {
			return false;
		}
		self::close( $fh );
		return true;
	}
}
