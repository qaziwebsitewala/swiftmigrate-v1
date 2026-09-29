<?php
/**
 * Send this site screen.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_conn = SwiftMigrate_Storage::read_json( SwiftMigrate_Storage::path( 'send-connection.json' ) );
?>
<div id="smg-send" class="smg-flow" data-state="connect">

	<!-- 1. Connect -->
	<section class="smg-stage" data-stage="connect">
		<h1><?php esc_html_e( 'Send this site', 'swiftmigrate' ); ?></h1>
		<p class="smg-lede"><?php esc_html_e( 'On the new site, open SwiftMigrate → Receive a site, generate a migration key and paste it below.', 'swiftmigrate' ); ?></p>
		<label class="smg-label" for="smg-key"><?php esc_html_e( 'Migration key from the destination site', 'swiftmigrate' ); ?></label>
		<textarea id="smg-key" class="smg-key" rows="3" spellcheck="false" autocomplete="off" placeholder="SMG1-…"><?php echo isset( $swiftmigrate_conn['key'] ) ? esc_textarea( $swiftmigrate_conn['key'] ) : ''; ?></textarea>
		<div class="smg-actions">
			<button type="button" class="smg-btn smg-btn--primary" data-act="connect"><?php esc_html_e( 'Connect', 'swiftmigrate' ); ?></button>
			<span class="smg-inline-status" role="status" aria-live="polite"></span>
		</div>
	</section>

	<!-- 2. Pre-flight & options -->
	<section class="smg-stage" data-stage="preflight" hidden>
		<div class="smg-route smg-route--static">
			<div class="smg-node"><span class="smg-node__label"><?php esc_html_e( 'From', 'swiftmigrate' ); ?></span><strong data-bind="local"></strong></div>
			<div class="smg-track" aria-hidden="true"><span></span></div>
			<div class="smg-node smg-node--dest"><span class="smg-node__label"><?php esc_html_e( 'To', 'swiftmigrate' ); ?></span><strong data-bind="peer"></strong></div>
		</div>

		<h2><?php esc_html_e( 'Pre-flight check', 'swiftmigrate' ); ?></h2>
		<ul class="smg-checks" data-bind="checks"></ul>

		<h2><?php esc_html_e( 'What to move', 'swiftmigrate' ); ?></h2>
		<fieldset class="smg-include">
			<legend class="screen-reader-text"><?php esc_html_e( 'What to move', 'swiftmigrate' ); ?></legend>
			<label><input type="checkbox" name="db" checked> <span><strong><?php esc_html_e( 'Database', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Posts, pages, users, settings, WooCommerce orders…', 'swiftmigrate' ); ?></span></label>
			<label><input type="checkbox" name="uploads" checked> <span><strong><?php esc_html_e( 'Media uploads', 'swiftmigrate' ); ?></strong> wp-content/uploads</span></label>
			<label><input type="checkbox" name="themes" checked> <span><strong><?php esc_html_e( 'Themes', 'swiftmigrate' ); ?></strong> wp-content/themes</span></label>
			<label><input type="checkbox" name="plugins" checked> <span><strong><?php esc_html_e( 'Plugins', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Including must-use plugins', 'swiftmigrate' ); ?></span></label>
			<label><input type="checkbox" name="core" checked> <span><strong><?php esc_html_e( 'WordPress core files', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Keeps the exact same WordPress version', 'swiftmigrate' ); ?></span></label>
			<label><input type="checkbox" name="other" checked> <span><strong><?php esc_html_e( 'Other files in the site root', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'robots.txt, verification files, custom folders', 'swiftmigrate' ); ?></span></label>
		</fieldset>
		<p class="smg-note"><?php esc_html_e( 'Never transferred: wp-config.php, .htaccess, .user.ini and php.ini stay as they are on the destination, so its database credentials and server rules keep working.', 'swiftmigrate' ); ?></p>

		<details class="smg-more">
			<summary><?php esc_html_e( 'Extra find & replace', 'swiftmigrate' ); ?></summary>
			<p class="smg-muted"><?php esc_html_e( 'Site URL and server paths are replaced automatically (including serialized and JSON-encoded data). Add anything else here, e.g. an old CDN address or email domain.', 'swiftmigrate' ); ?></p>
			<div class="smg-pairs" data-bind="pairs"></div>
			<button type="button" class="smg-btn smg-btn--quiet" data-act="add-pair"><?php esc_html_e( 'Add a replacement', 'swiftmigrate' ); ?></button>
		</details>

		<label class="smg-toggle"><input type="checkbox" name="autorestore"> <span><?php esc_html_e( 'Restore automatically as soon as every chunk is verified', 'swiftmigrate' ); ?></span></label>

		<div class="smg-alert smg-alert--warn">
			<p><strong><?php esc_html_e( 'Everything currently on the destination will be replaced.', 'swiftmigrate' ); ?></strong> <span data-bind="backupnote"></span></p>
			<label class="smg-confirm"><input type="checkbox" name="confirm"> <?php esc_html_e( 'I understand the destination site will be overwritten', 'swiftmigrate' ); ?> (<span data-bind="peerhost"></span>)</label>
		</div>
		<div class="smg-actions">
			<button type="button" class="smg-btn smg-btn--primary" data-act="start" disabled><?php esc_html_e( 'Start migration', 'swiftmigrate' ); ?></button>
			<button type="button" class="smg-btn smg-btn--quiet" data-act="reconnect"><?php esc_html_e( 'Use a different key', 'swiftmigrate' ); ?></button>
		</div>
	</section>

	<!-- 3. Running -->
	<section class="smg-stage" data-stage="run" hidden>
		<div class="smg-route" data-bind="route">
			<div class="smg-node"><span class="smg-node__label"><?php esc_html_e( 'From', 'swiftmigrate' ); ?></span><strong><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></strong></div>
			<div class="smg-track" aria-hidden="true"><span data-bind="track"></span></div>
			<div class="smg-node smg-node--dest"><span class="smg-node__label"><?php esc_html_e( 'To', 'swiftmigrate' ); ?></span><strong data-bind="peer2"></strong></div>
		</div>

		<ol class="smg-stepper" data-bind="stepper">
			<li data-step="scan"><?php esc_html_e( 'Scan', 'swiftmigrate' ); ?></li>
			<li data-step="transfer"><?php esc_html_e( 'Transfer', 'swiftmigrate' ); ?></li>
			<li data-step="verify"><?php esc_html_e( 'Verify', 'swiftmigrate' ); ?></li>
			<li data-step="restore"><?php esc_html_e( 'Restore', 'swiftmigrate' ); ?></li>
		</ol>

		<div class="smg-progress">
			<div class="smg-progress__head">
				<strong data-bind="label"><?php esc_html_e( 'Preparing…', 'swiftmigrate' ); ?></strong>
				<span class="smg-num" data-bind="pct">0%</span>
			</div>
			<div class="smg-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-bind="bar"></span></div>
		</div>

		<dl class="smg-stats">
			<div><dt><?php esc_html_e( 'Transferred', 'swiftmigrate' ); ?></dt><dd class="smg-num" data-bind="bytes">—</dd></div>
			<div><dt><?php esc_html_e( 'Files', 'swiftmigrate' ); ?></dt><dd class="smg-num" data-bind="files">—</dd></div>
			<div><dt><?php esc_html_e( 'Speed', 'swiftmigrate' ); ?></dt><dd class="smg-num" data-bind="speed">—</dd></div>
			<div><dt><?php esc_html_e( 'Time left', 'swiftmigrate' ); ?></dt><dd class="smg-num" data-bind="eta">—</dd></div>
		</dl>

		<div class="smg-alert smg-alert--warn" data-bind="warning" hidden></div>
		<div class="smg-alert smg-alert--error" data-bind="error" hidden></div>

		<div class="smg-ready" data-bind="ready" hidden>
			<h2><?php esc_html_e( 'Everything arrived intact', 'swiftmigrate' ); ?></h2>
			<p><?php esc_html_e( 'Every file and database chunk has been verified on the destination. Restoring switches the destination over to this site; it usually takes a minute or two.', 'swiftmigrate' ); ?></p>
			<button type="button" class="smg-btn smg-btn--primary smg-btn--big" data-act="restore"><?php esc_html_e( 'Restore on destination', 'swiftmigrate' ); ?></button>
		</div>

		<div class="smg-actions">
			<button type="button" class="smg-btn" data-act="pause"><?php esc_html_e( 'Pause', 'swiftmigrate' ); ?></button>
			<button type="button" class="smg-btn" data-act="resume" hidden><?php esc_html_e( 'Resume', 'swiftmigrate' ); ?></button>
			<button type="button" class="smg-btn smg-btn--danger-quiet" data-act="cancel"><?php esc_html_e( 'Cancel migration', 'swiftmigrate' ); ?></button>
		</div>

		<details class="smg-log">
			<summary><?php esc_html_e( 'Activity log', 'swiftmigrate' ); ?></summary>
			<pre data-bind="log"></pre>
		</details>
		<p class="smg-note"><?php esc_html_e( 'You can close this tab and come back later: progress is saved and the migration resumes where it stopped.', 'swiftmigrate' ); ?></p>
	</section>

	<!-- 4. Done -->
	<section class="smg-stage" data-stage="done" hidden>
		<div class="smg-done">
			<svg class="smg-done__mark" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="22"/><path d="M14 25l7 7 13-15"/></svg>
			<h1><?php esc_html_e( 'The site is live on its new home', 'swiftmigrate' ); ?></h1>
			<p class="smg-lede"><?php esc_html_e( 'Log in to the new site with this site\'s usernames and passwords. Permalinks are refreshed on its first page load.', 'swiftmigrate' ); ?></p>
			<div class="smg-actions">
				<a class="smg-btn smg-btn--primary" data-bind="visit" target="_blank" rel="noopener"><?php esc_html_e( 'Open the new site', 'swiftmigrate' ); ?></a>
				<a class="smg-btn" data-bind="login" target="_blank" rel="noopener"><?php esc_html_e( 'Log in there', 'swiftmigrate' ); ?></a>
			</div>
		</div>
		<div class="smg-panel smg-after">
			<h2><?php esc_html_e( 'After checking the new site', 'swiftmigrate' ); ?></h2>
			<p><?php esc_html_e( 'The destination still holds its previous files and database as a rollback copy. Something wrong? Roll back to exactly how it was. All good? Delete the copy to free disk space.', 'swiftmigrate' ); ?></p>
			<div class="smg-actions">
				<button type="button" class="smg-btn" data-act="purge"><?php esc_html_e( 'Delete rollback copy', 'swiftmigrate' ); ?></button>
				<button type="button" class="smg-btn smg-btn--danger-quiet" data-act="rollback"><?php esc_html_e( 'Roll back destination', 'swiftmigrate' ); ?></button>
				<button type="button" class="smg-btn smg-btn--quiet" data-act="new"><?php esc_html_e( 'Start another migration', 'swiftmigrate' ); ?></button>
			</div>
			<span class="smg-inline-status" data-bind="afterstatus" role="status" aria-live="polite"></span>
		</div>
	</section>
</div>
