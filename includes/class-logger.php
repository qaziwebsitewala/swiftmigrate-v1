<?php
/**
 * Per-migration log files and the history index.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migration logger.
 */
class SwiftMigrate_Logger {

	public static function file( $id ) {
		return SwiftMigrate_Storage::migration_dir( $id ) . '/migration.log';
	}

	public static function log( $id, $message, $level = 'info' ) {
		if ( ! $id ) {
			return;
		}
		// Exception messages are HTML-escaped when thrown; the log is plain text.
		$message = html_entity_decode( (string) $message, ENT_QUOTES, 'UTF-8' );
		$line    = sprintf( "[%s] %-5s %s\n", gmdate( 'Y-m-d H:i:s' ), strtoupper( $level ), $message );
		file_put_contents( self::file( $id ), $line, FILE_APPEND | LOCK_EX );
	}

	public static function tail( $id, $lines = 60 ) {
		$file = self::file( $id );
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$size = filesize( $file );
		$fh = SwiftMigrate_Fs::open( $file, 'rb' );
		if ( ! $fh ) {
			return array();
		}
		$read = (int) min( $size, 64 * KB_IN_BYTES );
		fseek( $fh, -$read, SEEK_END );
		$data = (string) SwiftMigrate_Fs::read( $fh, $read );
		SwiftMigrate_Fs::close( $fh );
		$all = preg_split( '/\n/', trim( $data ) );
		return array_slice( $all, -$lines );
	}

	/** History index shown on the Logs page (source and destination both record). */
	public static function history_add( array $entry ) {
		SwiftMigrate_Storage::update_json(
			SwiftMigrate_Storage::path( 'history.json' ),
			function ( $h ) use ( $entry ) {
				$h = is_array( $h ) ? $h : array();
				foreach ( $h as $i => $row ) {
					if ( $row['id'] === $entry['id'] && $row['role'] === $entry['role'] ) {
						$h[ $i ] = array_merge( $row, $entry );
						return $h;
					}
				}
				array_unshift( $h, $entry );
				return array_slice( $h, 0, 50 );
			}
		);
	}

	public static function history() {
		return SwiftMigrate_Storage::read_json( SwiftMigrate_Storage::path( 'history.json' ), array() );
	}
}
