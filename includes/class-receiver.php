<?php
/**
 * Destination side of a migration, called through the REST route in
 * SwiftMigrate_Api after the request signature has been verified.
 *
 * Restore is a resumable state machine, each call doing a bounded slice of work:
 *   db      import SQL chunks into staging tables (smgt_*); live site untouched
 *   sr      serialize-safe URL/path replacement inside the staging tables
 *   prefix  adapt table-prefix-bound keys, home/siteurl, keep this plugin active
 *           (only SwiftMigrate's own entry in active_plugins is touched)
 *   files   move media and other non-code files into place; journal for rollback
 *   code    move core, plugins, themes and drop-ins in one uninterrupted pass
 *   swap    same request as "code": one atomic RENAME TABLE,
 *           live → smgb_* (backup), smgt_* → live
 *   cleanup remove the emptied staging tree
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives, verifies and restores an incoming site.
 */
class SwiftMigrate_Receiver {

	const TMP = 'smgt_';
	const BAK = 'smgb_';

	/**
	 * Restore steps in order, with their labels. "code" (plugins, themes, core,
	 * drop-ins) is moved in one uninterrupted pass and immediately followed by
	 * "swap" in the same request, so no request ever runs a half-replaced
	 * plugin, theme or core.
	 *
	 * @return array<string, string> Step key => translated label.
	 */
	public static function steps() {
		return array(
			'db'      => __( 'Importing database', 'swiftmigrate' ),
			'sr'      => __( 'Updating URLs and paths', 'swiftmigrate' ),
			'prefix'  => __( 'Adapting settings to this server', 'swiftmigrate' ),
			'files'   => __( 'Moving media and other files into place', 'swiftmigrate' ),
			'code'    => __( 'Moving plugins, themes and core into place', 'swiftmigrate' ),
			'swap'    => __( 'Switching to the new database', 'swiftmigrate' ),
			'cleanup' => __( 'Removing staging files', 'swiftmigrate' ),
		);
	}

	private $id;
	private $state;
	private $file;
	private $deadline;

	// ---------------------------------------------------------------- helpers

	public static function home() {
		return defined( 'WP_HOME' ) && WP_HOME ? untrailingslashit( WP_HOME ) : untrailingslashit( get_option( 'home' ) );
	}

	public static function siteurl() {
		return defined( 'WP_SITEURL' ) && WP_SITEURL ? untrailingslashit( WP_SITEURL ) : untrailingslashit( get_option( 'siteurl' ) );
	}

	private static function roots() {
		return SwiftMigrate_Paths::roots();
	}

	private static function plugin_basename() {
		return plugin_basename( SWIFTMIGRATE_FILE );
	}

	public static function current_id() {
		$c = SwiftMigrate_Storage::read_json( SwiftMigrate_Storage::path( 'recv-current.json' ) );
		return isset( $c['id'] ) ? $c['id'] : '';
	}

	private function open( $id, $must_exist = true ) {
		$id = SwiftMigrate_Storage::sanitize_id( $id );
		if ( ! $id ) {
			throw new RuntimeException( esc_html__( 'Missing migration id.', 'swiftmigrate' ), 400 );
		}
		$this->id    = $id;
		$this->file  = SwiftMigrate_Storage::migration_dir( $id ) . '/dest.json';
		$this->state = SwiftMigrate_Storage::read_json( $this->file );
		if ( $must_exist && empty( $this->state['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Unknown migration on destination. Start the migration again from the source.', 'swiftmigrate' ), 404 );
		}
	}

	private function save() {
		$this->state['updated'] = time();
		SwiftMigrate_Storage::write_json( $this->file, $this->state );
	}

	private function dir( $sub = '' ) {
		return SwiftMigrate_Storage::migration_dir( $this->id ) . ( $sub ? '/' . $sub : '' );
	}

	private function log( $msg, $level = 'info' ) {
		SwiftMigrate_Logger::log( $this->id, $msg, $level );
	}

	private function time_left() {
		return microtime( true ) < $this->deadline;
	}

	// ---------------------------------------------------------------- handshake / init

	public function handshake( array $in ) {
		global $wpdb, $wp_version;
		$post_max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$memory   = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$packet   = 0;
		try {
			$packet = (int) SwiftMigrate_DB::var_( 'SELECT @@max_allowed_packet' );
		} catch ( Exception $e ) {
			$packet = 4194304;
		}
		$roots = self::roots();
		$store = SwiftMigrate_Storage::base();
		return array(
			'home'        => self::home(),
			'siteurl'     => self::siteurl(),
			'abspath'     => $roots['a'],
			'content_dir' => $roots['c'],
			'prefix'      => $wpdb->prefix,
			'wp'          => $wp_version,
			'php'         => PHP_VERSION,
			'mysql'       => $wpdb->db_version(),
			'multisite'   => is_multisite(),
			'post_max'    => $post_max > 0 ? $post_max : 512 * MB_IN_BYTES,
			'memory'      => $memory > 0 ? $memory : 1024 * MB_IN_BYTES,
			'packet'      => $packet,
			'max_exec'    => (int) ini_get( 'max_execution_time' ),
			'zlib'        => function_exists( 'gzdecode' ),
			'free'        => SwiftMigrate_Storage::free_space( $store ),
			'writable'    => wp_is_writable( $roots['a'] ) && wp_is_writable( $roots['c'] ) && wp_is_writable( $store ),
			'swiftmigrate' => SWIFTMIGRATE_VERSION,
			'name'        => get_option( 'blogname' ),
		);
	}

