<?php
/**
 * History & logs screen.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_history = SwiftMigrate_Logger::history();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection; the id is reduced to [a-z0-9].
$swiftmigrate_view = isset( $_GET['view'] ) ? SwiftMigrate_Storage::sanitize_id( sanitize_key( wp_unslash( $_GET['view'] ) ) ) : '';
$swiftmigrate_base    = admin_url( 'admin.php?page=swiftmigrate-logs' );
?>
<h1><?php esc_html_e( 'History & logs', 'swiftmigrate' ); ?></h1>

<?php if ( $swiftmigrate_view ) : ?>
	<?php $swiftmigrate_lines = SwiftMigrate_Logger::tail( $swiftmigrate_view, 2000 ); ?>
	<p><a href="<?php echo esc_url( $swiftmigrate_base ); ?>">&larr; <?php esc_html_e( 'All migrations', 'swiftmigrate' ); ?></a></p>
	<section class="smg-panel">
		<h2>
			<?php
			/* translators: %s: migration ID. */
			echo esc_html( sprintf( __( 'Migration %s', 'swiftmigrate' ), $swiftmigrate_view ) );
			?>
		</h2>
		<?php if ( ! $swiftmigrate_lines ) : ?>
			<p class="smg-muted"><?php esc_html_e( 'No log is stored for this migration on this site (it may have been cleaned up).', 'swiftmigrate' ); ?></p>
		<?php else : ?>
			<p class="smg-muted"><?php esc_html_e( 'Showing the latest entries.', 'swiftmigrate' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=swiftmigrate_download_log&id=' . rawurlencode( $swiftmigrate_view ) ), 'swiftmigrate_log' ) ); ?>"><?php esc_html_e( 'Download full log', 'swiftmigrate' ); ?></a>
			</p>
			<pre class="smg-logview"><?php
			foreach ( $swiftmigrate_lines as $swiftmigrate_line ) {
				$swiftmigrate_cls = false !== strpos( $swiftmigrate_line, ' ERROR ' ) ? 'is-error' : ( false !== strpos( $swiftmigrate_line, ' WARN ' ) ? 'is-warn' : '' );
				echo '<span class="' . esc_attr( $swiftmigrate_cls ) . '">' . esc_html( $swiftmigrate_line ) . "</span>\n";
			}
			?></pre>
		<?php endif; ?>
	</section>
<?php elseif ( ! $swiftmigrate_history ) : ?>
	<section class="smg-panel"><p class="smg-muted"><?php esc_html_e( 'No migrations have run on this site yet.', 'swiftmigrate' ); ?></p></section>
<?php else : ?>
	<table class="smg-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Started', 'swiftmigrate' ); ?></th>
				<th><?php esc_html_e( 'Direction', 'swiftmigrate' ); ?></th>
				<th><?php esc_html_e( 'Other site', 'swiftmigrate' ); ?></th>
				<th><?php esc_html_e( 'Status', 'swiftmigrate' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'swiftmigrate' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Log', 'swiftmigrate' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $swiftmigrate_history as $swiftmigrate_h ) : ?>
			<tr>
				<td class="smg-num"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $swiftmigrate_h['started'] ) ); ?></td>
				<td><?php echo 'source' === $swiftmigrate_h['role'] ? esc_html__( 'Sent', 'swiftmigrate' ) : esc_html__( 'Received', 'swiftmigrate' ); ?></td>
				<td><?php echo esc_html( preg_replace( '#^https?://#', '', (string) $swiftmigrate_h['peer'] ) ); ?></td>
				<td><span class="smg-badge smg-badge--<?php echo esc_attr( sanitize_html_class( $swiftmigrate_h['status'] ) ); ?>"><?php echo esc_html( SwiftMigrate_Logger::status_label( $swiftmigrate_h['status'] ) ); ?></span></td>
				<td class="smg-num"><?php echo ! empty( $swiftmigrate_h['finished'] ) ? esc_html( human_time_diff( (int) $swiftmigrate_h['started'], (int) $swiftmigrate_h['finished'] ) ) : '—'; ?></td>
				<td><a href="<?php echo esc_url( add_query_arg( 'view', $swiftmigrate_h['id'], $swiftmigrate_base ) ); ?>"><?php esc_html_e( 'View log', 'swiftmigrate' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
