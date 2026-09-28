<?php
/**
 * Shared header with section tabs.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

$swiftmigrate_current = SwiftMigrate_Plugin::current_page();
?>
<header class="smg-head">
	<div class="smg-brand">
		<svg class="smg-mark" viewBox="0 0 40 20" aria-hidden="true"><circle cx="5" cy="10" r="4"/><circle cx="35" cy="10" r="4"/><path d="M9 10h22" stroke-dasharray="3 3"/></svg>
		<span class="smg-name">SwiftMigrate</span>
		<span class="smg-ver"><?php echo esc_html( SWIFTMIGRATE_VERSION ); ?></span>
	</div>
	<nav class="smg-tabs" aria-label="<?php esc_attr_e( 'SwiftMigrate sections', 'swiftmigrate' ); ?>">
		<?php foreach ( SwiftMigrate_Plugin::pages() as $swiftmigrate_slug => $swiftmigrate_page ) : ?>
			<?php if ( $swiftmigrate_slug === $swiftmigrate_current ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $swiftmigrate_slug ) ); ?>" class="is-active" aria-current="page"><?php echo esc_html( $swiftmigrate_page[0] ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $swiftmigrate_slug ) ); ?>"><?php echo esc_html( $swiftmigrate_page[0] ); ?></a>
			<?php endif; ?>
		<?php endforeach; ?>
	</nav>
</header>
<?php
if ( SwiftMigrate_Plugin::MENU_SLUG === $swiftmigrate_current && get_transient( 'swiftmigrate_restored_notice' ) ) :
	delete_transient( 'swiftmigrate_restored_notice' );
	?>
	<div class="smg-alert smg-alert--ok"><p><?php esc_html_e( 'This site was restored by SwiftMigrate. Permalinks have been refreshed. Check a few pages, then delete the rollback copy on the Receive page to free disk space.', 'swiftmigrate' ); ?></p></div>
<?php endif; ?>
