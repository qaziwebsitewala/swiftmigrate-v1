<?php
/**
 * Transport-level failure of one endpoint URL.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Raised when an endpoint URL could not be used at all (network error, HTML
 * page, missing route). The client then tries the next equivalent URL.
 */
class SwiftMigrate_Transport_Exception extends RuntimeException {}
