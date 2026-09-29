<?php
/**
 * Admin controller: menu pages, assets and the AJAX endpoints used by the
 * browser orchestrator (assets/js/admin.js).
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and AJAX.
 */
class SwiftMigrate_Plugin {

	const CAP        = 'manage_options';
	const MENU_SLUG  = 'swiftmigrate';
	const AJAX_NONCE = 'swiftmigrate_ajax';

	/**
	 * Singleton.
	 *
	 * @var SwiftMigrate_Plugin|null
	 */
	private static $instance;

	/**
	 * Admin page hook suffixes.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Returns the instance.
	 *
	 * @return SwiftMigrate_Plugin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_swiftmigrate_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_swiftmigrate_download_log', array( $this, 'download_log' ) );
		add_action( 'init', array( $this, 'post_restore' ), 99 );
		add_filter( 'plugin_action_links_' . plugin_basename( SWIFTMIGRATE_FILE ), array( $this, 'action_links' ) );

		foreach ( self::ajax_actions() as $a ) {
			add_action( 'wp_ajax_swiftmigrate_' . $a, array( $this, 'ajax' ) );
		}
	}

	/**
	 * AJAX actions (all admin-only).
	 *
	 * @return string[]
	 */
	private static function ajax_actions() {
		return array(
			'connect',
			'start',
			'scan',
			'job',
			'checkpoint',
			'phase',
			'verify',
			'remote',
			'cancel',
			'status',
			'key_generate',
			'key_revoke',
			'recv_status',
			'recv_rollback',
			'recv_purge',
		);
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		try {
			SwiftMigrate_Storage::base();
		} catch ( RuntimeException $e ) {
			// Created on first use instead; the Overview screen reports the problem.
			unset( $e );
		}
		if ( false === get_option( SwiftMigrate_Settings::OPTION ) ) {
			update_option( SwiftMigrate_Settings::OPTION, SwiftMigrate_Settings::defaults(), false );
		}
	}

	/**
	 * Deactivation: no open door stays behind when the plugin is switched off.
	 */
	public static function deactivate() {
		SwiftMigrate_Security::revoke();
	}

	/**
	 * Adds an "Open" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Open', 'swiftmigrate' ) . '</a>' );
		return $links;
	}

	/**
	 * First page load after a restore on this (destination) site.
	 */
	public function post_restore() {
		// Autoloaded option set by the restore on the new database: no extra query on normal page loads.
		if ( ! get_option( 'swiftmigrate_post_restore' ) ) {
			return;
		}
		delete_option( 'swiftmigrate_post_restore' );
		flush_rewrite_rules( false );
		wp_cache_flush();
		set_transient( 'swiftmigrate_restored_notice', 1, DAY_IN_SECONDS );
	}

	// ---------------------------------------------------------------- menu.

	/**
	 * Admin pages.
	 *
	 * @return array<string, array{0: string, 1: string}> slug => (title, view).
	 */
	public static function pages() {
		return array(
			self::MENU_SLUG              => array( __( 'Overview', 'swiftmigrate' ), 'dashboard' ),
			self::MENU_SLUG . '-send'     => array( __( 'Send this site', 'swiftmigrate' ), 'send' ),
			self::MENU_SLUG . '-receive'  => array( __( 'Receive a site', 'swiftmigrate' ), 'receive' ),
			self::MENU_SLUG . '-settings' => array( __( 'Settings', 'swiftmigrate' ), 'settings' ),
			self::MENU_SLUG . '-logs'     => array( __( 'History & logs', 'swiftmigrate' ), 'logs' ),
		);
	}

	/**
	 * Registers the menu.
	 */
	public function menu() {
		$this->hooks[] = add_menu_page( 'SwiftMigrate', 'SwiftMigrate', self::CAP, self::MENU_SLUG, array( $this, 'page' ), 'dashicons-migrate', 76 );
		foreach ( self::pages() as $slug => $page ) {
			$this->hooks[] = add_submenu_page( self::MENU_SLUG, $page[0], $page[0], self::CAP, $slug, array( $this, 'page' ) );
		}
	}

	/**
	 * Slug of the admin page being viewed.
	 *
	 * @return string
	 */
	public static function current_page() {
		$pages = self::pages();
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		return isset( $pages[ $page ] ) ? $page : self::MENU_SLUG;
	}

