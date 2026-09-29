<?php
/**
 * Receive a site screen.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_recv   = SwiftMigrate_Security::get_receive();
$swiftmigrate_active = ! empty( $swiftmigrate_recv['enabled'] ) && ! empty( $swiftmigrate_recv['secret'] ) && (int) $swiftmigrate_recv['expires'] > time();
$swiftmigrate_key    = $swiftmigrate_active ? SwiftMigrate_Security::encode_key( $swiftmigrate_recv['secret'] ) : '';
$swiftmigrate_exp    = $swiftmigrate_active ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $swiftmigrate_recv['expires'] ) : '';
?>
<div id="smg-receive" class="smg-flow" data-active="<?php echo $swiftmigrate_active ? '1' : '0'; ?>">
	<h1><?php esc_html_e( 'Receive a site', 'swiftmigrate' ); ?></h1>
	<p class="smg-lede"><?php esc_html_e( 'Use this on the new (destination) site. Generate a key, then paste it into SwiftMigrate → Send this site on the site you are moving.', 'swiftmigrate' ); ?></p>

	<div class="smg-alert smg-alert--warn">
		<p><strong><?php esc_html_e( 'This site will be replaced.', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Its database, themes, plugins and media are swapped for the incoming site when the migration is restored. wp-config.php and .htaccess are kept. With "Keep a rollback copy" on (Settings), you can undo the switch.', 'swiftmigrate' ); ?></p>
	</div>

	<section class="smg-panel smg-keybox" data-bind="keybox">
		<div data-when="inactive" <?php echo $swiftmigrate_active ? 'hidden' : ''; ?>>
			<h2><?php esc_html_e( 'Migration key', 'swiftmigrate' ); ?></h2>
			<p class="smg-muted"><?php esc_html_e( 'The key contains this site\'s address and a one-off secret. Every request from the source is signed with it; nothing else can write here.', 'swiftmigrate' ); ?></p>
			<div class="smg-actions">
				<button type="button" class="smg-btn smg-btn--primary" data-act="generate"><?php esc_html_e( 'Generate migration key', 'swiftmigrate' ); ?></button>
			</div>
		</div>
		<div data-when="active" <?php echo $swiftmigrate_active ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Migration key', 'swiftmigrate' ); ?></h2>
			<label class="screen-reader-text" for="smg-recv-key"><?php esc_html_e( 'Migration key', 'swiftmigrate' ); ?></label>
			<textarea id="smg-recv-key" class="smg-key" rows="3" readonly spellcheck="false"><?php echo esc_textarea( $swiftmigrate_key ); ?></textarea>
			<div class="smg-actions">
				<button type="button" class="smg-btn smg-btn--primary" data-act="copy"><?php esc_html_e( 'Copy key', 'swiftmigrate' ); ?></button>
				<button type="button" class="smg-btn" data-act="generate"><?php esc_html_e( 'New key', 'swiftmigrate' ); ?></button>
				<button type="button" class="smg-btn smg-btn--danger-quiet" data-act="revoke"><?php esc_html_e( 'Revoke', 'swiftmigrate' ); ?></button>
				<span class="smg-inline-status" data-bind="keystatus" role="status" aria-live="polite"></span>
			</div>
			<p class="smg-muted"><?php esc_html_e( 'Valid until', 'swiftmigrate' ); ?> <strong data-bind="expires"><?php echo esc_html( $swiftmigrate_exp ); ?></strong>. <?php esc_html_e( 'Generating a new key invalidates this one.', 'swiftmigrate' ); ?></p>
		</div>
	</section>

	<section class="smg-panel smg-incoming" data-bind="incoming" hidden>
		<h2><?php esc_html_e( 'Incoming migration', 'swiftmigrate' ); ?></h2>
		<div class="smg-route">
			<div class="smg-node"><span class="smg-node__label"><?php esc_html_e( 'From', 'swiftmigrate' ); ?></span><strong data-bind="from"></strong></div>
			<div class="smg-track" aria-hidden="true"><span data-bind="track"></span></div>
			<div class="smg-node smg-node--dest"><span class="smg-node__label"><?php esc_html_e( 'To', 'swiftmigrate' ); ?></span><strong><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></strong></div>
		</div>
		<div class="smg-progress">
			<div class="smg-progress__head">
				<strong data-bind="label"></strong>
				<span class="smg-num" data-bind="pct"></span>
			</div>
			<div class="smg-bar"><span data-bind="bar"></span></div>
		</div>
		<p class="smg-muted" data-bind="hint"></p>
		<div class="smg-actions" data-bind="after" hidden>
			<button type="button" class="smg-btn" data-act="purge"><?php esc_html_e( 'Delete rollback copy', 'swiftmigrate' ); ?></button>
			<button type="button" class="smg-btn smg-btn--danger-quiet" data-act="rollback"><?php esc_html_e( 'Roll back this site', 'swiftmigrate' ); ?></button>
			<span class="smg-inline-status" data-bind="afterstatus" role="status" aria-live="polite"></span>
		</div>
		<details class="smg-log">
			<summary><?php esc_html_e( 'Activity log', 'swiftmigrate' ); ?></summary>
			<pre data-bind="log"></pre>
		</details>
	</section>
	<div class="smg-alert smg-alert--error" data-bind="error" hidden></div>
</div>
