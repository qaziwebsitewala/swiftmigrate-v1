<?php
/**
 * Source side of a migration.
 *
 * The work is split into independent, idempotent jobs stored in an append-only
 * job log (jobs.jsonl + fixed-width offset index for O(1) lookup):
 *   d = database chunk (schema, primary-key range or offset window)
 *   b = batch of small files packed into one request
 *   c = one chunk of a large file
 * The browser runs N jobs in parallel and checkpoints a done-bitmap, so a
 * migration can be paused, survive a closed tab, and resume exactly where it was.
 *
 * @package SwiftMigrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Source side of a migration.
 */
class SwiftMigrate_Source {

	const PAGE        = 50;      // Jobs per verification page.
	const STMT_BYTES  = 1048576; // Max bytes per INSERT statement (capped by max_allowed_packet).

	private $id;
	private $state;
	private $file;
	private $client;

	// ---------------------------------------------------------------- lifecycle

	public static function current_id() {
		$c = SwiftMigrate_Storage::read_json( SwiftMigrate_Storage::path( 'send-current.json' ) );
		return isset( $c['id'] ) ? $c['id'] : '';
	}

	public static function load( $id = null ) {
		$id = $id ? $id : self::current_id();
		if ( ! $id ) {
			throw new RuntimeException( esc_html__( 'No migration in progress.', 'swiftmigrate' ) );
		}
		$o        = new self();
		$o->id    = SwiftMigrate_Storage::sanitize_id( $id );
		$o->file  = SwiftMigrate_Storage::migration_dir( $o->id ) . '/source.json';
		$o->state = SwiftMigrate_Storage::read_json( $o->file );
		if ( empty( $o->state['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Migration state not found. Start a new migration.', 'swiftmigrate' ) );
		}
		return $o;
	}

	private function save() {
		$this->state['updated'] = time();
		SwiftMigrate_Storage::write_json( $this->file, $this->state );
	}

	private function client() {
		if ( ! $this->client ) {
			$urls         = ! empty( $this->state['urls'] ) ? $this->state['urls'] : array( $this->state['url'] );
			$this->client = new SwiftMigrate_Client( $urls, $this->state['secret'] );
		}
		return $this->client;
	}

	private function dir() {
		return SwiftMigrate_Storage::migration_dir( $this->id );
	}

	private function log( $msg, $level = 'info' ) {
		SwiftMigrate_Logger::log( $this->id, $msg, $level );
	}

	// ---------------------------------------------------------------- connect

	/** Validates the key against the destination and returns a pre-flight report. */
	public static function connect( $key ) {
		$k      = SwiftMigrate_Security::decode_key( $key );
		$client = new SwiftMigrate_Client( array( $k['url'], $k['fallback'] ), $k['secret'] );
		$peer   = $client->call( 'handshake', array( 'source' => self::site_info() ), 30 );
		$url    = $client->url();
		if ( untrailingslashit( $peer['home'] ) === untrailingslashit( home_url() ) ) {
			throw new RuntimeException( esc_html__( 'This key belongs to this same site. Generate the key on the destination site and paste it here, on the source.', 'swiftmigrate' ) );
		}
		SwiftMigrate_Storage::write_json(
			SwiftMigrate_Storage::path( 'send-connection.json' ),
			array(
				'key'    => $key,
				'url'    => $url,
				'urls'   => array_values( array_unique( array_filter( array( $url, $k['url'], $k['fallback'] ) ) ) ),
				'secret' => $k['secret'],
				'peer'   => $peer,
				'time'   => time(),
			)
		);
		return array(
			'peer'   => $peer,
			'local'  => self::site_info(),
			'direct' => $url === $k['url'],
			'checks' => self::preflight( $peer ),
		);
	}

	public static function site_info() {
		global $wpdb, $wp_version;
		$roots = SwiftMigrate_Paths::roots();
		return array(
			'home'            => home_url(),
			'siteurl'         => site_url(),
			'abspath'         => $roots['a'],
			'content_dir'     => $roots['c'],
			'prefix'          => $wpdb->prefix,
			'charset'         => $wpdb->charset,
			'wp'              => $wp_version,
			'php'             => PHP_VERSION,
			'mysql'           => $wpdb->db_version(),
			'multisite'       => is_multisite(),
			'plugin'          => plugin_basename( SWIFTMIGRATE_FILE ),
			'swiftmigrate'    => SWIFTMIGRATE_VERSION,
			'name'            => get_bloginfo( 'name' ),
			'db_bytes'        => self::db_size(),
		);
	}

	public static function db_size() {
		global $wpdb;
		$size = SwiftMigrate_DB::var_(
			SwiftMigrate_DB::prepare(
				'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
				SwiftMigrate_DB::like_escape( $wpdb->prefix ) . '%'
			)
		);
		return (float) $size;
	}

