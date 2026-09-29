<?php
/**
 * Plugin Name:       SwiftMigrate
 * Plugin URI:        https://organicreach.agency/swiftmigrate/
 * Description:       Move a complete WordPress site (files and database) directly from one server to another, with checksummed parallel transfer, verification before restore and one-click rollback.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Hasan Alam Qazi
 * Author URI:        https://organicreach.agency/hasan-alam-qazi/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swiftmigrate
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

define( 'SWIFTMIGRATE_FILE', __FILE__ );
require_once plugin_dir_path( __FILE__ ) . 'includes/bootstrap.php';

register_activation_hook( __FILE__, array( 'SwiftMigrate_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SwiftMigrate_Plugin', 'deactivate' ) );

add_action( 'rest_api_init', array( 'SwiftMigrate_Api', 'register_routes' ) );
add_action( 'plugins_loaded', array( 'SwiftMigrate_Plugin', 'instance' ) );
