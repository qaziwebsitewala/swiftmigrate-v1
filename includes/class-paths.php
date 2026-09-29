<?php
/**
 * Locations of the trees a migration copies.
 *
 * A migration moves two trees: the WordPress install itself ("a") and its
 * content folder ("c"). Both are resolved here, in one place, through the
 * WordPress filesystem API (WP_Filesystem_Base::abspath() and
 * ::wp_content_dir()), so custom layouts (a moved wp-content, WordPress in a
 * subfolder, Windows paths) are handled the same way WordPress core handles
 * them during updates. Both values can be adjusted with filters.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Path helper.
 */
class SwiftMigrate_Paths {

	/**
	 * The site's content folder (normally wp-content), without trailing slash.
	 *
	 * @return string
	 */
	public static function content_dir() {
		/**
		 * Filters the content folder SwiftMigrate sends from and restores into.
		 *
		 * @param string $dir Absolute path, forward slashes, no trailing slash.
		 */
		return self::norm( apply_filters( 'swiftmigrate_content_dir', SwiftMigrate_Fs::content_dir() ) );
	}

	/**
	 * The WordPress install folder, without trailing slash.
	 *
	 * @return string
	 */
	public static function root_dir() {
		/**
		 * Filters the WordPress install folder SwiftMigrate sends from and restores into.
		 *
		 * @param string $dir Absolute path, forward slashes, no trailing slash.
		 */
		return self::norm( apply_filters( 'swiftmigrate_root_dir', SwiftMigrate_Fs::root_dir() ) );
	}

	/**
	 * Both roots, keyed as on the wire.
	 *
	 * @return array{c: string, a: string}
	 */
	public static function roots() {
		return array(
			'c' => self::content_dir(),
			'a' => self::root_dir(),
		);
	}

	/**
	 * The uploads folder of this site, without trailing slash ('' if unavailable).
	 *
	 * @return string
	 */
	public static function uploads_dir() {
		$uploads = wp_upload_dir( null, false );
		return empty( $uploads['basedir'] ) ? '' : self::norm( $uploads['basedir'] );
	}

	/**
	 * Path of $path relative to $root, or '' when $path is not inside $root.
	 *
	 * @param string $path Absolute path.
	 * @param string $root Absolute path.
	 * @return string
	 */
	public static function relative( $path, $root ) {
		$path = self::norm( $path );
		$root = self::norm( $root );
		if ( '' === $path || '' === $root || 0 !== strpos( $path . '/', $root . '/' ) ) {
			return '';
		}
		return ltrim( substr( $path, strlen( $root ) ), '/' );
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function norm( $path ) {
		return untrailingslashit( wp_normalize_path( (string) $path ) );
	}
}
