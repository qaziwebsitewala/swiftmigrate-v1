=== SwiftMigrate ===
Contributors: qaziwebsitewala
Tags: migration, migrate, clone, move site, transfer
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move a whole WordPress site (database and files) straight from one server to another. Verified transfer, one-click restore and rollback.

== Description ==

SwiftMigrate moves a complete WordPress site from its current server to a new one. The files and the database travel directly from server to server, so nothing is downloaded to your computer and there is no archive to upload.

**How a migration works**

1. Install and activate SwiftMigrate on both sites. The new site can be a fresh WordPress install on any domain.
2. On the new site, open SwiftMigrate → Receive a site → Generate migration key, then copy the key.
3. On the old site, open SwiftMigrate → Send this site, paste the key and click Connect.
4. Review the pre-flight check, choose what to move and confirm that the destination may be overwritten. Click Start migration.
5. When every chunk has been verified on the destination, click Restore on destination (or tick "Restore automatically").
6. Log in to the new site with the old site's username and password.
7. Check the new site, then click "Delete rollback copy" – or "Roll back" if something is wrong.

**Features**

* Direct server-to-server transfer with parallel streams (4 by default, configurable).
* Small files are packed together; large files of any size are split into chunks.
* Every file and chunk carries a SHA-1 checksum, and the destination rejects anything that does not match.
* After the transfer, the destination confirms every single chunk. Missing or damaged chunks are resent automatically before a restore is allowed.
* Fast database export using primary-key ranges, binary-safe values and statement sizes adapted to the destination's MySQL limits.
* The restore imports into temporary tables, rewrites URLs and server paths (including serialized, JSON-escaped and URL-encoded data), then switches all tables at once. Different table prefixes are handled automatically.
* Rollback: the destination's previous database and replaced files are kept until you delete them.
* Resumable: close the browser tab at any time and press Resume later.
* Full log of every migration under SwiftMigrate → History & logs, downloadable as a text file.

**Security**

* A migration key is generated on the destination. It contains the destination's address and a random 256-bit secret, and it expires after 24 hours by default.
* Every request between the two sites is signed with HMAC-SHA256, time-limited and single-use, so it cannot be altered or replayed.
* If the destination does not use HTTPS (or certificate verification is turned off), every request and response is additionally encrypted end-to-end with a key derived from the migration secret (XChaCha20-Poly1305, via PHP's sodium extension or the library bundled with WordPress). Site data is never sent in clear text. If encryption is not available, a non-HTTPS migration is refused.
* Temporary data is kept in a private folder inside the uploads directory with a random, unguessable name, protected against web access. Received files are stored under hashed names with a .bin extension until the restore, so no received code can run from that folder.
* Keys are revoked when the plugin is deactivated.
* All admin screens and actions require the `manage_options` capability.

**Never transferred**

wp-config.php, .htaccess, .user.ini, php.ini and web.config stay as they are on the destination, so its database credentials and server rules keep working.

**Limitations**

* Multisite networks are not supported.
* Files that exist only on the destination are left in place.
* Changes made on the source while a migration runs may not all be captured. Avoid editing content during the transfer.
* Rollback needs the "Keep a rollback copy" setting (on by default) and enough free disk space on the destination.

== External services ==

SwiftMigrate does not use any third-party service and does not send any data to the plugin author.

To perform a migration, the site you are moving (the source) sends its files and database content to **the WordPress site whose migration key you paste** (the destination). This happens only after you paste a key and click Connect / Start migration, and only to the address contained in that key. The destination is a WordPress site you control, running SwiftMigrate. The data is sent to the destination's REST API route `/wp-json/swiftmigrate/v1/transfer` (or `?rest_route=/swiftmigrate/v1/transfer`).

What is sent: the database tables and the files you choose to include (media, themes, plugins, WordPress core, other files in the site root), plus technical information needed for the transfer (site URL, server paths, table prefix, WordPress/PHP/MySQL versions and upload limits). Over HTTPS the data is encrypted by TLS; if the destination does not use HTTPS, SwiftMigrate encrypts every request and response itself with the migration key, so the data is never sent in clear text.

Because the destination is your own site, its privacy policy and terms are your own. No other service is involved.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New Plugin, search for "SwiftMigrate", then click Install Now and Activate. Alternatively, upload the `swiftmigrate` folder to `/wp-content/plugins/` and activate it on the Plugins screen.
2. Do the same on the second site (both the old and the new site need SwiftMigrate).
3. Follow the steps under "How a migration works" in the Description.

Requirements on both sites: WordPress 6.2 or later, PHP 7.4 or later, writable content and uploads folders, and a WordPress REST API that is reachable from the other server. The zlib PHP extension is recommended for compressed transfers.

== Frequently Asked Questions ==

= Which site do I start on? =

On the new site (the destination): generate the migration key there. Then paste the key on the old site (the source) and start the migration from the old site.

= Will the destination site be overwritten? =

Yes. When you restore, the destination's database, themes, plugins and media are replaced by the incoming site. wp-config.php and .htaccess are kept. With "Keep a rollback copy" enabled (the default), you can undo the switch with one click.

= Which username and password do I use after the migration? =

The ones from the old site. The destination now contains the old site's database, including its users.

= Where does SwiftMigrate store temporary data? =

In a private folder inside your uploads directory: `uploads/swiftmigrate/data-<random>`. The location is determined with `wp_upload_dir()`, and the folder has a random, unguessable name plus index.php, .htaccess and web.config rules that deny web access. Received files are kept there under hashed names with a `.bin` extension until the restore moves them into place, so nothing received can be executed from that folder. It is removed when you delete the plugin.

= Is the transfer encrypted? =

Yes. Over HTTPS, TLS encrypts it. If the destination does not use HTTPS, or you turned off certificate verification, SwiftMigrate encrypts every request and response end-to-end with the migration key (XChaCha20-Poly1305). Using HTTPS on the destination is still recommended.

= The transfer fails with HTTP 401, 403 or 406. What can I do? =

A security plugin or the host's firewall is probably blocking the REST API or binary uploads on the destination. Try these in order:

* In Settings, enable "Firewall-friendly mode" on the source site.
* Allow unauthenticated POST requests to `/wp-json/swiftmigrate/v1/transfer` in your security plugin on the destination. SwiftMigrate authenticates these requests itself with the migration key's signature.
* If the REST API is disabled entirely on the destination, enable it for the duration of the migration.

= The transfer times out on a slow host. =

Lower "Parallel streams" and "Maximum request size" in Settings on the source site.

= The destination has a self-signed SSL certificate. =

Disable "Verify SSL certificates" in Settings on the source site. Only do this if you trust the network between both servers.

= Can I close the browser during a migration? =

Yes. Progress is saved on the server. Open SwiftMigrate → Send this site again and click Resume.

= Is multisite supported? =

No. Both sites must be single-site WordPress installs.

= What does uninstalling remove? =

Deleting the plugin removes its settings, the `uploads/swiftmigrate` folder and any leftover staging or rollback tables. The migrated site itself is not touched.

== Changelog ==

= 1.0.1 =
* Working data moved to a private, unguessable folder inside the uploads directory (resolved with `wp_upload_dir()`).
* Received files are staged under hashed, non-executable names until the restore.
* Transfers to a destination without HTTPS (or with certificate verification off) are encrypted end-to-end.
* Site and content folders are resolved through the WordPress filesystem API.
* "Skip spam and trashed comments" now skips trashed comments too.
* All messages, including pre-flight checks, logs and the admin scripts, are translatable.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.1 =
Safer storage location and encrypted transfers to non-HTTPS destinations. Update both sites.