	/**
	 * Pre-flight report shown before a migration starts.
	 *
	 * @param array $peer Destination handshake data.
	 * @return array[] Each: level (ok|warn|error), label, detail.
	 */
	private static function preflight( array $peer ) {
		$checks = array();
		$add    = function ( $level, $label, $detail ) use ( &$checks ) {
			$checks[] = compact( 'level', 'label', 'detail' );
		};
		if ( is_multisite() || ! empty( $peer['multisite'] ) ) {
			$add( 'error', __( 'Multisite', 'swiftmigrate' ), __( 'Multisite networks are not supported. Both sites must be single-site installs.', 'swiftmigrate' ) );
		}
		if ( version_compare( $peer['php'], PHP_VERSION, '<' ) && version_compare( substr( $peer['php'], 0, 3 ), substr( PHP_VERSION, 0, 3 ), '<' ) ) {
			/* translators: 1: PHP version of the destination, 2: PHP version of this site. */
			$add( 'warn', __( 'PHP version', 'swiftmigrate' ), sprintf( __( 'Destination runs PHP %1$s, older than this site (%2$s). Plugins or themes may need a newer PHP.', 'swiftmigrate' ), $peer['php'], PHP_VERSION ) );
		} else {
			/* translators: %s: PHP version of the destination. */
			$add( 'ok', __( 'PHP version', 'swiftmigrate' ), sprintf( __( 'Destination PHP %s', 'swiftmigrate' ), $peer['php'] ) );
		}
		$https = 'https' === strtolower( (string) wp_parse_url( (string) $peer['home'], PHP_URL_SCHEME ) );
		if ( ! SwiftMigrate_Security::needs_encryption( $peer['home'] ) ) {
			$add( 'ok', __( 'Transport', 'swiftmigrate' ), __( 'HTTPS, every request signed with HMAC-SHA256.', 'swiftmigrate' ) );
		} elseif ( $https && SwiftMigrate_Security::can_encrypt() ) {
			$add( 'warn', __( 'Transport', 'swiftmigrate' ), __( 'HTTPS with certificate verification turned off (Settings). SwiftMigrate therefore also encrypts every request and response end-to-end with the migration key.', 'swiftmigrate' ) );
		} elseif ( SwiftMigrate_Security::can_encrypt() ) {
			$add( 'warn', __( 'Transport', 'swiftmigrate' ), __( 'The destination does not use HTTPS, so SwiftMigrate encrypts every request and response end-to-end with the migration key (XChaCha20-Poly1305) and signs it with HMAC-SHA256. No site data is sent in clear text. Enabling HTTPS on the destination is still recommended.', 'swiftmigrate' ) );
		} else {
			$add( 'error', __( 'Transport', 'swiftmigrate' ), __( 'The connection to the destination is not verified HTTPS and this server cannot encrypt the transfer itself. Enable HTTPS (with certificate verification) on the destination site.', 'swiftmigrate' ) );
		}
		if ( empty( $peer['zlib'] ) ) {
			$add( 'warn', __( 'Compression', 'swiftmigrate' ), __( 'Destination has no zlib; transfer will be uncompressed.', 'swiftmigrate' ) );
		}
		if ( ! empty( $peer['free'] ) ) {
			$need = self::db_size() * 2;
			/* translators: %s: free disk space, e.g. "12 GB". */
			$add( $peer['free'] > $need ? 'ok' : 'warn', __( 'Disk space', 'swiftmigrate' ), sprintf( __( '%s free on destination. File size is checked again after scanning.', 'swiftmigrate' ), size_format( $peer['free'] ) ) );
		}
		/* translators: 1: negotiated request size, 2: maximum request size of the destination. */
		$add( 'ok', __( 'Chunk size', 'swiftmigrate' ), sprintf( __( 'Negotiated %1$s per request (destination accepts up to %2$s).', 'swiftmigrate' ), size_format( self::negotiate_chunk( $peer ) ), size_format( $peer['post_max'] ) ) );
		return $checks;
	}

