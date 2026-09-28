<?php
defined( 'ABSPATH' ) || exit;

class SwiftMigrate_Settings {

	const OPTION = 'swiftmigrate_settings';

	public static function defaults() {
		return array(
			'chunk_mb'          => 8,     // Max bytes per transfer request (auto-capped by destination limits).
			'workers'           => 4,     // Parallel transfer streams.
			'compress'          => 1,     // Gzip compressible payloads (level 1: fast).
			'db_rows'           => 2000,  // Rows per database chunk.
			'batch_files'       => 1500,  // Max small files packed into one request.
			'skip_transients'   => 1,
			'skip_spam'         => 1,
			'skip_revisions'    => 0,
			'keep_backup'       => 1,     // Keep destination's replaced tables and files for rollback.
			'sslverify'         => 1,
			'firewall_safe'     => 0,     // Base64 bodies for WAFs that block binary uploads.
			'request_timeout'   => 120,
			'time_budget'       => 20,    // Seconds of work per server request.
			'key_hours'         => 24,
			'excludes'          => implode( "\n", array(
				'content:cache',
				'content:upgrade',
				'content:upgrade-temp-backup',
				'content:wflogs',
				'content:ai1wm-backups',
				'content:updraft',
				'content:backups-dup-lite',
				'content:backups-dup-pro',
				'content:wpvividbackups',
				'content:backup-db',
				'content:et-cache',
				'content:litespeed',
				'content:uploads/wp-migrate-db',
				'*.log',
				'*/node_modules',
				'*/.git',
				'.DS_Store',
				'error_log',
			) ),
			'skip_tables'       => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $def ) {
			if ( is_int( $def ) ) {
				$out[ $k ] = isset( $in[ $k ] ) ? (int) $in[ $k ] : ( in_array( $k, array( 'chunk_mb', 'workers', 'db_rows', 'batch_files', 'request_timeout', 'time_budget', 'key_hours' ), true ) ? $def : 0 );
			} else {
				$out[ $k ] = isset( $in[ $k ] ) ? sanitize_textarea_field( (string) $in[ $k ] ) : $def;
			}
		}
		$out['chunk_mb']        = min( 64, max( 1, $out['chunk_mb'] ) );
		$out['workers']         = min( 16, max( 1, $out['workers'] ) );
		$out['db_rows']         = min( 50000, max( 100, $out['db_rows'] ) );
		$out['batch_files']     = min( 10000, max( 50, $out['batch_files'] ) );
		$out['request_timeout'] = min( 600, max( 30, $out['request_timeout'] ) );
		$out['time_budget']     = min( 120, max( 5, $out['time_budget'] ) );
		$out['key_hours']       = min( 168, max( 1, $out['key_hours'] ) );
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/** Seconds of work a single PHP request may do on this server. */
	public static function budget() {
		$budget = (int) self::get( 'time_budget' );
		$max    = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$budget = min( $budget, max( 3, $max - 8 ) );
		}
		return max( 3, $budget );
	}

	public static function excludes() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'excludes' ) );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}
}
