<?php
/**
 * Uninstall: removes everything SwiftMigrate created (settings, working data,
 * staging and rollback tables). The migrated site itself is left untouched.
 *
 * @package SwiftMigrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/bootstrap.php';

// Staging (smgt_*) and rollback (smgb_*) tables.
foreach ( array( SwiftMigrate_Receiver::TMP, SwiftMigrate_Receiver::BAK ) as $swiftmigrate_prefix ) {
	foreach ( SwiftMigrate_DB::tables_like( $swiftmigrate_prefix ) as $swiftmigrate_table ) {
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $swiftmigrate_table ) );
	}
}

// Private working folder: <uploads>/swiftmigrate (resolved with wp_upload_dir()).
$swiftmigrate_dirs = array( SwiftMigrate_Storage::existing_base() );
try {
	$swiftmigrate_dirs[] = SwiftMigrate_Storage::parent_dir();
} catch ( RuntimeException $swiftmigrate_e ) {
	unset( $swiftmigrate_e );
}
foreach ( array_filter( $swiftmigrate_dirs ) as $swiftmigrate_dir ) {
	// Only ever delete SwiftMigrate's own folder (<uploads>/swiftmigrate or a data folder inside it).
	$swiftmigrate_ours = SwiftMigrate_Storage::DIR_NAME === basename( $swiftmigrate_dir ) || SwiftMigrate_Storage::DIR_NAME === basename( dirname( $swiftmigrate_dir ) );
	if ( $swiftmigrate_ours && is_dir( $swiftmigrate_dir ) ) {
		SwiftMigrate_Storage::rrmdir_step( $swiftmigrate_dir, microtime( true ) + 60 );
	}
}

delete_option( 'swiftmigrate_settings' );
delete_option( 'swiftmigrate_post_restore' );
delete_option( SwiftMigrate_Storage::OPTION );
delete_transient( 'swiftmigrate_restored_notice' );
