<?php
/**
 * Overview screen.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_env     = SwiftMigrate_Plugin::environment();
$swiftmigrate_history = array_slice( SwiftMigrate_Logger::history(), 0, 5 );
?>
<section class="smg-intro">
	<h1><?php esc_html_e( 'Move this WordPress site to another server, directly.', 'swiftmigrate' ); ?></h1>
	<p class="smg-lede"><?php esc_html_e( 'Files and database travel server-to-server in parallel, checksummed chunks. Nothing is downloaded to your computer. Every file is verified on arrival before the new site is switched on, and the previous version of the destination is kept so you can roll back.', 'swiftmigrate' ); ?></p>
</section>

<section class="smg-choose">
	<a class="smg-choice" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftmigrate-send' ) ); ?>">
		<span class="smg-choice__kicker"><?php esc_html_e( 'This is the old site', 'swiftmigrate' ); ?></span>
		<strong><?php esc_html_e( 'Send this site', 'swiftmigrate' ); ?></strong>
		<span><?php esc_html_e( 'Paste the key from the new site and start the transfer.', 'swiftmigrate' ); ?></span>
	</a>
	<a class="smg-choice" href="<?php echo esc_url( admin_url( 'admin.php?page=swiftmigrate-receive' ) ); ?>">
		<span class="smg-choice__kicker"><?php esc_html_e( 'This is the new site', 'swiftmigrate' ); ?></span>
		<strong><?php esc_html_e( 'Receive a site', 'swiftmigrate' ); ?></strong>
		<span><?php esc_html_e( 'Generate a migration key to paste on the old site.', 'swiftmigrate' ); ?></span>
	</a>
</section>

<section class="smg-how">
	<h2><?php esc_html_e( 'How a migration runs', 'swiftmigrate' ); ?></h2>
	<ol class="smg-steps-static">
		<li><strong><?php esc_html_e( 'Install on both sites', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'The new site can be a fresh WordPress install on any domain.', 'swiftmigrate' ); ?></li>
		<li><strong><?php esc_html_e( 'Generate a key on the new site', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Receive a site → Generate migration key.', 'swiftmigrate' ); ?></li>
		<li><strong><?php esc_html_e( 'Paste it on the old site', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'Send this site → Connect, choose what to include, start.', 'swiftmigrate' ); ?></li>
		<li><strong><?php esc_html_e( 'Restore with one click', 'swiftmigrate' ); ?></strong> <?php esc_html_e( 'After every chunk is verified, the new site is switched over and URLs are rewritten.', 'swiftmigrate' ); ?></li>
	</ol>
</section>

<div class="smg-split">
	<section class="smg-panel">
		<h2><?php esc_html_e( 'This server', 'swiftmigrate' ); ?></h2>
		<table class="smg-kv">
			<?php foreach ( $swiftmigrate_env as $swiftmigrate_row ) : ?>
				<tr class="is-<?php echo esc_attr( $swiftmigrate_row[2] ); ?>"><th><?php echo esc_html( $swiftmigrate_row[0] ); ?></th><td><span class="smg-dot" aria-hidden="true"></span><?php echo esc_html( $swiftmigrate_row[1] ); ?></td></tr>
			<?php endforeach; ?>
		</table>
	</section>
	<section class="smg-panel">
		<h2><?php esc_html_e( 'Recent migrations', 'swiftmigrate' ); ?></h2>
		<?php if ( ! $swiftmigrate_history ) : ?>
			<p class="smg-muted"><?php esc_html_e( 'None yet. Start on the new site by generating a key.', 'swiftmigrate' ); ?></p>
		<?php else : ?>
			<ul class="smg-hist">
				<?php foreach ( $swiftmigrate_history as $swiftmigrate_h ) : ?>
					<li>
						<span class="smg-badge smg-badge--<?php echo esc_attr( sanitize_html_class( $swiftmigrate_h['status'] ) ); ?>"><?php echo esc_html( SwiftMigrate_Logger::status_label( $swiftmigrate_h['status'] ) ); ?></span>
						<?php echo 'source' === $swiftmigrate_h['role'] ? esc_html__( 'Sent to', 'swiftmigrate' ) : esc_html__( 'Received from', 'swiftmigrate' ); ?>
						<strong><?php echo esc_html( preg_replace( '#^https?://#', '', $swiftmigrate_h['peer'] ) ); ?></strong>
						<time>
							<?php
							/* translators: %s: human-readable time difference, e.g. "5 mins". */
							echo esc_html( sprintf( __( '%s ago', 'swiftmigrate' ), human_time_diff( (int) $swiftmigrate_h['started'] ) ) );
							?>
						</time>
					</li>
				<?php endforeach; ?>
			</ul>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=swiftmigrate-logs' ) ); ?>"><?php esc_html_e( 'All history and logs', 'swiftmigrate' ); ?></a></p>
		<?php endif; ?>
	</section>
</div>