	public function init( array $in ) {
		global $wpdb;
		if ( ! wp_is_writable( SwiftMigrate_Paths::content_dir() ) || ! wp_is_writable( SwiftMigrate_Storage::base() ) ) {
			throw new RuntimeException( esc_html__( 'The content folder or the SwiftMigrate folder in uploads on the destination is not writable by PHP.', 'swiftmigrate' ), 500 );
		}
		if ( empty( $in['source'] ) || empty( $in['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Invalid init request.', 'swiftmigrate' ), 400 );
		}
		$prev = self::current_id();
		if ( $prev && $prev !== $in['id'] ) {
			$old = new self();
			$old->open( $prev, false );
			if ( ! empty( $old->state['phase'] ) && 'receiving' === $old->state['phase'] ) {
				$old->discard_staging( microtime( true ) + 10 );
				$old->state['phase'] = 'superseded';
				$old->save();
			}
		}
		$this->open( $in['id'], false );
		$this->state = array(
			'id'      => $this->id,
			'role'    => 'destination',
			'created' => time(),
			'phase'   => 'receiving',
			'source'  => $in['source'],
			'options' => isset( $in['options'] ) ? $in['options'] : array(),
			'prefix'  => $wpdb->prefix,
			'r'       => array(),
		);
		wp_mkdir_p( $this->dir( 'staging/files' ) );
		wp_mkdir_p( $this->dir( 'staging/db' ) );
		wp_mkdir_p( $this->dir( 'staging/maps' ) );
		$this->save();
		SwiftMigrate_Storage::write_json( SwiftMigrate_Storage::path( 'recv-current.json' ), array( 'id' => $this->id ) );
		/* translators: %s: address of the source site. */
		$this->log( sprintf( __( 'Incoming migration from %s', 'swiftmigrate' ), $in['source']['home'] ) );
		SwiftMigrate_Logger::history_add(
			array(
				'id'      => $this->id,
				'role'    => 'destination',
				'peer'    => $in['source']['home'],
				'started' => time(),
				'status'  => 'receiving',
			)
		);
		return array( 'accepted' => true );
	}

	// ---------------------------------------------------------------- receive

	/**
	 * Where a received file waits until it is moved into place.
	 *
	 * Staged files never keep their real name or extension: they are stored
	 * as <sha1>.bin, so an incoming PHP file cannot be executed from the
	 * working folder even on a server that ignores the .htaccess rules.
	 *
	 * @param string $root Root key.
	 * @param string $rel  Validated relative path.
	 * @return string
	 */
	private function staged( $root, $rel ) {
		return $this->dir( self::hashed( 'staging/files', $root, $rel ) );
	}

	/**
	 * Where the destination's previous version of a file is kept for rollback
	 * (same non-executable naming as staged()).
	 *
	 * @param string $root Root key.
	 * @param string $rel  Validated relative path.
	 * @return string
	 */
	private function backup_path( $root, $rel ) {
		return $this->dir( self::hashed( 'backup', $root, $rel ) );
	}

	/**
	 * Non-executable storage name for a site file: <folder>/<2 hex>/<sha1>.bin.
	 *
	 * @param string $folder Sub-folder of the migration folder.
	 * @param string $root   Root key.
	 * @param string $rel    Relative path.
	 * @return string
	 */
	private static function hashed( $folder, $root, $rel ) {
		$h = sha1( $root . ':' . $rel );
		return $folder . '/' . substr( $h, 0, 2 ) . '/' . $h . '.bin';
	}

	private function check_item( $root, $rel ) {
		if ( 'a' !== $root && 'c' !== $root ) {
			throw new RuntimeException( esc_html__( 'Invalid file root.', 'swiftmigrate' ), 400 );
		}
		$safe = SwiftMigrate_Storage::safe_rel( $rel );
		if ( false === $safe ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Rejected unsafe path: %s', 'swiftmigrate' ), $rel ) ), 400 );
		}
		return $safe;
	}

	private static function ensure_dir( $file ) {
		$d = dirname( $file );
		if ( ! is_dir( $d ) && ! wp_mkdir_p( $d ) && ! is_dir( $d ) ) {
			/* translators: %s: folder path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot create directory %s', 'swiftmigrate' ), $d ) ), 500 );
		}
	}

	public function put( $id, $body, $gz ) {
		$this->open( $id );
		if ( 'receiving' !== $this->state['phase'] ) {
			/* translators: %s: internal migration state. */
			throw new RuntimeException( esc_html( sprintf( __( 'Destination is no longer receiving for this migration (%s).', 'swiftmigrate' ), $this->state['phase'] ) ), 409 );
		}
		list( $h, $payload ) = SwiftMigrate_Packer::unpack( $body, $gz );
		$received = array();

		switch ( isset( $h['t'] ) ? $h['t'] : '' ) {
			case 'b':
				$off = 0;
				foreach ( (array) $h['items'] as $it ) {
					if ( ! empty( $it['x'] ) ) {
						continue;
					}
					$rel  = $this->check_item( $it['r'], $it['p'] );
					$data = (string) substr( $payload, $off, (int) $it['l'] );
					$off += (int) $it['l'];
					if ( strlen( $data ) !== (int) $it['l'] || sha1( $data ) !== $it['h'] ) {
						/* translators: %s: file path. */
						throw new RuntimeException( esc_html( sprintf( __( 'Checksum mismatch for %s; the packet will be resent.', 'swiftmigrate' ), $rel ) ), 422 );
					}
					$path = $this->staged( $it['r'], $rel );
					self::ensure_dir( $path );
					if ( ! SwiftMigrate_Fs::put( $path, $data ) ) {
						/* translators: %s: file path. */
						throw new RuntimeException( esc_html( sprintf( __( 'Cannot write %s on destination (disk full or permissions?).', 'swiftmigrate' ), $rel ) ), 507 );
					}
					$received[] = wp_json_encode( array( $it['r'], $rel ), JSON_UNESCAPED_SLASHES );
				}
				break;

			case 'c':
				$rel = $this->check_item( $h['r'], $h['p'] );
				if ( strlen( $payload ) !== (int) $h['l'] || sha1( $payload ) !== $h['h'] ) {
					/* translators: 1: chunk number, 2: file path. */
					throw new RuntimeException( esc_html( sprintf( __( 'Checksum mismatch for chunk %1$d of %2$s; the packet will be resent.', 'swiftmigrate' ), (int) $h['i'], $rel ) ), 422 );
				}
				$path = $this->staged( $h['r'], $rel );
				self::ensure_dir( $path );
				$fh = SwiftMigrate_Fs::open( $path, 'c+b' );
				if ( ! $fh ) {
					/* translators: %s: file path. */
					throw new RuntimeException( esc_html( sprintf( __( 'Cannot open %s for writing.', 'swiftmigrate' ), $rel ) ), 507 );
				}
				fseek( $fh, (int) $h['o'] );
				$w = SwiftMigrate_Fs::write( $fh, $payload );
				fflush( $fh );
				SwiftMigrate_Fs::close( $fh );
				if ( strlen( $payload ) !== $w ) {
					/* translators: %s: file path. */
					throw new RuntimeException( esc_html( sprintf( __( 'Short write on %s (disk full?).', 'swiftmigrate' ), $rel ) ), 507 );
				}
				// Chunk receipt map: one byte per chunk, so verification can prove there are no holes.
				$map = $this->dir( 'staging/maps/' . sha1( $h['r'] . ':' . $rel ) . '.map' );
				$mh  = SwiftMigrate_Fs::open( $map, 'c+b' );
				if ( ! $mh ) {
					/* translators: %s: file path. */
					throw new RuntimeException( esc_html( sprintf( __( 'Cannot record chunk receipt for %s.', 'swiftmigrate' ), $rel ) ), 507 );
				}
				fseek( $mh, (int) $h['i'] );
				SwiftMigrate_Fs::write( $mh, '1' );
				SwiftMigrate_Fs::close( $mh );
				if ( 0 === (int) $h['i'] ) {
					$received[] = wp_json_encode( array( $h['r'], $rel ), JSON_UNESCAPED_SLASHES );
				}
				break;

			case 'd':
				if ( sha1( $payload ) !== $h['h'] ) {
					throw new RuntimeException( esc_html__( 'Checksum mismatch in database chunk; it will be resent.', 'swiftmigrate' ), 422 );
				}
				$base = sprintf( '%05d-%06d', (int) $h['ti'], (int) $h['ci'] );
				$db   = $this->dir( 'staging/db' );
				if ( 0 === (int) $h['part'] ) {
					foreach ( (array) glob( $db . '/' . $base . '-*.sql' ) as $old ) {
						SwiftMigrate_Fs::delete( $old );
					}
					SwiftMigrate_Fs::delete( $db . '/' . $base . '.done' );
				}
				if ( ! SwiftMigrate_Fs::put( $db . '/' . sprintf( '%s-%05d.sql', $base, (int) $h['part'] ), $payload ) ) {
					throw new RuntimeException( esc_html__( 'Cannot write database chunk (disk full?).', 'swiftmigrate' ), 507 );
				}
				if ( ! empty( $h['last'] ) ) {
					SwiftMigrate_Fs::put( $db . '/' . $base . '.done', (string) ( (int) $h['part'] + 1 ) );
				}
				break;

			default:
				throw new RuntimeException( esc_html__( 'Unknown payload type.', 'swiftmigrate' ), 400 );
		}

		if ( $received ) {
			SwiftMigrate_Fs::append( $this->dir( 'received.jsonl' ), implode( "\n", $received ) . "\n" );
		}
		return array( 'stored' => count( $received ) );
	}

