<?php
/**
 * Settings screen.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_s = SwiftMigrate_Settings::all();

/** Small helpers for consistent rows. */
$swiftmigrate_num = function ( $key, $label, $help, $min, $max, $unit = '' ) use ( $swiftmigrate_s ) {
	printf(
		'<div class="smg-field"><label for="smg-%1$s">%2$s</label><div class="smg-field__ctl"><input type="number" id="smg-%1$s" name="swiftmigrate[%1$s]" value="%3$d" min="%4$d" max="%5$d" class="small-text"> <span class="smg-muted">%6$s</span></div><p class="smg-help">%7$s</p></div>',
		esc_attr( $key ),
		esc_html( $label ),
		(int) $swiftmigrate_s[ $key ],
		(int) $min,
		(int) $max,
		esc_html( $unit ),
		esc_html( $help )
	);
};
$swiftmigrate_chk = function ( $key, $label, $help ) use ( $swiftmigrate_s ) {
	printf(
		'<div class="smg-field smg-field--check"><label><input type="checkbox" name="swiftmigrate[%1$s]" value="1" %2$s> <strong>%3$s</strong></label><p class="smg-help">%4$s</p></div>',
		esc_attr( $key ),
		checked( ! empty( $swiftmigrate_s[ $key ] ), true, false ),
		esc_html( $label ),
		esc_html( $help )
	);
};
?>
<h1><?php esc_html_e( 'Settings', 'swiftmigrate' ); ?></h1>
<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect. ?>
	<div class="smg-alert smg-alert--ok"><p><?php esc_html_e( 'Settings saved.', 'swiftmigrate' ); ?></p></div>
<?php endif; ?>
<p class="smg-lede"><?php esc_html_e( 'The defaults suit most hosts. Transfer settings are read on the source site; restore and security settings on the destination.', 'swiftmigrate' ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smg-settings">
	<input type="hidden" name="action" value="swiftmigrate_save_settings">
	<?php wp_nonce_field( 'swiftmigrate_settings' ); ?>

	<section class="smg-panel">
		<h2><?php esc_html_e( 'Speed', 'swiftmigrate' ); ?></h2>
		<?php
		$swiftmigrate_num( 'workers', __( 'Parallel streams', 'swiftmigrate' ), __( 'How many transfers run at once. 4 is safe on shared hosting; 8–12 is faster on VPS and dedicated servers.', 'swiftmigrate' ), 1, 16 );
		$swiftmigrate_num( 'chunk_mb', __( 'Maximum request size', 'swiftmigrate' ), __( 'Larger chunks mean fewer requests. Automatically lowered to fit the destination\'s upload and memory limits.', 'swiftmigrate' ), 1, 64, 'MB' );
		$swiftmigrate_num( 'batch_files', __( 'Small files per request', 'swiftmigrate' ), __( 'Small files are packed together, so thousands of thumbnails travel in a handful of requests.', 'swiftmigrate' ), 50, 10000 );
		$swiftmigrate_num( 'db_rows', __( 'Database rows per chunk', 'swiftmigrate' ), __( 'Lower this if a table with very large rows (page builders, logs) times out.', 'swiftmigrate' ), 100, 50000 );
		$swiftmigrate_num( 'time_budget', __( 'Work per server request', 'swiftmigrate' ), __( 'Each request stops safely before this limit and continues in the next one. Kept below max_execution_time automatically.', 'swiftmigrate' ), 5, 120, __( 'seconds', 'swiftmigrate' ) );
		$swiftmigrate_chk( 'compress', __( 'Compress transfers', 'swiftmigrate' ), __( 'Fast gzip for text, SQL and code. Already-compressed files (images, video, zip) are sent as they are.', 'swiftmigrate' ) );
		?>
	</section>

	<section class="smg-panel">
		<h2><?php esc_html_e( 'What is left behind', 'swiftmigrate' ); ?></h2>
		<?php
		$swiftmigrate_chk( 'skip_transients', __( 'Skip transients', 'swiftmigrate' ), __( 'Temporary cached values; WordPress rebuilds them.', 'swiftmigrate' ) );
		$swiftmigrate_chk( 'skip_spam', __( 'Skip spam and trashed comments', 'swiftmigrate' ), '' );
		$swiftmigrate_chk( 'skip_revisions', __( 'Skip post revisions', 'swiftmigrate' ), __( 'Can shrink large editorial databases considerably.', 'swiftmigrate' ) );
		?>
		<div class="smg-field">
			<label for="smg-skip_tables"><?php esc_html_e( 'Skip database tables', 'swiftmigrate' ); ?></label>
			<textarea id="smg-skip_tables" name="swiftmigrate[skip_tables]" rows="3" class="large-text code" placeholder="wc_admin_notes&#10;actionscheduler_logs"><?php echo esc_textarea( $swiftmigrate_s['skip_tables'] ); ?></textarea>
			<p class="smg-help"><?php esc_html_e( 'One table per line, with or without the table prefix.', 'swiftmigrate' ); ?></p>
		</div>
		<div class="smg-field">
			<label for="smg-excludes"><?php esc_html_e( 'Excluded files and folders', 'swiftmigrate' ); ?></label>
			<textarea id="smg-excludes" name="swiftmigrate[excludes]" rows="10" class="large-text code"><?php echo esc_textarea( $swiftmigrate_s['excludes'] ); ?></textarea>
			<p class="smg-help"><?php esc_html_e( 'One rule per line. content:path is relative to wp-content, abs:path to the WordPress root; anything else is a wildcard pattern matched against names and paths (e.g. *.log, */node_modules).', 'swiftmigrate' ); ?></p>
		</div>
	</section>

	<section class="smg-panel">
		<h2><?php esc_html_e( 'Safety and connection', 'swiftmigrate' ); ?></h2>
		<?php
		$swiftmigrate_chk( 'keep_backup', __( 'Keep a rollback copy on the destination', 'swiftmigrate' ), __( 'The destination\'s previous database and replaced files are kept until you delete them, so a restore can be undone. Needs extra disk space.', 'swiftmigrate' ) );
		$swiftmigrate_chk( 'sslverify', __( 'Verify SSL certificates', 'swiftmigrate' ), __( 'Turn off only for a destination with a self-signed or not-yet-issued certificate.', 'swiftmigrate' ) );
		$swiftmigrate_chk( 'firewall_safe', __( 'Firewall-friendly mode', 'swiftmigrate' ), __( 'Encodes data as text. About 35% slower; use it when a security plugin or host firewall blocks the transfer (HTTP 403 or 406).', 'swiftmigrate' ) );
		$swiftmigrate_num( 'request_timeout', __( 'Request timeout', 'swiftmigrate' ), __( 'How long the source waits for the destination before retrying.', 'swiftmigrate' ), 30, 600, __( 'seconds', 'swiftmigrate' ) );
		$swiftmigrate_num( 'key_hours', __( 'Migration key lifetime', 'swiftmigrate' ), __( 'Keys stop working after this time. A restored site keeps its key 48 more hours so rollback works.', 'swiftmigrate' ), 1, 168, __( 'hours', 'swiftmigrate' ) );
		?>
	</section>

	<div class="smg-actions">
		<button type="submit" class="smg-btn smg-btn--primary"><?php esc_html_e( 'Save settings', 'swiftmigrate' ); ?></button>
		<button type="submit" name="reset" value="1" class="smg-btn smg-btn--quiet" data-confirm="<?php esc_attr_e( 'Reset all settings to their defaults?', 'swiftmigrate' ); ?>"><?php esc_html_e( 'Restore defaults', 'swiftmigrate' ); ?></button>
	</div>
</form>