	/**
	 * Renders a page.
	 */
	public function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to use SwiftMigrate.', 'swiftmigrate' ) );
		}
		$pages = self::pages();
		$view  = $pages[ self::current_page() ][1];
		echo '<div class="wrap smg">';
		include SWIFTMIGRATE_DIR . 'admin/views/header.php';
		if ( is_multisite() ) {
			echo '<div class="smg-alert smg-alert--error"><p>' . esc_html__( 'SwiftMigrate supports single-site installs only. Multisite networks cannot be migrated with this plugin.', 'swiftmigrate' ) . '</p></div>';
		}
		include SWIFTMIGRATE_DIR . 'admin/views/' . $view . '.php';
		echo '</div>';
	}

	/**
	 * Enqueues assets on our pages only.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'swiftmigrate-admin', SWIFTMIGRATE_URL . 'assets/css/admin.css', array(), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'swiftmigrate-admin', SWIFTMIGRATE_URL . 'assets/js/admin.js', array( 'wp-i18n' ), self::asset_version( 'assets/js/admin.js' ), true );
		wp_set_script_translations( 'swiftmigrate-admin', 'swiftmigrate' );
		wp_localize_script(
			'swiftmigrate-admin',
			'swiftmigrateAdmin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'workers' => (int) SwiftMigrate_Settings::get( 'workers' ),
				'backup'  => (bool) SwiftMigrate_Settings::get( 'keep_backup' ),
				'page'    => SwiftMigrate_Source::PAGE,
				'home'    => home_url(),
				'sendUrl' => admin_url( 'admin.php?page=' . self::MENU_SLUG . '-send' ),
			)
		);
	}

	/**
	 * Asset version: plugin version plus file time, so browsers never run a
	 * cached script from an older build of the same version.
	 *
	 * @param string $rel Path relative to the plugin folder.
	 * @return string
	 */
	private static function asset_version( $rel ) {
		$file = SWIFTMIGRATE_DIR . $rel;
		return SWIFTMIGRATE_VERSION . ( is_file( $file ) ? '.' . filemtime( $file ) : '' );
	}

	// ---------------------------------------------------------------- settings / logs.

	/**
	 * Saves the settings form.
	 */
	public function save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'swiftmigrate' ), 403 );
		}
		check_admin_referer( 'swiftmigrate_settings' );
		if ( isset( $_POST['reset'] ) ) {
			update_option( SwiftMigrate_Settings::OPTION, SwiftMigrate_Settings::defaults(), false );
		} else {
			$in = array();
			if ( isset( $_POST['swiftmigrate'] ) && is_array( $_POST['swiftmigrate'] ) ) {
				// Each value is sanitized by type in SwiftMigrate_Settings::save().
				$in = map_deep( wp_unslash( $_POST['swiftmigrate'] ), 'sanitize_textarea_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by map_deep( ..., 'sanitize_textarea_field' ).
			}
			SwiftMigrate_Settings::save( $in );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings&saved=1' ) );
		exit;
	}

	/**
	 * Streams a migration log as a download.
	 */
	public function download_log() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'swiftmigrate' ), 403 );
		}
		check_admin_referer( 'swiftmigrate_log' );
		$id   = SwiftMigrate_Storage::sanitize_id( isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '' );
		$file = $id ? SwiftMigrate_Logger::file( $id ) : '';
		if ( ! $id || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Log not found.', 'swiftmigrate' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="swiftmigrate-' . $id . '.log"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( (string) SwiftMigrate_Fs::get( $file ) );
		exit;
	}

	// ---------------------------------------------------------------- ajax.

	/**
	 * Single entry point for all AJAX actions.
	 */
	public function ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your session has ended or you lack permission. If this site was just replaced by a migration, log in again with the migrated site\'s credentials.', 'swiftmigrate' ),
					'code'    => 'auth',
				),
				403
			);
		}
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security token expired. Reload the page.', 'swiftmigrate' ),
					'code'    => 'nonce',
				),
				403
			);
		}
		// Migration steps are long-running by nature; these limits apply to this admin request only.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped to this migration request only.
		}
		wp_raise_memory_limit( 'admin' );

		$action = substr( current_action(), strlen( 'wp_ajax_swiftmigrate_' ) );
		try {
			wp_send_json_success( $this->dispatch( $action ) );
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'code'    => (int) $e->getCode(),
				),
				200
			);
		}
	}

	/**
	 * Reads one POSTed scalar, unslashed and sanitized as plain text.
	 * The nonce was verified in ajax() before dispatch() runs.
	 *
	 * @param string $key     Field name.
	 * @param string $default Default value.
	 * @return string
	 */
	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer() in ajax().
		if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer() in ajax().
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Reads a POSTed JSON field (sent by admin.js) and decodes it.
	 *
	 * @param string $key Field name.
	 * @return mixed|null
	 */
	private static function post_json( $key ) {
		$raw = self::post( $key );
		return '' === $raw ? null : json_decode( $raw, true );
	}

	/**
	 * Find/replace pairs from the Send screen. Kept as typed (only unslashed and
	 * stripped of control characters): they are search strings for the database,
	 * never printed back unescaped.
	 *
	 * @return array[]
	 */
	private static function post_pairs() {
		$pairs = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer() in ajax().
		if ( empty( $_POST['pairs'] ) || ! is_array( $_POST['pairs'] ) ) {
			return $pairs;
		}
		// Nonce verified by check_ajax_referer() in ajax(); each value is sanitized by clean_pair_value().
		$rows = map_deep( wp_unslash( $_POST['pairs'] ), array( __CLASS__, 'clean_pair_value' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && isset( $row[0], $row[1] ) && '' !== trim( (string) $row[0] ) ) {
				$pairs[] = array( (string) $row[0], (string) $row[1] );
			}
		}
		return $pairs;
	}

	/**
	 * Sanitizes one find/replace value: removes control characters and invalid UTF-8.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function clean_pair_value( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
	}

	/**
	 * Routes an AJAX action.
	 *
	 * @param string $action Action name.
	 * @return array
	 * @throws RuntimeException On invalid input.
	 */
	private function dispatch( $action ) {
		switch ( $action ) {
			// Source.
			case 'connect':
				// The key is base64url text; strip anything else (whitespace from copy/paste).
				return SwiftMigrate_Source::connect( preg_replace( '/[^A-Za-z0-9_\-]/', '', self::post( 'key' ) ) );
			case 'start':
				$opts = array( 'pairs' => self::post_pairs() );
				foreach ( array( 'db', 'uploads', 'themes', 'plugins', 'core', 'other', 'autorestore' ) as $k ) {
					$opts[ $k ] = in_array( self::post( $k ), array( '1', 'true', 'on' ), true );
				}
				return SwiftMigrate_Source::start( $opts );
			case 'scan':
				return SwiftMigrate_Source::load()->scan_step();
			case 'job':
				$cursor = self::post_json( 'cursor' );
				return SwiftMigrate_Source::load()->run_job( absint( self::post( 'n', '0' ) ), is_array( $cursor ) ? $cursor : null );
			case 'checkpoint':
				SwiftMigrate_Source::load()->checkpoint(
					preg_replace( '/[^A-Za-z0-9+\/=]/', '', self::post( 'bitmap' ) ),
					(float) self::post( 'bytes', '0' ),
					absint( self::post( 'files', '0' ) )
				);
				return array();
			case 'phase':
				$src = SwiftMigrate_Source::load();
				$ph  = sanitize_key( self::post( 'phase' ) );
				if ( ! in_array( $ph, array( 'transfer', 'verify', 'ready', 'restore' ), true ) ) {
					throw new RuntimeException( esc_html__( 'Invalid phase.', 'swiftmigrate' ) );
				}
				$src->set_phase( $ph );
				return $src->status();
			case 'verify':
				$src = SwiftMigrate_Source::load();
				if ( '' !== self::post( 'page' ) ) {
					return array( 'bad' => $src->verify_page( absint( self::post( 'page' ) ) ) );
				}
				if ( '' !== self::post( 'pass' ) ) {
					return array( 'pass' => $src->verify_pass() );
				}
				$ids = self::post_json( 'ids' );
				$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
				return array( 'bad' => $ids ? $src->verify( $ids ) : array() );
			case 'remote':
				$what = sanitize_key( self::post( 'what' ) );
				if ( ! in_array( $what, array( 'restore', 'rollback', 'purge', 'status' ), true ) ) {
					throw new RuntimeException( esc_html__( 'Invalid remote action.', 'swiftmigrate' ) );
				}
				return SwiftMigrate_Source::load()->remote( $what );
			case 'cancel':
				SwiftMigrate_Source::load()->cancel();
				return array();
			case 'status':
				$id = SwiftMigrate_Source::current_id();
				if ( ! $id ) {
					return array( 'phase' => 'idle' );
				}
				$src          = SwiftMigrate_Source::load( $id );
				$st           = $src->status();
				$st['bitmap'] = in_array( $st['phase'], array( 'transfer', 'verify' ), true ) ? $src->bitmap() : '';
				return $st;

			// Destination.
			case 'key_generate':
				$key = SwiftMigrate_Security::generate_key( (int) SwiftMigrate_Settings::get( 'key_hours' ) );
				$r   = SwiftMigrate_Security::get_receive();
				return array(
					'key'     => $key,
					'expires' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['expires'] ),
				);
			case 'key_revoke':
				SwiftMigrate_Security::revoke();
				return array();
			case 'recv_status':
				return ( new SwiftMigrate_Receiver() )->status( array() );
			case 'recv_rollback':
				return ( new SwiftMigrate_Receiver() )->rollback( array() );
			case 'recv_purge':
				return ( new SwiftMigrate_Receiver() )->purge( array() );
		}
		throw new RuntimeException( esc_html__( 'Unknown action.', 'swiftmigrate' ) );
	}

	// ---------------------------------------------------------------- environment report.

	/**
	 * Server facts for the Overview screen.
	 *
	 * @return array[]
	 */
	public static function environment() {
		global $wpdb, $wp_version;
		$mem      = (string) ini_get( 'memory_limit' );
		$exec     = (int) ini_get( 'max_execution_time' );
		$post     = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$content  = SwiftMigrate_Paths::content_dir();
		$free     = SwiftMigrate_Storage::free_space( $content );
		$writable = wp_is_writable( $content );
		try {
			$storage = wp_is_writable( SwiftMigrate_Storage::base() );
		} catch ( RuntimeException $e ) {
			$storage = false;
		}
		return array(
			array( __( 'WordPress', 'swiftmigrate' ), $wp_version, 'ok' ),
			array( __( 'PHP', 'swiftmigrate' ), PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'error' ),
			array( __( 'Database server', 'swiftmigrate' ), $wpdb->db_server_info(), 'ok' ),
			array( __( 'Memory limit', 'swiftmigrate' ), $mem, ( wp_convert_hr_to_bytes( $mem ) >= 128 * MB_IN_BYTES || '-1' === $mem ) ? 'ok' : 'warn' ),
			array( __( 'Max execution time', 'swiftmigrate' ), $exec ? $exec . 's' : __( 'unlimited', 'swiftmigrate' ), 'ok' ),
			array( __( 'Max request size (post_max_size)', 'swiftmigrate' ), size_format( $post ), $post >= 8 * MB_IN_BYTES ? 'ok' : 'warn' ),
			array( __( 'Compression (zlib)', 'swiftmigrate' ), function_exists( 'gzencode' ) ? __( 'available', 'swiftmigrate' ) : __( 'missing', 'swiftmigrate' ), function_exists( 'gzencode' ) ? 'ok' : 'warn' ),
			array( __( 'Content folder writable', 'swiftmigrate' ), $writable ? __( 'yes', 'swiftmigrate' ) : __( 'no', 'swiftmigrate' ), $writable ? 'ok' : 'error' ),
			array( __( 'Working folder (uploads/swiftmigrate) writable', 'swiftmigrate' ), $storage ? __( 'yes', 'swiftmigrate' ) : __( 'no', 'swiftmigrate' ), $storage ? 'ok' : 'error' ),
			array( __( 'Encryption for non-HTTPS transfers', 'swiftmigrate' ), SwiftMigrate_Security::can_encrypt() ? __( 'available', 'swiftmigrate' ) : __( 'missing', 'swiftmigrate' ), SwiftMigrate_Security::can_encrypt() ? 'ok' : 'warn' ),
			array( __( 'Free disk space', 'swiftmigrate' ), null === $free ? __( 'unknown', 'swiftmigrate' ) : size_format( $free ), 'ok' ),
			array( __( 'Database size', 'swiftmigrate' ), size_format( SwiftMigrate_Source::db_size() ), 'ok' ),
		);
	}
}