	// ---------------------------------------------------------------- verify

	public function verify( array $in ) {
		$this->open( isset( $in['id'] ) ? $in['id'] : '' );
		$bad = array();
		$db  = $this->dir( 'staging/db' );
		foreach ( (array) $in['entries'] as $e ) {
			$ok = true;
			if ( 'b' === $e['t'] ) {
				foreach ( (array) $e['items'] as $it ) {
					$rel = SwiftMigrate_Storage::safe_rel( $it[1] );
					$p   = $rel ? $this->staged( $it[0], $rel ) : '';
					clearstatcache( true, $p );
					if ( ! $rel || ! is_file( $p ) || filesize( $p ) !== (int) $it[2] ) {
						$ok = false;
						break;
					}
				}
			} elseif ( 'c' === $e['t'] ) {
				$rel = SwiftMigrate_Storage::safe_rel( $e['p'] );
				$map = $this->dir( 'staging/maps/' . sha1( $e['r'] . ':' . $rel ) . '.map' );
				$ok  = false;
				if ( $rel && is_file( $map ) && is_file( $this->staged( $e['r'], $rel ) ) ) {
					$fh = SwiftMigrate_Fs::open( $map, 'rb' );
					if ( $fh ) {
						fseek( $fh, (int) $e['i'] );
						$ok = '1' === SwiftMigrate_Fs::read( $fh, 1 );
						SwiftMigrate_Fs::close( $fh );
					}
				}
			} elseif ( 'd' === $e['t'] ) {
				$base = sprintf( '%05d-%06d', (int) $e['ti'], (int) $e['ci'] );
				$done = $db . '/' . $base . '.done';
				$ok   = is_file( $done );
				if ( $ok ) {
					$parts = (int) SwiftMigrate_Fs::get( $done );
					for ( $i = 0; $i < $parts; $i++ ) {
						if ( ! is_file( sprintf( '%s/%s-%05d.sql', $db, $base, $i ) ) ) {
							$ok = false;
							break;
						}
					}
				}
			}
			if ( ! $ok ) {
				$bad[] = (int) $e['j'];
			}
		}
		if ( $bad ) {
			/* translators: %d: number of transfer jobs. */
			$this->log( sprintf( _n( 'Verification: %d job incomplete, requesting resend', 'Verification: %d jobs incomplete, requesting resend', count( $bad ), 'swiftmigrate' ), count( $bad ) ), 'warn' );
		}
		return array( 'bad' => $bad );
	}

	// ---------------------------------------------------------------- restore

	public function restore( array $in ) {
		$this->open( isset( $in['id'] ) ? $in['id'] : '' );
		$this->deadline = microtime( true ) + SwiftMigrate_Settings::budget();

		if ( 'done' === $this->state['phase'] ) {
			return $this->progress( true );
		}
		if ( ! in_array( $this->state['phase'], array( 'receiving', 'restoring' ), true ) ) {
			/* translators: %s: internal migration state. */
			throw new RuntimeException( esc_html( sprintf( __( 'This migration cannot be restored (state: %s).', 'swiftmigrate' ), $this->state['phase'] ) ), 409 );
		}
		if ( 'receiving' === $this->state['phase'] ) {
			$this->state['phase'] = 'restoring';
			$this->state['r']     = array( 'step' => 'db' );
			$this->log( __( 'Restore started.', 'swiftmigrate' ) );
			$this->save();
		}

		$r = &$this->state['r'];
		try {
			while ( $this->time_left() || 'swap' === $r['step'] ) {
				$step = $r['step'];
				$done = $this->{ 'step_' . $step }( $r );
				if ( ! $done ) {
					break;
				}
				$steps = self::steps();
				$keys  = array_keys( $steps );
				$next  = array_search( $step, $keys, true ) + 1;
				/* translators: %s: name of a restore step. */
				$this->log( sprintf( __( 'Completed: %s', 'swiftmigrate' ), $steps[ $step ] ) );
				if ( $next >= count( $keys ) ) {
					$this->finish();
					return $this->progress( true );
				}
				$r['step'] = $keys[ $next ];
				$this->save();
			}
		} catch ( Exception $e ) {
			/* translators: %s: error message. */
			$this->log( sprintf( __( 'Restore error: %s', 'swiftmigrate' ), $e->getMessage() ), 'error' );
			$this->save();
			throw new RuntimeException( esc_html( $e->getMessage() ), 500 );
		}
		$this->save();
		return $this->progress( false );
	}