	public static function negotiate_chunk( array $peer ) {
		$want = (int) SwiftMigrate_Settings::get( 'chunk_mb' ) * MB_IN_BYTES;
		if ( ! empty( $peer['post_max'] ) ) {
			$want = min( $want, (int) ( $peer['post_max'] * 0.7 ) );
		}
		if ( ! empty( $peer['memory'] ) && $peer['memory'] > 0 ) {
			$want = min( $want, (int) ( $peer['memory'] / 6 ) );
		}
		$mine = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $mine > 0 ) {
			$want = min( $want, (int) ( $mine / 6 ) );
		}
		if ( SwiftMigrate_Settings::get( 'firewall_safe' ) ) {
			$want = (int) ( $want / 1.4 );
		}
		return max( 256 * KB_IN_BYTES, $want );
	}

	// ---------------------------------------------------------------- start

	public static function start( array $opts ) {
		global $wpdb;
		$conn = SwiftMigrate_Storage::read_json( SwiftMigrate_Storage::path( 'send-connection.json' ) );
		if ( empty( $conn['secret'] ) ) {
			throw new RuntimeException( esc_html__( 'Connect to a destination first.', 'swiftmigrate' ) );
		}
		if ( is_multisite() || ! empty( $conn['peer']['multisite'] ) ) {
			throw new RuntimeException( esc_html__( 'Multisite networks are not supported.', 'swiftmigrate' ) );
		}
		if ( SwiftMigrate_Security::needs_encryption( $conn['url'] ) && ! SwiftMigrate_Security::can_encrypt() ) {
			throw new RuntimeException( esc_html__( 'The connection to the destination is not verified HTTPS and this server cannot encrypt the transfer itself. Enable HTTPS (with certificate verification) on the destination site.', 'swiftmigrate' ) );
		}

		$id   = strtolower( gmdate( 'ymdHis' ) . wp_generate_password( 6, false, false ) );
		$id   = SwiftMigrate_Storage::sanitize_id( $id );
		$peer = $conn['peer'];

		$o        = new self();
		$o->id    = $id;
		$o->file  = SwiftMigrate_Storage::migration_dir( $id ) . '/source.json';
		$o->state = array(
			'id'          => $id,
			'role'        => 'source',
			'created'     => time(),
			'phase'       => 'scan',
			'url'         => $conn['url'],
			'urls'        => isset( $conn['urls'] ) ? $conn['urls'] : array( $conn['url'] ),
			'secret'      => $conn['secret'],
			'peer'        => $peer,
			'opts'        => array(
				'db'        => ! empty( $opts['db'] ),
				'uploads'   => ! empty( $opts['uploads'] ),
				'themes'    => ! empty( $opts['themes'] ),
				'plugins'   => ! empty( $opts['plugins'] ),
				'core'      => ! empty( $opts['core'] ),
				'other'     => ! empty( $opts['other'] ),
				'autorestore' => ! empty( $opts['autorestore'] ),
				'pairs'     => isset( $opts['pairs'] ) ? (array) $opts['pairs'] : array(),
			),
			'chunk'       => self::negotiate_chunk( $peer ),
			'stmt'        => min( self::STMT_BYTES, max( 65536, (int) ( ( isset( $peer['packet'] ) ? $peer['packet'] : 4194304 ) / 2 ) ) ),
			'gzip'        => SwiftMigrate_Settings::get( 'compress' ) && function_exists( 'gzencode' ) && ! empty( $peer['zlib'] ),
			'jobs'        => 0,
			'jobs_bytes'  => 0,
			'files_total' => 0,
			'bytes_total' => 0,
			'db_tables'   => 0,
			'skipped'     => array(),
			'bytes_done'  => 0,
			'files_done'  => 0,
			'verify_pass' => 0,
		);
		$o->client()->call(
			'init',
			array(
				'id'      => $id,
				'source'  => self::site_info(),
				'options' => array(
					'keep_backup' => (bool) SwiftMigrate_Settings::get( 'keep_backup' ),
					'pairs'       => $o->state['opts']['pairs'],
					'db'          => $o->state['opts']['db'],
				),
			)
		);

		SwiftMigrate_Storage::write_json( SwiftMigrate_Storage::path( 'send-current.json' ), array( 'id' => $id ) );
		SwiftMigrate_Fs::delete( $o->dir() . '/jobs.jsonl' );
		SwiftMigrate_Fs::delete( $o->dir() . '/jobs.idx' );
		SwiftMigrate_Fs::delete( $o->dir() . '/done.bin' );

		/* translators: 1: migration ID, 2: address of this site, 3: address of the destination. */
		$o->log( sprintf( __( 'Migration %1$s started: %2$s → %3$s', 'swiftmigrate' ), $id, home_url(), $peer['home'] ) );
		/* translators: 1: request size, 2: SQL statement size, 3: "on" or "off", 4: number of parallel streams. */
		$o->log( sprintf( __( 'Chunk %1$s, statement %2$s, gzip %3$s, workers %4$d', 'swiftmigrate' ), size_format( $o->state['chunk'] ), size_format( $o->state['stmt'] ), $o->state['gzip'] ? __( 'on', 'swiftmigrate' ) : __( 'off', 'swiftmigrate' ), SwiftMigrate_Settings::get( 'workers' ) ) );
		if ( SwiftMigrate_Security::needs_encryption( $conn['url'] ) ) {
			$o->log( __( 'The connection is not verified HTTPS: all transfer requests are encrypted end-to-end with the migration key.', 'swiftmigrate' ) );
		}

		if ( $o->state['opts']['db'] ) {
			$o->plan_database();
		}
		$o->state['scan'] = array(
			'stack' => array(),
			'batch' => array(),
			'bbyt'  => 0,
		);
		// Stack is LIFO: push abs first so wp-content (the bulk) is scanned first.
		if ( $o->state['opts']['core'] || $o->state['opts']['other'] ) {
			$o->state['scan']['stack'][] = array( 'a', '' );
		}
		$o->state['scan']['stack'][] = array( 'c', '' );
		$o->save();

		SwiftMigrate_Logger::history_add(
			array(
				'id'      => $id,
				'role'    => 'source',
				'peer'    => $peer['home'],
				'started' => time(),
				'status'  => 'running',
			)
		);
		return $o->status();
	}

	// ---------------------------------------------------------------- job log

	private function job_append( array $job ) {
		$line = wp_json_encode( $job, JSON_UNESCAPED_SLASHES ) . "\n";
		$jf   = $this->dir() . '/jobs.jsonl';
		$off  = (int) $this->state['jobs_bytes'];
		SwiftMigrate_Fs::append( $jf, $line );
		SwiftMigrate_Fs::append( $this->dir() . '/jobs.idx', pack( 'N2', $off & 0xFFFFFFFF, (int) floor( $off / 4294967296 ) ) );
		$this->state['jobs_bytes'] = $off + strlen( $line );
		$this->state['jobs']++;
	}

	/** Discards jobs appended after the last saved state (crash mid-step). */
	private function job_log_truncate() {
		$jf = $this->dir() . '/jobs.jsonl';
		$ix = $this->dir() . '/jobs.idx';
		clearstatcache();
		if ( file_exists( $jf ) && filesize( $jf ) > $this->state['jobs_bytes'] ) {
			$h = SwiftMigrate_Fs::open( $jf, 'r+' );
			if ( $h ) {
				ftruncate( $h, $this->state['jobs_bytes'] );
				SwiftMigrate_Fs::close( $h );
			}
		}
		if ( file_exists( $ix ) && filesize( $ix ) > $this->state['jobs'] * 8 ) {
			$h = SwiftMigrate_Fs::open( $ix, 'r+' );
			if ( $h ) {
				ftruncate( $h, $this->state['jobs'] * 8 );
				SwiftMigrate_Fs::close( $h );
			}
		}
	}

	public function job( $n ) {
		$n = (int) $n;
		if ( $n < 0 || $n >= $this->state['jobs'] ) {
			throw new RuntimeException( esc_html__( 'Job out of range.', 'swiftmigrate' ) );
		}
		$ix = SwiftMigrate_Fs::open( $this->dir() . '/jobs.idx', 'rb' );
		$jf = SwiftMigrate_Fs::open( $this->dir() . '/jobs.jsonl', 'rb' );
		if ( ! $ix || ! $jf ) {
			SwiftMigrate_Fs::close( $ix );
			SwiftMigrate_Fs::close( $jf );
			throw new RuntimeException( esc_html__( 'Job log is missing; start a new migration.', 'swiftmigrate' ) );
		}
		fseek( $ix, $n * 8 );
		$p = unpack( 'Nlo/Nhi', (string) SwiftMigrate_Fs::read( $ix, 8 ) );
		SwiftMigrate_Fs::close( $ix );
		$off = is_array( $p ) ? $p['lo'] + $p['hi'] * 4294967296 : 0;
		fseek( $jf, (int) $off );
		$line = fgets( $jf );
		SwiftMigrate_Fs::close( $jf );
		$job = json_decode( (string) $line, true );
		if ( ! is_array( $job ) ) {
			throw new RuntimeException( esc_html__( 'Job log is damaged; start a new migration.', 'swiftmigrate' ) );
		}
		return $job;
	}

	// ---------------------------------------------------------------- database plan

	private function plan_database() {
		global $wpdb;
		$skip   = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) SwiftMigrate_Settings::get( 'skip_tables' ) ) ) );
		$tables = SwiftMigrate_DB::tables_like( $wpdb->prefix );
		$rows   = (int) SwiftMigrate_Settings::get( 'db_rows' );
		$ti     = 0;
		$est    = array();
		foreach ( SwiftMigrate_DB::rows( 'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' ) as $r ) {
			$est[ $r['TABLE_NAME'] ] = (int) $r['TABLE_ROWS'];
		}

		foreach ( $tables as $table ) {
			$suffix = substr( $table, strlen( $wpdb->prefix ) );
			if ( in_array( $table, $skip, true ) || in_array( $suffix, $skip, true ) ) {
				/* translators: %s: database table name. */
				$this->log( sprintf( __( 'Skipping table %s (excluded in Settings)', 'swiftmigrate' ), $table ) );
				continue;
			}
			$ti++;
			$this->job_append( array( 't' => 'd', 'm' => 's', 'tb' => $table, 'ti' => $ti, 'ci' => 0 ) );

			$pk = $this->numeric_pk( $table );
			$ci = 1;
			if ( $pk ) {
				$mm = SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( 'SELECT MIN(%i) a, MAX(%i) b FROM %i', $pk, $pk, $table ) );
				if ( null === $mm[0]['a'] ) {
					$this->job_append( array( 't' => 'd', 'm' => 'r', 'tb' => $table, 'ti' => $ti, 'ci' => $ci, 'pk' => $pk, 'a' => null, 'b' => null ) );
					continue;
				}
				$min    = (float) $mm[0]['a'];
				$max    = (float) $mm[0]['b'];
				$n      = max( 1, isset( $est[ $table ] ) ? $est[ $table ] : 1 );
				$chunks = max( 1, (int) ceil( $n / $rows ) );
				$step   = max( $rows, (int) ceil( ( $max - $min + 1 ) / $chunks ) );
				// First range is open below, last range open above: rows inserted
				// during the migration at either end are never lost.
				for ( $a = $min; $a <= $max; $a += $step ) {
					$b = $a + $step;
					$this->job_append(
						array(
							't'  => 'd',
							'm'  => 'r',
							'tb' => $table,
							'ti' => $ti,
							'ci' => $ci++,
							'pk' => $pk,
							'a'  => $a === $min ? null : self::num( $a ),
							'b'  => $b > $max ? null : self::num( $b ),
						)
					);
				}
			} else {
				$count  = (int) SwiftMigrate_DB::var_( SwiftMigrate_DB::prepare( 'SELECT COUNT(*) FROM %i', $table ) );
				$chunks = max( 1, (int) ceil( $count / $rows ) );
				for ( $i = 0; $i < $chunks; $i++ ) {
					$this->job_append(
						array(
							't'   => 'd',
							'm'   => 'o',
							'tb'  => $table,
							'ti'  => $ti,
							'ci'  => $ci++,
							'off' => $i * $rows,
							'lim' => $i === $chunks - 1 ? 0 : $rows, // Last window unbounded.
						)
					);
				}
			}
		}
		$this->state['db_tables'] = $ti;
		/* translators: 1: number of tables, 2: number of transfer jobs. */
		$this->log( sprintf( __( 'Database planned: %1$d tables, %2$d jobs', 'swiftmigrate' ), $ti, $this->state['jobs'] ) );
	}

	private static function num( $f ) {
		return sprintf( '%.0f', $f );
	}

	private function numeric_pk( $table ) {
		$keys = SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ) );
		if ( 1 !== count( $keys ) ) {
			return null;
		}
		$col = $keys[0]['Column_name'];
		foreach ( SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( 'SHOW COLUMNS FROM %i', $table ) ) as $c ) {
			if ( $c['Field'] === $col && preg_match( '/int/i', $c['Type'] ) ) {
				return $col;
			}
		}
		return null;
	}

	// ---------------------------------------------------------------- file scan

	private function roots() {
		return SwiftMigrate_Paths::roots();
	}

	private function exclude_rules() {
		$roots   = $this->roots();
		$content = $roots['c'];
		$abs     = $roots['a'];
		$rules   = array(
			'c' => array(),
			'a' => array( 'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config', '.maintenance' ),
			'g' => array(),
		);
		// Never send SwiftMigrate itself or its private working folder.
		foreach ( array( SwiftMigrate_Storage::parent_dir(), plugin_dir_path( SWIFTMIGRATE_FILE ) ) as $own ) {
			foreach ( array( 'c', 'a' ) as $root ) {
				$rel = SwiftMigrate_Paths::relative( $own, $roots[ $root ] );
				if ( '' !== $rel ) {
					$rules[ $root ][] = $rel;
				}
			}
		}
		$content_rel = SwiftMigrate_Paths::relative( $content, $abs );
		if ( '' !== $content_rel ) {
			$rules['a'][] = $content_rel;
		}
		$o = $this->state['opts'];
		if ( ! $o['uploads'] ) {
			$uploads_rel  = SwiftMigrate_Paths::relative( SwiftMigrate_Paths::uploads_dir(), $content );
			$rules['c'][] = '' !== $uploads_rel ? $uploads_rel : 'uploads';
		}
		if ( ! $o['themes'] ) {
			$rules['c'][] = 'themes';
		}
		if ( ! $o['plugins'] ) {
			$rules['c'][] = 'plugins';
			$rules['c'][] = 'mu-plugins';
		}
		foreach ( SwiftMigrate_Settings::excludes() as $line ) {
			if ( preg_match( '/^(content|abs):(.+)$/', $line, $m ) ) {
				$rules[ 'content' === $m[1] ? 'c' : 'a' ][] = trim( $m[2], '/' );
			} else {
				$rules['g'][] = $line;
			}
		}
		return $rules;
	}

	private function excluded( $root, $rel, $name, $rules, $is_dir ) {
		foreach ( $rules[ $root ] as $r ) {
			if ( $rel === $r || 0 === strpos( $rel, $r . '/' ) ) {
				return true;
			}
		}
		foreach ( $rules['g'] as $g ) {
			if ( fnmatch( $g, $rel ) || fnmatch( $g, $name ) ) {
				return true;
			}
		}
		if ( 'a' === $root ) {
			$o     = $this->state['opts'];
			$core  = ( 0 === strpos( $rel, 'wp-admin' ) || 0 === strpos( $rel, 'wp-includes' ) || ( false === strpos( $rel, '/' ) && preg_match( '/^(wp-[a-z\-]+\.php|index\.php|xmlrpc\.php|license\.txt|readme\.html)$/', $rel ) ) );
			if ( $core && ! $o['core'] ) {
				return true;
			}
			if ( ! $core && ! $o['other'] ) {
				return true;
			}
		}
		return false;
	}

	/** Time-sliced, resumable directory walk. */
	public function scan_step() {
		if ( 'scan' !== $this->state['phase'] ) {
			return $this->status();
		}
		$this->job_log_truncate();
		$deadline = microtime( true ) + SwiftMigrate_Settings::budget();
		$roots    = $this->roots();
		$rules    = $this->exclude_rules();
		$chunk    = (int) $this->state['chunk'];
		$maxfiles = (int) SwiftMigrate_Settings::get( 'batch_files' );
		$scan     = &$this->state['scan'];

		while ( $scan['stack'] && microtime( true ) < $deadline ) {
			list( $root, $rel ) = array_pop( $scan['stack'] );
			$dir     = $roots[ $root ] . ( '' === $rel ? '' : '/' . $rel );
			$entries = is_readable( $dir ) ? scandir( $dir ) : false;
			if ( false === $entries ) {
				/* translators: %s: folder path. */
				$this->skip( sprintf( __( 'Unreadable directory: %s', 'swiftmigrate' ), "$root:$rel" ) );
				continue;
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$r    = '' === $rel ? $name : $rel . '/' . $name;
				$full = $dir . '/' . $name;
				if ( is_dir( $full ) ) {
					if ( is_link( $full ) ) {
						/* translators: %s: folder path. */
						$this->skip( sprintf( __( 'Symlinked directory not followed: %s', 'swiftmigrate' ), "$root:$r" ) );
						continue;
					}
					if ( $this->excluded( $root, $r, $name, $rules, true ) ) {
						continue;
					}
					if ( 'a' === $root && file_exists( $full . '/wp-config.php' ) && file_exists( $full . '/wp-includes' ) ) {
						/* translators: %s: folder path. */
						$this->log( sprintf( __( 'Skipping %s: it is a separate WordPress install', 'swiftmigrate' ), $r ) );
						continue;
					}
					$scan['stack'][] = array( $root, $r );
					continue;
				}
				if ( ! is_file( $full ) || $this->excluded( $root, $r, $name, $rules, false ) ) {
					continue;
				}
				if ( ! is_readable( $full ) ) {
					/* translators: %s: file path. */
					$this->skip( sprintf( __( 'Unreadable file: %s', 'swiftmigrate' ), "$root:$r" ) );
					continue;
				}
				$size = (int) filesize( $full );
				$this->state['files_total']++;
				$this->state['bytes_total'] += $size;

				if ( $size > $chunk ) {
					$n = (int) ceil( $size / $chunk );
					for ( $i = 0; $i < $n; $i++ ) {
						$this->job_append(
							array(
								't' => 'c',
								'r' => $root,
								'p' => $r,
								's' => $size,
								'o' => $i * $chunk,
								'l' => min( $chunk, $size - $i * $chunk ),
								'i' => $i,
								'n' => $n,
							)
						);
					}
					continue;
				}
				if ( $scan['batch'] && ( $scan['bbyt'] + $size > $chunk || count( $scan['batch'] ) >= $maxfiles ) ) {
					$this->flush_batch();
				}
				$scan['batch'][] = array( $root, $r, $size );
				$scan['bbyt']   += $size;
			}
		}

		if ( ! $scan['stack'] ) {
			$this->flush_batch();
			$this->state['phase'] = 'transfer';
			unset( $this->state['scan'] );
			/* translators: 1: number of files, 2: total size, 3: number of transfer jobs. */
			$this->log( sprintf( __( 'Scan complete: %1$s files, %2$s, %3$d jobs total', 'swiftmigrate' ), number_format_i18n( $this->state['files_total'] ), size_format( $this->state['bytes_total'] ), $this->state['jobs'] ) );
			$need = ( $this->state['bytes_total'] + self::db_size() ) * ( SwiftMigrate_Settings::get( 'keep_backup' ) ? 2 : 1.1 );
			if ( ! empty( $this->state['peer']['free'] ) && $this->state['peer']['free'] < $need ) {
				$this->state['warning'] = SwiftMigrate_Settings::get( 'keep_backup' )
					/* translators: 1: free disk space on the destination, 2: space the migration may need. */
					? sprintf( __( 'The destination reports %1$s free, but this migration may need about %2$s (staging + rollback copy). Free up space or turn off "Keep a rollback copy" before continuing.', 'swiftmigrate' ), size_format( $this->state['peer']['free'] ), size_format( $need ) )
					/* translators: 1: free disk space on the destination, 2: space the migration may need. */
					: sprintf( __( 'The destination reports %1$s free, but this migration may need about %2$s (staging). Free up space before continuing.', 'swiftmigrate' ), size_format( $this->state['peer']['free'] ), size_format( $need ) );
				$this->log( $this->state['warning'], 'warn' );
			}
			SwiftMigrate_Fs::put( $this->dir() . '/done.bin', str_repeat( "\0", (int) ceil( max( 1, $this->state['jobs'] ) / 8 ) ) );
		}
		$this->save();
		return $this->status();
	}

	private function flush_batch() {
		$scan = &$this->state['scan'];
		if ( $scan['batch'] ) {
			$this->job_append( array( 't' => 'b', 'f' => $scan['batch'] ) );
		}
		$scan['batch'] = array();
		$scan['bbyt']  = 0;
	}

	private function skip( $msg ) {
		if ( count( $this->state['skipped'] ) < 200 ) {
			$this->state['skipped'][] = $msg;
		}
		$this->log( $msg, 'warn' );
	}

	// ---------------------------------------------------------------- run a job

	/**
	 * Executes one job. Database jobs over very large key ranges can span several
	 * requests: they return a cursor and the browser calls again with it.
	 */
	public function run_job( $n, $cursor = null ) {
		$job   = $this->job( $n );
		$roots = $this->roots();
		switch ( $job['t'] ) {
			case 'b':
				return $this->run_batch( $n, $job, $roots );
			case 'c':
				return $this->run_chunk( $n, $job, $roots );
			case 'd':
				return $this->run_db( $n, $job, is_array( $cursor ) ? $cursor : null );
		}
		throw new RuntimeException( esc_html__( 'Unknown job type.', 'swiftmigrate' ) );
	}

	private function run_batch( $n, $job, $roots ) {
		$items   = array();
		$payload = '';
		$compr   = 0;
		foreach ( $job['f'] as $f ) {
			list( $root, $rel ) = $f;
			$file = $roots[ $root ] . '/' . $rel;
			$data = SwiftMigrate_Fs::get( $file );
			if ( false === $data ) {
				$items[] = array( 'r' => $root, 'p' => $rel, 'x' => 1 );
				/* translators: %s: file path. */
				$this->log( sprintf( __( 'File vanished or unreadable during transfer: %s', 'swiftmigrate' ), "$root:$rel" ), 'warn' );
				continue;
			}
			$items[]  = array( 'r' => $root, 'p' => $rel, 'l' => strlen( $data ), 'h' => sha1( $data ) );
			$payload .= $data;
			if ( SwiftMigrate_Packer::compressible( $rel ) ) {
				$compr += strlen( $data );
			}
		}
		$gz = $this->state['gzip'] && ( strlen( $payload ) < 4096 || $compr > strlen( $payload ) * 0.3 );
		list( $body, $gzd ) = SwiftMigrate_Packer::pack( array( 't' => 'b', 'job' => $n, 'items' => $items ), $payload, $gz );
		$this->client()->raw( 'put', $body, 'application/octet-stream', array( 'X-SwiftMigrate-Gz' => $gzd ? '1' : '0', 'X-SwiftMigrate-Mig' => $this->id ) );
		return array(
			'done'  => true,
			'bytes' => strlen( $payload ),
			'wire'  => strlen( $body ),
			'files' => count( $job['f'] ),
		);
	}

	private function run_chunk( $n, $job, $roots ) {
		$path = $roots[ $job['r'] ] . '/' . $job['p'];
		if ( ! file_exists( $path ) ) {
			if ( 0 === (int) $job['i'] ) {
				/* translators: %s: file path. */
				$this->log( sprintf( __( 'File deleted on source since scan, skipped: %s', 'swiftmigrate' ), $job['r'] . ':' . $job['p'] ), 'warn' );
			}
			return array( 'done' => true, 'bytes' => 0, 'wire' => 0, 'files' => 0 );
		}
		$fh   = SwiftMigrate_Fs::open( $path, 'rb' );
		if ( ! $fh ) {
			/* translators: %s: file path. */
			throw new RuntimeException( esc_html( sprintf( __( 'Cannot open %s for reading.', 'swiftmigrate' ), $job['p'] ) ) );
		}
		fseek( $fh, $job['o'] );
		$data = '';
		while ( strlen( $data ) < $job['l'] && ! feof( $fh ) ) {
			$part = SwiftMigrate_Fs::read( $fh, $job['l'] - strlen( $data ) );
			if ( false === $part || '' === $part ) {
				break;
			}
			$data .= $part;
		}
		SwiftMigrate_Fs::close( $fh );
		$header = array(
			't'   => 'c',
			'job' => $n,
			'r'   => $job['r'],
			'p'   => $job['p'],
			'o'   => $job['o'],
			'l'   => strlen( $data ),
			'i'   => $job['i'],
			'n'   => $job['n'],
			's'   => $job['s'],
			'h'   => sha1( $data ),
		);
		$gz = $this->state['gzip'] && SwiftMigrate_Packer::compressible( $job['p'] );
		list( $body, $gzd ) = SwiftMigrate_Packer::pack( $header, $data, $gz );
		$this->client()->raw( 'put', $body, 'application/octet-stream', array( 'X-SwiftMigrate-Gz' => $gzd ? '1' : '0', 'X-SwiftMigrate-Mig' => $this->id ) );
		return array(
			'done'  => true,
			'bytes' => strlen( $data ),
			'wire'  => strlen( $body ),
			'files' => 0 === (int) $job['i'] ? 1 : 0,
		);
	}

	// ---------------------------------------------------------------- database export

	private function run_db( $n, $job, $cursor ) {
		global $wpdb;
		$table    = $job['tb'];
		$deadline = microtime( true ) + SwiftMigrate_Settings::budget();
		$part     = $cursor ? (int) $cursor['part'] : 0;
		$sent     = 0;
		$wire     = 0;

		if ( 's' === $job['m'] ) {
			$row    = SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( 'SHOW CREATE TABLE %i', $table ) );
			$create = $row[0]['Create Table'];
			$pfx    = preg_quote( $wpdb->prefix, '/' );
			$create = preg_replace( '/^CREATE TABLE `' . $pfx . '/', 'CREATE TABLE `' . SwiftMigrate_DB::PLACEHOLDER, $create );
			$create = preg_replace( '/REFERENCES `' . $pfx . '/', 'REFERENCES `' . SwiftMigrate_DB::PLACEHOLDER, $create );
			$name   = SwiftMigrate_DB::PLACEHOLDER . substr( $table, strlen( $wpdb->prefix ) );
			$sql    = 'DROP TABLE IF EXISTS ' . SwiftMigrate_DB::ident( $name ) . SwiftMigrate_DB::EOS . $create . SwiftMigrate_DB::EOS;
			$wire  += $this->send_db_part( $n, $job, 0, $sql, true );
			return array( 'done' => true, 'bytes' => strlen( $sql ), 'wire' => $wire, 'files' => 0 );
		}

		$types  = $this->insert_columns( $table );
		$cols   = array_keys( $types );
		$select = implode( ',', array_map( array( 'SwiftMigrate_DB', 'ident' ), $cols ) );
		$reads  = implode( ',', array_map( array( __CLASS__, 'select_expr' ), $cols, array_values( $types ) ) );
		$target = SwiftMigrate_DB::ident( SwiftMigrate_DB::PLACEHOLDER . substr( $table, strlen( $wpdb->prefix ) ) );
		$head   = 'INSERT INTO ' . $target . ' (' . $select . ') VALUES ';
		$filter = $this->row_filter( $table );
		$sub    = 1000;
		$buf    = '';
		$stmt   = '';
		$limit  = (int) $this->state['chunk'];
		$slimit = (int) $this->state['stmt'];

		// Cursor state.
		$last     = $cursor && array_key_exists( 'last', $cursor ) ? $cursor['last'] : null;
		$consumed = $cursor ? (int) $cursor['consumed'] : 0;
		$pkorder  = '';
		if ( 'o' === $job['m'] ) {
			$pkcols = wp_list_pluck( SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ) ), 'Column_name' );
			if ( $pkcols ) {
				$pkorder = ' ORDER BY ' . implode( ',', array_map( array( 'SwiftMigrate_DB', 'ident' ), $pkcols ) );
			}
		}

		while ( true ) {
			if ( 'r' === $job['m'] ) {
				$pk    = SwiftMigrate_DB::ident( $job['pk'] );
				$where = array();
				if ( null !== $last ) {
					$where[] = $pk . ' > ' . ( preg_match( '/^-?\d+$/', (string) $last ) ? $last : self::num( $last ) );
				} elseif ( null !== $job['a'] ) {
					$where[] = $pk . ' >= ' . $job['a'];
				}
				if ( null !== $job['b'] ) {
					$where[] = $pk . ' < ' . $job['b'];
				}
				if ( $filter ) {
					$where[] = $filter;
				}
				$sql = 'SELECT ' . $reads . ', ' . $pk . ' AS `__smg_pk` FROM ' . SwiftMigrate_DB::ident( $table )
					. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY ' . $pk . ' LIMIT ' . $sub;
			} else {
				$take = $sub;
				if ( $job['lim'] > 0 ) {
					$take = min( $sub, $job['lim'] - $consumed );
				}
				if ( $take <= 0 ) {
					break;
				}
				$sql = 'SELECT ' . $reads . ' FROM ' . SwiftMigrate_DB::ident( $table ) . ( $filter ? ' WHERE ' . $filter : '' )
					. $pkorder . ' LIMIT ' . $take . ' OFFSET ' . ( $job['off'] + $consumed );
			}

			list( $rows, $hex, $num ) = $this->fetch( $sql, count( $cols ) );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				if ( 'r' === $job['m'] ) {
					$last = array_pop( $row );
				}
				$vals = array();
				foreach ( $row as $i => $v ) {
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( $hex[ $i ] ) {
						$vals[] = '' === $v ? "''" : '0x' . bin2hex( $v );
					} elseif ( $num[ $i ] && is_numeric( $v ) ) {
						$vals[] = $v;
					} else {
						$vals[] = "'" . SwiftMigrate_DB::esc( $v ) . "'";
					}
				}
				$tuple = '(' . implode( ',', $vals ) . ')';
				if ( '' !== $stmt && strlen( $stmt ) + strlen( $tuple ) + 1 > $slimit ) {
					$buf .= $stmt . SwiftMigrate_DB::EOS;
					$stmt = '';
				}
				$stmt .= ( '' === $stmt ? $head : ',' ) . $tuple;
			}
			$consumed += count( $rows );

			if ( strlen( $buf ) + strlen( $stmt ) >= $limit ) {
				$buf  .= $stmt . SwiftMigrate_DB::EOS;
				$stmt  = '';
				$wire += $this->send_db_part( $n, $job, $part, $buf, false );
				$sent += strlen( $buf );
				$buf   = '';
				$part++;
				if ( microtime( true ) > $deadline ) {
					return array(
						'done'   => false,
						'cursor' => array( 'part' => $part, 'last' => $last, 'consumed' => $consumed ),
						'bytes'  => $sent,
						'wire'   => $wire,
						'files'  => 0,
					);
				}
			}
			if ( count( $rows ) < ( 'r' === $job['m'] ? $sub : $take ) ) {
				break;
			}
		}
		if ( '' !== $stmt ) {
			$buf .= $stmt . SwiftMigrate_DB::EOS;
		}
		$wire += $this->send_db_part( $n, $job, $part, $buf, true );
		$sent += strlen( $buf );
		return array( 'done' => true, 'bytes' => $sent, 'wire' => $wire, 'files' => 0 );
	}

	private function send_db_part( $n, $job, $part, $sql, $last ) {
		$header = array(
			't'    => 'd',
			'job'  => $n,
			'ti'   => (int) $job['ti'],
			'ci'   => (int) $job['ci'],
			'part' => (int) $part,
			'last' => (bool) $last,
			'h'    => sha1( $sql ),
		);
		list( $body, $gzd ) = SwiftMigrate_Packer::pack( $header, $sql, $this->state['gzip'] );
		$this->client()->raw( 'put', $body, 'application/octet-stream', array( 'X-SwiftMigrate-Gz' => $gzd ? '1' : '0', 'X-SwiftMigrate-Mig' => $this->id ) );
		return strlen( $body );
	}

	/**
	 * Non-generated columns, in table order, with their SQL types.
	 *
	 * @param string $table Table name.
	 * @return array<string, string> Column name => type.
	 */
	private function insert_columns( $table ) {
		$out = array();
		foreach ( SwiftMigrate_DB::rows( SwiftMigrate_DB::prepare( 'SHOW COLUMNS FROM %i', $table ) ) as $c ) {
			if ( isset( $c['Extra'] ) && false !== stripos( $c['Extra'], 'GENERATED' ) ) {
				continue;
			}
			$out[ $c['Field'] ] = strtolower( (string) $c['Type'] );
		}
		return $out;
	}

	/**
	 * SELECT expression for a column. BIT columns are read as numbers
	 * (col+0): PHP drivers disagree on how raw BIT values are returned.
	 *
	 * @param string $name Column.
	 * @param string $type SQL type.
	 * @return string
	 */
	private static function select_expr( $name, $type ) {
		return 0 === strpos( $type, 'bit' ) ? '(' . SwiftMigrate_DB::ident( $name ) . '+0)' : SwiftMigrate_DB::ident( $name );
	}

	private function row_filter( $table ) {
		global $wpdb;
		$suffix = substr( $table, strlen( $wpdb->prefix ) );
		if ( 'options' === $suffix && SwiftMigrate_Settings::get( 'skip_transients' ) ) {
			return "`option_name` NOT LIKE '\\\\_transient\\\\_%' AND `option_name` NOT LIKE '\\\\_site\\\\_transient\\\\_%'";
		}
		if ( 'comments' === $suffix && SwiftMigrate_Settings::get( 'skip_spam' ) ) {
			// Spam and trashed comments (the setting's label promises both).
			return "`comment_approved` NOT IN ('spam', 'trash')";
		}
		if ( 'posts' === $suffix && SwiftMigrate_Settings::get( 'skip_revisions' ) ) {
			return "`post_type` <> 'revision'";
		}
		return '';
	}

	/**
	 * Fetches rows plus per-column flags: [rows, hex-encode?, numeric?].
	 *
	 * @param string $sql   SELECT statement built from identifiers of this site's own tables.
	 * @param int    $ncols Number of selected data columns.
	 * @return array
	 */
	private function fetch( $sql, $ncols ) {
		$hex = array_fill( 0, $ncols + 1, false );
		$num = array_fill( 0, $ncols + 1, false );
		list( $rows, $types, $charsets ) = SwiftMigrate_DB::rows_with_fields( $sql );
		foreach ( $types as $i => $t ) {
			// charsetnr 63 = binary charset. Only string/blob types are real binary data
			// (numbers and dates also report 63). BIT (16) is always sent as hex.
			$t         = (int) $t;
			$cs        = isset( $charsets[ $i ] ) ? (int) $charsets[ $i ] : 0;
			$hex[ $i ] = 16 === $t || ( 63 === $cs && in_array( $t, array( 15, 249, 250, 251, 252, 253, 254, 255 ), true ) );
			$num[ $i ] = in_array( $t, array( 0, 1, 2, 3, 4, 5, 8, 9, 246 ), true );
		}
		return array( $rows, $hex, $num );
	}

	// ---------------------------------------------------------------- progress

	/** Browser sends its done-bitmap and counters; persisted so a closed tab can resume. */
	public function checkpoint( $bitmap_b64, $bytes, $files ) {
		$bin = base64_decode( (string) $bitmap_b64, true );
		if ( false !== $bin && strlen( $bin ) === (int) ceil( max( 1, $this->state['jobs'] ) / 8 ) ) {
			SwiftMigrate_Fs::put( $this->dir() . '/done.bin', $bin, true );
		}
		$this->state['bytes_done'] = max( 0, (float) $bytes );
		$this->state['files_done'] = max( 0, (int) $files );
		$this->save();
	}

	public function bitmap() {
		$f = $this->dir() . '/done.bin';
		$bin = SwiftMigrate_Fs::get( $f );
		return false === $bin ? '' : base64_encode( $bin ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary progress bitmap sent to the admin screen as text.
	}

	public function set_phase( $phase ) {
		$this->state['phase'] = $phase;
		/* translators: %s: internal migration phase. */
		$this->log( sprintf( __( 'Phase: %s', 'swiftmigrate' ), $phase ) );
		$this->save();
	}

	// ---------------------------------------------------------------- verify

	/**
	 * Asks the destination to confirm every item of the given jobs is present,
	 * complete and the right size. Returns job numbers that must be sent again.
	 */
	public function verify( array $ids ) {
		$roots   = $this->roots();
		$entries = array();
		foreach ( $ids as $n ) {
			$job = $this->job( $n );
			if ( 'b' === $job['t'] ) {
				$items = array();
				foreach ( $job['f'] as $f ) {
					$path = $roots[ $f[0] ] . '/' . $f[1];
					$size = is_file( $path ) ? filesize( $path ) : false;
					if ( false === $size ) {
						continue; // Deleted on source since scan: nothing to verify.
					}
					$items[] = array( $f[0], $f[1], $size );
				}
				$entries[] = array( 'j' => $n, 't' => 'b', 'items' => $items );
			} elseif ( 'c' === $job['t'] ) {
				if ( ! file_exists( $roots[ $job['r'] ] . '/' . $job['p'] ) ) {
					continue; // Deleted on source since scan.
				}
				$entries[] = array( 'j' => $n, 't' => 'c', 'r' => $job['r'], 'p' => $job['p'], 'i' => $job['i'], 'n' => $job['n'] );
			} else {
				$entries[] = array( 'j' => $n, 't' => 'd', 'ti' => $job['ti'], 'ci' => $job['ci'] );
			}
		}
		$res = $this->client()->call( 'verify', array( 'id' => $this->id, 'entries' => $entries ) );
		return array_map( 'intval', (array) $res['bad'] );
	}

	public function verify_page( $page ) {
		$from = $page * self::PAGE;
		$to   = min( $this->state['jobs'], $from + self::PAGE );
		return $this->verify( range( $from, max( $from, $to - 1 ) ) );
	}

	public function verify_pass() {
		$this->state['verify_pass']++;
		$this->save();
		return $this->state['verify_pass'];
	}

	// ---------------------------------------------------------------- remote steps

	public function remote( $action, array $data = array() ) {
		$data['id'] = $this->id;
		$res        = $this->client()->call( $action, $data, max( 60, SwiftMigrate_Settings::budget() + 40 ) );
		if ( 'restore' === $action && ! empty( $res['done'] ) ) {
			$this->state['phase']    = 'done';
			$this->state['finished'] = time();
			$this->save();
			$this->log( __( 'Restore finished on destination.', 'swiftmigrate' ) );
			SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'source', 'status' => 'done', 'finished' => time(), 'bytes' => $this->state['bytes_total'] ) );
		}
		if ( 'rollback' === $action && ! empty( $res['done'] ) ) {
			$this->state['phase'] = 'rolled_back';
			$this->save();
			SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'source', 'status' => 'rolled back' ) );
		}
		return $res;
	}

	public function cancel() {
		try {
			$this->client()->call( 'abort', array( 'id' => $this->id ), 20 );
		} catch ( Exception $e ) {
			/* translators: %s: error message. */
			$this->log( sprintf( __( 'Destination did not confirm abort: %s', 'swiftmigrate' ), $e->getMessage() ), 'warn' );
		}
		$this->state['phase'] = 'cancelled';
		$this->save();
		SwiftMigrate_Storage::write_json( SwiftMigrate_Storage::path( 'send-current.json' ), array() );
		SwiftMigrate_Logger::history_add( array( 'id' => $this->id, 'role' => 'source', 'status' => 'cancelled' ) );
	}

	public function status() {
		$s = $this->state;
		return array(
			'id'          => $this->id,
			'phase'       => $s['phase'],
			'peer'        => $s['peer']['home'],
			'peer_login'  => trailingslashit( $s['peer']['siteurl'] ) . 'wp-login.php',
			'jobs'        => (int) $s['jobs'],
			'pages'       => (int) ceil( $s['jobs'] / self::PAGE ),
			'files_total' => (int) $s['files_total'],
			'bytes_total' => (float) $s['bytes_total'],
			'db_tables'   => (int) $s['db_tables'],
			'bytes_done'  => (float) $s['bytes_done'],
			'files_done'  => (int) $s['files_done'],
			'scan_left'   => isset( $s['scan'] ) ? count( $s['scan']['stack'] ) : 0,
			'skipped'     => array_slice( $s['skipped'], 0, 50 ),
			'warning'     => isset( $s['warning'] ) ? $s['warning'] : '',
			'workers'     => (int) SwiftMigrate_Settings::get( 'workers' ),
			'keep_backup' => (bool) SwiftMigrate_Settings::get( 'keep_backup' ),
			'autorestore' => ! empty( $s['opts']['autorestore'] ),
			'log'         => SwiftMigrate_Logger::tail( $this->id, 40 ),
		);
	}
}
