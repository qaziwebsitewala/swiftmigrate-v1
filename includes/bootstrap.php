<?php
/**
 * Constants and class autoloader.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SWIFTMIGRATE_VERSION' ) ) {
	define( 'SWIFTMIGRATE_VERSION', '1.0.0' );
	define( 'SWIFTMIGRATE_PROTOCOL', 2 );
	define( 'SWIFTMIGRATE_FILE', dirname( __DIR__ ) . '/swiftmigrate.php' );
	define( 'SWIFTMIGRATE_DIR', plugin_dir_path( SWIFTMIGRATE_FILE ) );
	define( 'SWIFTMIGRATE_URL', plugin_dir_url( SWIFTMIGRATE_FILE ) );
	define( 'SWIFTMIGRATE_SLUG', basename( dirname( __DIR__ ) ) );

	spl_autoload_register(
		function ( $class_name ) {
			$prefix = 'SwiftMigrate_';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$file = SWIFTMIGRATE_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}