	private function progress( $done ) {
		$r    = isset( $this->state['r'] ) ? $this->state['r'] : array();
		$step = isset( $r['step'] ) ? $r['step'] : 'db';
		$pct  = 0;
		if ( 'db' === $step && ! empty( $r['files'] ) ) {
			$pct = $r['fi'] / max( 1, count( $r['files'] ) );
		} elseif ( 'sr' === $step && isset( $r['tables'] ) ) {
			$pct = ( isset( $r['ti'] ) ? $r['ti'] : 0 ) / max( 1, count( $r['tables'] ) );
		} elseif ( 'files' === $step && ! empty( $r['jsize'] ) ) {
			$pct = $r['jpos'] / max( 1, $r['jsize'] );
		} elseif ( 'code' === $step && ! empty( $r['jsize'] ) && isset( $r['cpos'] ) ) {
			$pct = $r['cpos'] / max( 1, $r['jsize'] );
		}
		$steps = self::steps();
		$keys  = array_keys( $steps );
		$idx   = array_search( $step, $keys, true );
		return array(
			'done'     => (bool) $done,
			'step'     => $step,
			'label'    => $done ? __( 'Restore complete', 'swiftmigrate' ) : $steps[ $step ],
			'index'    => $done ? count( $keys ) : (int) $idx,
			'steps'    => array_values( $steps ),
			'step_pct' => round( min( 1, $pct ) * 100, 1 ),
			'home'     => self::home(),
		);
	}

	// -- db

