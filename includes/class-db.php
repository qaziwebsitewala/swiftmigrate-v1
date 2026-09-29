<?php
/**
 * Database helpers.
 *
 * All statements go through $wpdb. Two kinds of SQL pass through here:
 *
 * 1. Statements built by this plugin: identifiers are quoted with ident()
 *    (or %i placeholders) and values with $wpdb->prepare().
 * 2. The SQL dump streamed from the source site during a migration. It is
 *    produced by SwiftMigrate_Source (INSERT/CREATE TABLE statements for the
 *    source's own tables), signed with the migration key's HMAC secret,
 *    checksummed, and executed only against temporary smgt_* staging tables.
 *    A dump cannot be passed through $wpdb->prepare(), in the same way that a
 *    mysqldump file cannot, so its string literals are escaped by esc() below.
 *    esc_sql() is not used for the dump because it inserts a site-specific
 *    placeholder for "%" that would corrupt the data on a different site.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over $wpdb with error handling via exceptions.
 */
class SwiftMigrate_DB {

	const EOS         = "\n/*SMG_EOS*/\n";
	const PLACEHOLDER = '{{SMG_PREFIX}}';

	/**
	 * Runs a statement through $wpdb. Throws on error.
	 *
	 * @param string $sql Prepared statement or verified dump statement (see class comment).
	 * @return int|bool
	 * @throws RuntimeException On database error.
	 */
	public static function q( $sql ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$res      = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- See class comment: prepared by the caller or a signed migration dump applied to staging tables.
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( false === $res ) {
			throw new RuntimeException( esc_html( $error ? $error : __( 'Query failed', 'swiftmigrate' ) ) );
		}
		return $res;
	}

	/**
	 * Returns associative rows.
	 *
	 * @param string $sql Prepared SELECT/SHOW statement.
	 * @return array[]
	 * @throws RuntimeException On database error.
	 */
	public static function rows( $sql ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the caller; migration data must never be served from cache.
		$error    = $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		if ( $error ) {
			throw new RuntimeException( esc_html( $error ) );
		}
		return (array) $rows;
	}

	/**
	 * Returns numerically indexed rows plus column metadata of the result.
	 *
	 * @param string $sql Prepared SELECT statement.
	 * @return array{0: array[], 1: object[]} Rows and column info objects (type, charsetnr, ...).
	 * @throws RuntimeException On database error.
	 */
	public static function rows_with_fields( $sql ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the caller; migration data must never be served from cache.
		$error    = $wpdb->last_error;
		$types    = $rows ? (array) $wpdb->get_col_info( 'type' ) : array();
		$charsets = $rows ? (array) $wpdb->get_col_info( 'charsetnr' ) : array();
		$wpdb->suppress_errors( $suppress );
		if ( $error ) {
			throw new RuntimeException( esc_html( $error ) );
		}
		return array( (array) $rows, $types, $charsets );
	}

	/**
	 * First column of every row.
	 *
	 * @param string $sql Prepared statement.
	 * @return array
	 */
	public static function col( $sql ) {
		$out = array();
		foreach ( self::rows( $sql ) as $r ) {
			$out[] = reset( $r );
		}
		return $out;
	}

	/**
	 * First column of the first row.
	 *
	 * @param string $sql Prepared statement.
	 * @return mixed|null
	 */
	public static function var_( $sql ) {
		$rows = self::rows( $sql );
		return $rows ? reset( $rows[0] ) : null;
	}

	/**
	 * Prepares a statement with $wpdb->prepare().
	 *
	 * @param string $sql  Statement with %i / %s / %d placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public static function prepare( $sql, ...$args ) {
		global $wpdb;
		return $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This IS the prepare() call; $sql contains placeholders only.
	}

	/**
	 * Binary-safe string escaping for the migration dump, independent of the
	 * connection (NO_BACKSLASH_ESCAPES is turned off for the import session).
	 * Equivalent to mysqli_real_escape_string() for the utf8/utf8mb4/latin1
	 * character sets WordPress uses.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	public static function esc( $s ) {
		return strtr(
			(string) $s,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Quotes an identifier (table or column name) with backticks.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Escapes LIKE wildcards.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	public static function like_escape( $s ) {
		global $wpdb;
		return $wpdb->esc_like( (string) $s );
	}

	/**
	 * Prepares an import session for speed and fidelity.
	 *
	 * @param string $charset Source connection charset.
	 */
	public static function import_session( $charset = '' ) {
		$charset = preg_replace( '/[^a-z0-9_]/i', '', (string) $charset );
		if ( $charset ) {
			try {
				self::q( 'SET NAMES ' . $charset );
			} catch ( Exception $e ) {
				self::q( 'SET NAMES utf8mb4' );
			}
		}
		self::q( 'SET SESSION foreign_key_checks = 0' );
		self::q( 'SET SESSION unique_checks = 0' );
		self::q( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		try {
			self::q( 'SET SESSION wait_timeout = 600' );
		} catch ( Exception $e ) {
			// Not permitted on some hosts; harmless.
			unset( $e );
		}
	}

	/**
	 * Base tables whose name starts with $prefix.
	 *
	 * @param string $prefix Table name prefix.
	 * @return string[]
	 */
	public static function tables_like( $prefix ) {
		$rows = self::rows( self::prepare( 'SHOW FULL TABLES LIKE %s', self::like_escape( $prefix ) . '%' ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$vals = array_values( $r );
			if ( isset( $vals[1] ) && 'BASE TABLE' !== strtoupper( $vals[1] ) ) {
				continue;
			}
			$out[] = $vals[0];
		}
		return $out;
	}

	/**
	 * Whether a database error message means an unknown charset or collation.
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_collation_error( $message ) {
		return (bool) preg_match( '/Unknown (collation|character set)/i', (string) $message );
	}
}
