<?php
/**
 * Uninstall: removes everything SwiftMigrate created (settings, working data,
 * staging and rollback tables). The migrated site itself is left untouched.
 *
 * @package SwiftMigrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/bootstrap.php';

delete_option( 'swiftmigrate_settings' );
delete_transient( 'swiftmigrate_restored_notice' );

// Staging (smgt_*) and rollback (smgb_*) tables.
foreach ( array( SwiftMigrate_Receiver::TMP, SwiftMigrate_Receiver::BAK ) as $swiftmigrate_prefix ) {
	foreach ( SwiftMigrate_DB::tables_like( $swiftmigrate_prefix ) as $swiftmigrate_table ) {
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $swiftmigrate_table ) );
	}
}

// Private working folder.
$swiftmigrate_dir = trailingslashit( WP_CONTENT_DIR ) . SwiftMigrate_Storage::DIR_NAME;
if ( is_dir( $swiftmigrate_dir ) ) {
	SwiftMigrate_Storage::rrmdir_step( $swiftmigrate_dir, microtime( true ) + 60 );
}