	private function step_db( array &$r ) {
		global $wpdb;
		if ( empty( $this->state['options']['db'] ) ) {
			return true;
		}
		if ( ! isset( $r['files'] ) ) {
			foreach ( SwiftMigrate_DB::tables_like( self::TMP ) as $t ) {
				SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $t ) );
			}
			$files = array_map( 'basename', (array) glob( $this->dir( 'staging/db' ) . '/*.sql' ) );
			sort( $files, SORT_STRING );
			$r['files'] = $files;
			$r['fi']    = 0;
			$r['si']    = 0;
			$this->save();
		}
		SwiftMigrate_DB::import_session( isset( $this->state['source']['charset'] ) ? $this->state['source']['charset'] : '' );
		$count = count( $r['files'] );
		while ( $r['fi'] < $count ) {
			$sql   = (string) SwiftMigrate_Fs::get( $this->dir( 'staging/db/' . $r['files'][ $r['fi'] ] ) );
			$stmts = explode( SwiftMigrate_DB::EOS, $sql );
			unset( $sql );
			$n = count( $stmts );
			while ( $r['si'] < $n ) {
				$st = trim( $stmts[ $r['si'] ] );
				$r['si']++;
				if ( '' !== $st ) {
					$this->exec( $st, $r['files'][ $r['fi'] ] );
				}
				if ( ! $this->time_left() ) {
					return false;
				}
			}
			$r['fi']++;
			$r['si'] = 0;
		}
		return true;
	}

	private function exec( $st, $file ) {
		if ( 0 === strncmp( $st, 'INSERT', 6 ) ) {
			$pos = strpos( $st, ') VALUES (' );
			$pos = false === $pos ? strpos( $st, ' VALUES ' ) : $pos;
			$sql = str_replace( SwiftMigrate_DB::PLACEHOLDER, self::TMP, substr( $st, 0, $pos ) ) . substr( $st, $pos );
		} else {
			$sql = str_replace( SwiftMigrate_DB::PLACEHOLDER, self::TMP, $st );
		}
		try {
			SwiftMigrate_DB::q( $sql );
		} catch ( RuntimeException $e ) {
			if ( SwiftMigrate_DB::is_collation_error( $e->getMessage() ) ) {
				foreach ( self::collation_fallbacks( $sql ) as $alt ) {
					try {
						SwiftMigrate_DB::q( $alt );
						$this->log( __( 'Adjusted an unsupported charset/collation for this MySQL server.', 'swiftmigrate' ) );
						return;
					} catch ( RuntimeException $e2 ) {
						$e = $e2;
					}
				}
			}
			$what = preg_match( '/^(\w+ (?:TABLE|INTO)(?: IF EXISTS)?) `([^`]+)`/', $sql, $m ) ? $m[1] . ' ' . $m[2] : substr( $sql, 0, 60 );
			/* translators: 1: SQL statement summary, 2: dump file name, 3: database error message. */
			throw new RuntimeException( esc_html( sprintf( __( 'Database import failed at %1$s (%2$s): %3$s', 'swiftmigrate' ), $what, $file, $e->getMessage() ) ) );
		}
	}

	private static function collation_fallbacks( $sql ) {
		$a = preg_replace( '/utf8mb4_0900_\w+/', 'utf8mb4_unicode_520_ci', $sql );
		$a = preg_replace( '/utf8mb4_uca1400_\w+/', 'utf8mb4_unicode_520_ci', $a );
		$b = str_replace( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', $a );
		$c = str_replace( 'utf8mb4', 'utf8', $b );
		return array_unique( array( $a, $b, $c ) );
	}

	// -- search & replace

	private function pairs() {
		$src   = $this->state['source'];
		$roots = self::roots();
		$dst   = array(
			'home'        => self::home(),
			'siteurl'     => self::siteurl(),
			'abspath'     => $roots['a'],
			'content_dir' => $roots['c'],
		);
		$custom = isset( $this->state['options']['pairs'] ) ? (array) $this->state['options']['pairs'] : array();
		return SwiftMigrate_Search_Replace::build_pairs( $src, $dst, $custom );
	}

	/** Minimal set of substrings such that every needle contains one: drives a SQL LIKE pre-filter. */
	private static function core_needles( array $needles ) {
		usort(
			$needles,
			function ( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);
		$core = array();
		foreach ( $needles as $n ) {
			foreach ( $core as $c ) {
				if ( false !== strpos( $n, $c ) ) {
					continue 2;
				}
			}
			$core[] = $n;
		}
		return $core;
	}

	private function step_sr( array &$r ) {
		if ( ! isset( $r['tables'] ) ) {
			$r['tables'] = array();
			foreach ( SwiftMigrate_DB::tables_like( self::TMP ) as $t ) {
				$r['tables'][] = substr( $t, strlen( self::TMP ) );
			}
			$r['ti']   = 0;
			$r['last'] = null;
			$this->save();
		}
		$sr = new SwiftMigrate_Search_Replace( $this->pairs() );
		if ( ! $sr->has_work() ) {
			return true;
		}
		SwiftMigrate_DB::import_session( isset( $this->state['source']['charset'] ) ? $this->state['source']['charset'] : '' );
		$core = self::core_needles( $sr->needles() );

		while ( $r['ti'] < count( $r['tables'] ) ) {
			$suffix = $r['tables'][ $r['ti'] ];
			$table  = self::TMP . $suffix;
			$pk     = self::pluck_ordered( SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ) ), 'Column_name', 'Seq_in_index' );
			$text   = array();
			foreach ( SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( 'SHOW COLUMNS FROM %i', $table ) ) as $c ) {
				if ( preg_match( '/char|text|json/i', $c['Type'] ) && ! ( 'posts' === $suffix && 'guid' === $c['Field'] ) && ! in_array( $c['Field'], $pk, true ) ) {
					$text[] = $c['Field'];
				}
			}
			if ( ! $pk || ! $text ) {
				if ( ! $pk && $text ) {
					/* translators: %s: database table name. */
					$this->log( sprintf( __( 'Table %s has no primary key; URLs inside it were not rewritten.', 'swiftmigrate' ), $suffix ), 'warn' );
				}
				$r['ti']++;
				$r['last'] = null;
				continue;
			}

			// SELECT pk..., text... FROM table WHERE (col LIKE ... OR ...) [AND keyset] ORDER BY pk LIMIT 500.
			$select = array_merge( $pk, $text );
			$args   = $select;
			$args[] = $table;
			$like   = array();
			foreach ( $text as $c ) {
				foreach ( $core as $n ) {
					$like[] = '%i LIKE %s';
					$args[] = $c;
					$args[] = '%' . SwiftMigrate_DB::like_escape( $n ) . '%';
				}
			}
			$where = '(' . implode( ' OR ', $like ) . ')';
			if ( null !== $r['last'] ) {
				list( $ks_sql, $ks_args ) = self::keyset( $pk, $r['last'] );
				$where                   .= ' AND ' . $ks_sql;
				$args                     = array_merge( $args, $ks_args );
			}
			$args = array_merge( $args, $pk );
			$sql  = 'SELECT ' . self::placeholders( '%i', count( $select ) ) . ' FROM %i WHERE ' . $where
				. ' ORDER BY ' . self::placeholders( '%i', count( $pk ) ) . ' LIMIT 500';
			$rows = SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( $sql, ...$args ) );

			foreach ( $rows as $row ) {
				$set      = array();
				$set_args = array();
				foreach ( $text as $c ) {
					$new = $sr->replace( $row[ $c ] );
					if ( null !== $row[ $c ] && $new !== $row[ $c ] ) {
						$set[]      = '%i = %s';
						$set_args[] = $c;
						$set_args[] = $new;
					}
				}
				if ( $set ) {
					$key      = array();
					$key_args = array();
					foreach ( $pk as $k ) {
						$key[]      = '%i = %s';
						$key_args[] = $k;
						$key_args[] = $row[ $k ];
					}
					SwiftMigrate_DB::q(
						SwiftMigrate_DB::prepare(
							'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $key ) . ' LIMIT 1',
							...array_merge( array( $table ), $set_args, $key_args )
						)
					);
				}
				$r['last'] = array_map(
					function ( $k ) use ( $row ) {
						return $row[ $k ];
					},
					$pk
				);
			}
			if ( count( $rows ) < 500 ) {
				$r['ti']++;
				$r['last'] = null;
			}
			if ( ! $this->time_left() ) {
				return false;
			}
		}
		return true;
	}

	/** Primary-key column names in index order. */
	private static function pluck_ordered( array $rows, $field, $order_by ) {
		usort(
			$rows,
			function ( $a, $b ) use ( $order_by ) {
				return (int) $a[ $order_by ] - (int) $b[ $order_by ];
			}
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = $r[ $field ];
		}
		return $out;
	}

	/**
	 * (a,b) > (x,y) expanded so MySQL can use the primary index.
	 *
	 * @param string[] $pk   Primary key columns.
	 * @param array    $last Last seen key values.
	 * @return array{0: string, 1: array} SQL fragment with placeholders, and its arguments.
	 */
	private static function keyset( array $pk, array $last ) {
		$or   = array();
		$args = array();
		$n    = count( $pk );
		for ( $i = 0; $i < $n; $i++ ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[]  = '%i = %s';
				$args[] = $pk[ $j ];
				$args[] = (string) $last[ $j ];
			}
			$and[]  = '%i > %s';
			$args[] = $pk[ $i ];
			$args[] = (string) $last[ $i ];
			$or[]   = '(' . implode( ' AND ', $and ) . ')';
		}
		return array( '(' . implode( ' OR ', $or ) . ')', $args );
	}

	/**
	 * Comma-separated list of the same placeholder.
	 *
	 * @param string $ph    Placeholder, e.g. %i.
	 * @param int    $count How many.
	 * @return string
	 */
	private static function placeholders( $ph, $count ) {
		return implode( ',', array_fill( 0, max( 1, (int) $count ), $ph ) );
	}

	// -- prefix & settings

	private function step_prefix( array &$r ) {
		global $wpdb;
		$tables = SwiftMigrate_DB::tables_like( self::TMP );
		$opt    = self::TMP . 'options';
		if ( ! in_array( $opt, $tables, true ) ) {
			return true;
		}
		$src = (string) $this->state['source']['prefix'];
		$dst = (string) $this->state['prefix'];
		if ( $src !== $dst ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $opt, $dst . 'user_roles', $src . 'user_roles' ) );
			$um = self::TMP . 'usermeta';
			if ( in_array( $um, $tables, true ) ) {
				SwiftMigrate_DB::q(
					SwiftMigrate_DB::prepare(
						'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
						$um,
						$dst,
						strlen( $src ) + 1,
						SwiftMigrate_DB::like_escape( $src ) . '%'
					)
				);
			}
			/* translators: 1: source table prefix, 2: destination table prefix. */
			$this->log( sprintf( __( 'Table prefix adapted: %1$s → %2$s', 'swiftmigrate' ), $src, $dst ) );
		}
		// home/siteurl must match this server exactly (http vs https, www, subfolder).
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "UPDATE %i SET option_value = %s WHERE option_name = 'home'", $opt, self::home() ) );
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "UPDATE %i SET option_value = %s WHERE option_name = 'siteurl'", $opt, self::siteurl() ) );
		// Rewrite rules are regenerated for this server on first load.
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "UPDATE %i SET option_value = '' WHERE option_name = 'rewrite_rules'", $opt ) );

		// Keep SwiftMigrate active on the new database so rollback stays available.
		$raw    = SwiftMigrate_DB::var_( SwiftMigrate_DB::prepare( "SELECT option_value FROM %i WHERE option_name = 'active_plugins'", $opt ) );
		$active = ( $raw && is_serialized( $raw ) ) ? unserialize( $raw, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disallowed; this is the active_plugins option (a string array).
		$active = is_array( $active ) ? $active : array();
		$srcpl  = isset( $this->state['source']['plugin'] ) ? $this->state['source']['plugin'] : '';
		$mine   = self::plugin_basename();
		$active = array_values(
			array_filter(
				$active,
				function ( $p ) use ( $srcpl, $mine ) {
					return $p !== $srcpl || $p === $mine;
				}
			)
		);
		if ( ! in_array( $mine, $active, true ) ) {
			$active[] = $mine;
		}
		sort( $active );
		$ser = maybe_serialize( $active );
		if ( null === $raw ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES ('active_plugins', %s, 'yes')", $opt, $ser ) );
		} else {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "UPDATE %i SET option_value = %s WHERE option_name = 'active_plugins'", $opt, $ser ) );
		}

		// SwiftMigrate's own working folder must stay the same after the switch
		// (its state, logs and rollback copy live there): carry this site's
		// value over and drop the one that came with the source database.
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DELETE FROM %i WHERE option_name = %s', $opt, SwiftMigrate_Storage::OPTION ) );
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, SwiftMigrate_Storage::OPTION, SwiftMigrate_Storage::base() ) );
		return true;
	}

	// -- files

	private function protected_path( $root, $rel ) {
		if ( 'a' === $root && in_array( $rel, array( 'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config', '.maintenance' ), true ) ) {
			return true;
		}
		foreach ( self::guarded( $root ) as $g ) {
			if ( $rel === $g || 0 === strpos( $rel, $g . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Folders a restore must never touch, relative to the given root: this
	 * plugin's own folder and SwiftMigrate's working folder in uploads.
	 *
	 * @param string $root Root key.
	 * @return string[]
	 */
	private static function guarded( $root ) {
		static $cache = array();
		if ( ! isset( $cache[ $root ] ) ) {
			$roots = self::roots();
			$out   = array();
			if ( isset( $roots[ $root ] ) ) {
				foreach ( array( SwiftMigrate_Storage::parent_dir(), plugin_dir_path( SWIFTMIGRATE_FILE ) ) as $path ) {
					$rel = SwiftMigrate_Paths::relative( $path, $roots[ $root ] );
					if ( '' !== $rel ) {
						$out[] = $rel;
					}
				}
			}
			$cache[ $root ] = $out;
		}
		return $cache[ $root ];
	}

	/**
	 * Whether a received file is executable code that every request may load
	 * (WordPress core, plugins, must-use plugins, themes, wp-content drop-ins).
	 *
	 * @param string $root Root key ("a" = ABSPATH, "c" = wp-content).
	 * @param string $rel  Relative path.
	 * @return bool
	 */
	private static function is_code( $root, $rel ) {
		if ( 'a' === $root ) {
			return true;
		}
		return false === strpos( $rel, '/' ) || (bool) preg_match( '#^(plugins|mu-plugins|themes)/#', $rel );
	}

	/**
	 * Moves media and other non-code files into place, in time-boxed slices.
	 *
	 * @param array $r Restore state.
	 * @return bool Whether the step is complete.
	 */
	private function step_files( array &$r ) {
		return $this->move_files( $r, 'jpos', false, true );
	}

	/**
	 * Moves code files into place in one uninterrupted pass (renames only, so
	 * this is fast); the restore loop then switches the database in the same
	 * request.
	 *
	 * @param array $r Restore state.
	 * @return bool Always true.
	 */
	private function step_code( array &$r ) {
		return $this->move_files( $r, 'cpos', true, false );
	}

	/**
	 * Walks the list of received files and moves the selected kind into place.
	 *
	 * @param array  $r        Restore state.
	 * @param string $pos_key  State key holding the read offset in received.jsonl.
	 * @param bool   $code     True to move code files, false for everything else.
	 * @param bool   $budgeted Whether to stop when this request's time budget is used up.
	 * @return bool Whether the pass is complete.
	 */
	private function move_files( array &$r, $pos_key, $code, $budgeted ) {
		$list = $this->dir( 'received.jsonl' );
		if ( ! is_file( $list ) ) {
			return true;
		}
		if ( ! isset( $r[ $pos_key ] ) ) {
			$r[ $pos_key ] = 0;
			$r['jsize']    = filesize( $list );
			$r['moved']    = isset( $r['moved'] ) ? $r['moved'] : 0;
		}
		$roots  = self::roots();
		$backup = ! empty( $this->state['options']['keep_backup'] );
		$in     = SwiftMigrate_Fs::open( $list, 'rb' );
		$jr     = SwiftMigrate_Fs::open( $this->dir( 'journal.jsonl' ), 'ab' );
		if ( ! $in || ! $jr ) {
			SwiftMigrate_Fs::close( $in );
			SwiftMigrate_Fs::close( $jr );
			throw new RuntimeException( esc_html__( 'Cannot open the file list or rollback journal.', 'swiftmigrate' ), 500 );
		}
		fseek( $in, $r[ $pos_key ] );
		$complete = true;
		while ( ( $line = fgets( $in ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$item = json_decode( trim( $line ), true );
			if ( is_array( $item ) && 2 === count( $item ) && self::is_code( $item[0], (string) $item[1] ) === $code ) {
				$this->move_one( $item[0], $item[1], $roots, $backup, $jr, $r );
			}
			$r[ $pos_key ] = ftell( $in );
			if ( $budgeted && ! $this->time_left() ) {
				$complete = false;
				break;
			}
		}
		SwiftMigrate_Fs::close( $in );
		SwiftMigrate_Fs::close( $jr );
		if ( $complete ) {
			/* translators: %s: number of files. */
			$this->log( sprintf( __( '%s files moved into place so far', 'swiftmigrate' ), number_format_i18n( $r['moved'] ) ) );
		}
		return $complete;
	}

	private function move_one( $root, $rel, $roots, $backup, $jr, array &$r ) {
		$rel    = SwiftMigrate_Storage::safe_rel( $rel );
		if ( ! $rel || ! isset( $roots[ $root ] ) ) {
			return;
		}
		$staged = $this->staged( $root, $rel );
		if ( ! is_file( $staged ) ) {
			return; // Duplicate receipt line: already moved.
		}
		if ( $this->protected_path( $root, $rel ) ) {
			SwiftMigrate_Fs::delete( $staged );
			return;
		}
		$target = $roots[ $root ] . '/' . $rel;
		if ( is_dir( $target ) && ! is_link( $target ) ) {
			/* translators: %s: file path. */
			$this->log( sprintf( __( 'Skipped %s: a directory with that name exists on the destination.', 'swiftmigrate' ), $rel ), 'warn' );
			return;
		}
		self::ensure_dir( $target );
		$had = file_exists( $target ) || is_link( $target );
		// Write-ahead journal: rollback knows what to undo even after a crash.
		SwiftMigrate_Fs::write( $jr, wp_json_encode( array( $had ? 'B' : 'N', $root, $rel ), JSON_UNESCAPED_SLASHES ) . "\n" );
		fflush( $jr );
		if ( $had ) {
			if ( $backup ) {
				$bak = $this->backup_path( $root, $rel );
				self::ensure_dir( $bak );
				SwiftMigrate_Fs::move( $target, $bak, true );
			}
			SwiftMigrate_Fs::delete( $target );
		}
		if ( ! SwiftMigrate_Fs::move( $staged, $target, true ) ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot write %s into place. Check file permissions on the destination.', 'swiftmigrate' ), $rel ) ) );
		}
		$r['moved']++;
	}

	private function step_cleanup( array &$r ) {
		return SwiftMigrate_Storage::rrmdir_step( $this->dir( 'staging' ), $this->deadline );
	}

	// -- swap

	private function step_swap( array &$r ) {
		$tmp = SwiftMigrate_DB::tables_like( self::TMP );
		if ( ! $tmp ) {
			return true;
		}
		$dst  = (string) $this->state['prefix'];
		$live = array();
		foreach ( SwiftMigrate_DB::tables_like( $dst ) as $t ) {
			if ( 0 === strpos( $t, self::TMP ) || 0 === strpos( $t, self::BAK ) ) {
				continue;
			}
			$live[] = substr( $t, strlen( $dst ) );
		}
		foreach ( SwiftMigrate_DB::tables_like( self::BAK ) as $t ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $t ) );
		}
		$ren = array();
		foreach ( $live as $s ) {
			$ren[] = array( $dst . $s, self::BAK . $s );
		}
		$new = array();
		foreach ( $tmp as $t ) {
			$s     = substr( $t, strlen( self::TMP ) );
			$new[] = $s;
			$ren[] = array( $t, $dst . $s );
		}
		// A single RENAME TABLE is atomic: visitors see the old site or the new one, never a mix.
		self::rename_tables( $ren );
		$this->state['swap'] = array(
			'bak' => $live,
			'new' => $new,
		);
		if ( empty( $this->state['options']['keep_backup'] ) ) {
			foreach ( $live as $s ) {
				SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', self::BAK . $s ) );
			}
			$this->state['swap']['bak'] = array();
		}
		/* translators: 1: number of tables now live, 2: number of previous tables kept. */
		$this->log( sprintf( __( 'Database switched: %1$d tables live, %2$d previous tables kept as backup', 'swiftmigrate' ), count( $new ), count( $this->state['swap']['bak'] ) ) );
		wp_cache_flush();
		return true;
	}

	/**
	 * Clears PHP's opcode cache after code files were replaced, where allowed.
	 */
	private static function reset_opcache() {
		if ( function_exists( 'opcache_reset' ) && '' === (string) ini_get( 'opcache.restrict_api' ) ) {
			opcache_reset();
		}
	}

	/**
	 * Renames several tables in one atomic statement.
	 *
	 * @param array[] $pairs List of array( from, to ).
	 */
	private static function rename_tables( array $pairs ) {
		$parts = array();
		$args  = array();
		foreach ( $pairs as $p ) {
			$parts[] = '%i TO %i';
			$args[]  = $p[0];
			$args[]  = $p[1];
		}
		SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'RENAME TABLE ' . implode( ', ', $parts ), ...$args ) );
	}

	private function finish() {
		$this->state['phase']    = 'done';
		$this->state['finished'] = time();
		$this->save();
		// The new database is live now: flag the first page load (see SwiftMigrate_Plugin::post_restore()).
		update_option( 'swiftmigrate_post_restore', time(), true );
		self::reset_opcache();
		SwiftMigrate_Security::extend( 48 ); // Rollback stays possible for two days.
		/* translators: %s: site address. */
		$this->log( sprintf( __( 'Migration complete. The site is live on %s', 'swiftmigrate' ), self::home() ) );
		SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'destination', 'status' => 'done', 'finished' => time() ) );
	}

	// ---------------------------------------------------------------- rollback / purge / abort

	public function rollback( array $in ) {
		$this->open( isset( $in['id'] ) ? $in['id'] : self::current_id() );
		$this->deadline = microtime( true ) + SwiftMigrate_Settings::budget();
		if ( empty( $this->state['options']['keep_backup'] ) ) {
			throw new RuntimeException( esc_html__( 'Rollback is not available: "Keep a rollback copy" was off for this migration.', 'swiftmigrate' ), 409 );
		}
		if ( ! in_array( $this->state['phase'], array( 'restoring', 'done', 'rolling_back' ), true ) ) {
			/* translators: %s: internal migration state. */
			throw new RuntimeException( esc_html( sprintf( __( 'Nothing to roll back (state: %s).', 'swiftmigrate' ), $this->state['phase'] ) ), 409 );
		}
		$this->state['phase'] = 'rolling_back';
		$rb = isset( $this->state['rb'] ) ? $this->state['rb'] : array( 'jpos' => 0 );
		$roots   = self::roots();
		$journal = $this->dir( 'journal.jsonl' );

		if ( ! isset( $rb['files_done'] ) && is_file( $journal ) ) {
			$fh = SwiftMigrate_Fs::open( $journal, 'rb' );
			if ( ! $fh ) {
				throw new RuntimeException( esc_html__( 'Cannot read the rollback journal.', 'swiftmigrate' ), 500 );
			}
			fseek( $fh, $rb['jpos'] );
			while ( ( $line = fgets( $fh ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$e   = json_decode( trim( $line ), true );
				$rel = is_array( $e ) && 3 === count( $e ) ? SwiftMigrate_Storage::safe_rel( $e[2] ) : false;
				if ( $rel && isset( $roots[ $e[1] ] ) ) {
					$target = $roots[ $e[1] ] . '/' . $rel;
					$bak    = $this->backup_path( $e[1], $rel );
					if ( 'B' === $e[0] && file_exists( $bak ) ) {
						SwiftMigrate_Fs::delete( $target );
						SwiftMigrate_Fs::move( $bak, $target, true );
					} elseif ( 'N' === $e[0] && ! $this->protected_path( $e[1], $rel ) ) {
						SwiftMigrate_Fs::delete( $target );
						self::prune_empty_dirs( $roots[ $e[1] ], $rel );
					}
				}
				$rb['jpos'] = ftell( $fh );
				if ( ! $this->time_left() ) {
					SwiftMigrate_Fs::close( $fh );
					$this->state['rb'] = $rb;
					$this->save();
					return array(
						'done'  => false,
						'label' => __( 'Restoring previous files', 'swiftmigrate' ),
					);
				}
			}
			SwiftMigrate_Fs::close( $fh );
		}
		$rb['files_done'] = true;

		$dst = (string) $this->state['prefix'];
		if ( ! empty( $this->state['swap'] ) ) {
			foreach ( $this->state['swap']['new'] as $s ) {
				SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $dst . $s ) );
			}
			$ren = array();
			foreach ( $this->state['swap']['bak'] as $s ) {
				$ren[] = array( self::BAK . $s, $dst . $s );
			}
			if ( $ren ) {
				self::rename_tables( $ren );
			}
			unset( $this->state['swap'] );
		}
		foreach ( SwiftMigrate_DB::tables_like( self::TMP ) as $t ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $t ) );
		}
		wp_cache_flush();
		self::reset_opcache();
		$this->state['phase'] = 'rolled_back';
		$this->state['rb']    = $rb;
		$this->save();
		$this->log( __( 'Rolled back: previous files and database restored.', 'swiftmigrate' ) );
		SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'destination', 'status' => 'rolled back' ) );
		return array(
			'done'  => true,
			'label' => __( 'Rollback complete', 'swiftmigrate' ),
		);
	}

	/** Removes folders left empty by rollback, walking up but never past the root. */
	private static function prune_empty_dirs( $root, $rel ) {
		$dir = dirname( $rel );
		while ( '.' !== $dir && '' !== $dir && '/' !== $dir ) {
			$path = $root . '/' . $dir;
			if ( ! is_dir( $path ) || is_link( $path ) || count( (array) scandir( $path ) ) > 2 || ! SwiftMigrate_Fs::rmdir( $path ) ) {
				return;
			}
			$dir = dirname( $dir );
		}
	}

	/** Deletes the rollback copy (files + smgb_ tables) to free disk space. */
	public function purge( array $in ) {
		$this->open( isset( $in['id'] ) ? $in['id'] : self::current_id() );
		$deadline = microtime( true ) + SwiftMigrate_Settings::budget();
		foreach ( SwiftMigrate_DB::tables_like( self::BAK ) as $t ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $t ) );
		}
		$done = SwiftMigrate_Storage::rrmdir_step( $this->dir( 'backup' ), $deadline );
		if ( $done ) {
			SwiftMigrate_Fs::delete( $this->dir( 'journal.jsonl' ) );
			$this->state['options']['keep_backup'] = false;
			$this->state['purged']                 = time();
			$this->save();
			$this->log( __( 'Rollback copy deleted.', 'swiftmigrate' ) );
		}
		return array( 'done' => $done );
	}

	public function abort( array $in ) {
		$this->open( isset( $in['id'] ) ? $in['id'] : '' );
		if ( in_array( $this->state['phase'], array( 'restoring', 'done' ), true ) && ( ! empty( $this->state['r']['jpos'] ) || ! empty( $this->state['r']['cpos'] ) ) ) {
			throw new RuntimeException( esc_html__( 'Files were already moved into place; use Rollback instead of Cancel.', 'swiftmigrate' ), 409 );
		}
		$this->discard_staging( microtime( true ) + SwiftMigrate_Settings::budget() );
		$this->state['phase'] = 'aborted';
		$this->save();
		$this->log( __( 'Migration cancelled; staging data removed.', 'swiftmigrate' ) );
		SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'destination', 'status' => 'cancelled' ) );
		return array( 'aborted' => true );
	}

	private function discard_staging( $deadline ) {
		foreach ( SwiftMigrate_DB::tables_like( self::TMP ) as $t ) {
			SwiftMigrate_DB::q( SwiftMigrate_DB::prepare( 'DROP TABLE IF EXISTS %i', $t ) );
		}
		SwiftMigrate_Storage::rrmdir_step( $this->dir( 'staging' ), $deadline );
	}

	public function status( array $in = array() ) {
		$id = isset( $in['id'] ) && $in['id'] ? $in['id'] : self::current_id();
		if ( ! $id ) {
			return array( 'phase' => 'idle' );
		}
		$this->open( $id, false );
		if ( empty( $this->state['id'] ) ) {
			return array( 'phase' => 'idle' );
		}
		$p          = $this->progress( 'done' === $this->state['phase'] );
		$p['phase'] = $this->state['phase'];
		$p['id']    = $this->id;
		$p['from']  = $this->state['source']['home'];
		$p['can_rollback'] = ! empty( $this->state['options']['keep_backup'] ) && in_array( $this->state['phase'], array( 'restoring', 'done', 'rolling_back' ), true );
		$p['log']   = SwiftMigrate_Logger::tail( $this->id, 30 );
		return $p;
	}
}
