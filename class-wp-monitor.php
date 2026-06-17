<?php
/**
 * Clase principal del módulo WordPress Monitor.
 *
 * Recopila información de WordPress, servidor, configuración (wp-config.php),
 * seguridad, archivos expuestos y endpoints activos. Los datos se guardan
 * como snapshots históricos en una tabla propia.
 *
 * @package Ultron
 * @subpackage WP_Monitor
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultron_WP_Monitor {

	private string $table        = 'ultron_wp_monitor';
	private string $option_limit = 'ultron_wp_monitor_history_limit';
	private int    $default_limit = 100;

	public function __construct() {
		add_action( 'admin_post_ultron_wp_monitor_refresh', [ $this, 'handle_refresh' ] );
		add_action( 'admin_post_ultron_wp_monitor_export',  [ $this, 'handle_export' ] );
	}

	private function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . $this->table;
	}

	public function maybe_create_table(): void {
		global $wpdb;
		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			data LONGTEXT NOT NULL,
			PRIMARY KEY (id)
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'ultron' ) );
		}
		check_admin_referer( 'ultron_wp_monitor_refresh' );
		$this->save_snapshot( $this->collect_data() );
		wp_redirect( admin_url( 'admin.php?page=ultron-monitor&tab=wordpress&refreshed=1' ) );
		exit;
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'ultron' ) );
		}
		check_admin_referer( 'ultron_wp_monitor_export' );
		$snapshot = $this->get_latest_snapshot();
		if ( ! $snapshot ) {
			wp_die( __( 'No hay datos para exportar.', 'ultron' ) );
		}
		$filename = 'ultron-wp-monitor-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Recopila todos los datos del snapshot.
	 *
	 * @return array
	 */
	private function collect_data(): array {
		global $wpdb;

		// WordPress básico.
		$theme            = wp_get_theme();
		$core_updates     = get_site_transient( 'update_core' );
		$update_available = false;

		if ( $core_updates && ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( $update->response === 'upgrade' ) {
					$update_available = $update->version;
					break;
				}
			}
		}

		// Usuarios.
		$user_list = [];
		foreach ( get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] ) as $user ) {
			$user_list[] = [
				'id'    => $user->ID,
				'login' => $user->user_login,
				'name'  => $user->display_name,
				'roles' => get_userdata( $user->ID )->roles,
			];
		}

		// Seguridad — archivos expuestos.
		$exposed_files = $this->check_exposed_files();

		// Seguridad — plugins con readme expuesto.
		$exposed_plugins = $this->check_exposed_plugin_readmes();

		// Seguridad — listado de directorios.
		$dir_listing = $this->check_directory_listing();

		// .htaccess
		$htaccess = $this->check_htaccess();

		// Endpoints.
		$endpoints = $this->check_endpoints();

		// wp-config.php constants.
		$config = $this->collect_wp_config();

		return [
			'wordpress' => [
				'version'          => get_bloginfo( 'version' ),
				'update_available' => $update_available,
				'theme_name'       => $theme->get( 'Name' ),
				'theme_version'    => $theme->get( 'Version' ),
				'language'         => get_locale(),
				'timezone'         => wp_timezone_string(),
			],
			'server' => [
				'php_version'   => phpversion(),
				'mysql_version' => $wpdb->db_version(),
				'os'            => PHP_OS,
				'memory_limit'  => ini_get( 'memory_limit' ),
			],
			'config'           => $config,
			'security_files'   => $exposed_files,
			'security_plugins' => $exposed_plugins,
			'security_dirs'    => $dir_listing,
			'htaccess'         => $htaccess,
			'endpoints'        => $endpoints,
			'users'            => $user_list,
		];
	}

	/**
	 * Recopila constantes relevantes de wp-config.php.
	 *
	 * @return array
	 */
	private function collect_wp_config(): array {
		global $wpdb;

		return [
			// Básico.
			'site_url'       => get_bloginfo( 'url' ),
			'wp_url'         => site_url(),
			'multisite'      => is_multisite(),
			'table_prefix'   => $wpdb->prefix,

			// Debug.
			'wp_debug'            => defined( 'WP_DEBUG' )         && WP_DEBUG,
			'wp_debug_log'        => defined( 'WP_DEBUG_LOG' )     && WP_DEBUG_LOG,
			'wp_debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script_debug'        => defined( 'SCRIPT_DEBUG' )     && SCRIPT_DEBUG,
			'savequeries'         => defined( 'SAVEQUERIES' )      && SAVEQUERIES,

			// Rendimiento.
			'wp_cache'            => defined( 'WP_CACHE' )            && WP_CACHE,
			'autosave_interval'   => defined( 'AUTOSAVE_INTERVAL' )   ? AUTOSAVE_INTERVAL   : null,
			'empty_trash_days'    => defined( 'EMPTY_TRASH_DAYS' )    ? EMPTY_TRASH_DAYS    : null,
			'wp_post_revisions'   => defined( 'WP_POST_REVISIONS' )   ? WP_POST_REVISIONS   : null,
			'compress_scripts'    => defined( 'COMPRESS_SCRIPTS' )    ? COMPRESS_SCRIPTS    : null,
			'compress_css'        => defined( 'COMPRESS_CSS' )        ? COMPRESS_CSS        : null,

			// Seguridad.
			'disallow_file_edit'  => defined( 'DISALLOW_FILE_EDIT' )  && DISALLOW_FILE_EDIT,
			'disallow_file_mods'  => defined( 'DISALLOW_FILE_MODS' )  && DISALLOW_FILE_MODS,
			'force_ssl_admin'     => defined( 'FORCE_SSL_ADMIN' )     && FORCE_SSL_ADMIN,
			'http_block_external' => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,

			// Base de datos.
			'db_host'    => defined( 'DB_HOST' )    ? DB_HOST    : null,
			'db_charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : null,
			'db_collate' => defined( 'DB_COLLATE' ) ? DB_COLLATE : null,

			// URLs y rutas personalizadas.
			'wp_home'         => defined( 'WP_HOME' )         ? WP_HOME         : null,
			'wp_siteurl'      => defined( 'WP_SITEURL' )      ? WP_SITEURL      : null,
			'wp_content_dir'  => defined( 'WP_CONTENT_DIR' )  ? WP_CONTENT_DIR  : null,
			'wp_content_url'  => defined( 'WP_CONTENT_URL' )  ? WP_CONTENT_URL  : null,
			'uploads'         => defined( 'UPLOADS' )          ? UPLOADS         : null,

			// PHP.
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' )     ? WP_MEMORY_LIMIT     : null,
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,

			// Modo mantenimiento.
			'maintenance_mode'    => file_exists( ABSPATH . '.maintenance' ),
			'disable_wp_cron'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		];
	}

	/**
	 * Verifica archivos core que no deberían ser públicamente accesibles.
	 *
	 * @return array
	 */
	private function check_exposed_files(): array {
		$files_to_check = [
			'readme.html'          => home_url( '/readme.html' ),
			'license.txt'          => home_url( '/license.txt' ),
			'wp-config-sample.php' => home_url( '/wp-config-sample.php' ),
			'xmlrpc.php'           => home_url( '/xmlrpc.php' ),
			'wp-login.php'         => home_url( '/wp-login.php' ),
		];

		$results = [];

		foreach ( $files_to_check as $file => $url ) {
			$response = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 0 ] );
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$results[ $file ] = [
				'url'      => $url,
				'exposed'  => $code === 200,
				'code'     => $code,
			];
		}

		return $results;
	}

	/**
	 * Verifica si los readme.txt de plugins instalados son accesibles públicamente.
	 *
	 * @return array
	 */
	private function check_exposed_plugin_readmes(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$results = [];

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$slug     = explode( '/', $plugin_file )[0];
			$readme_url = content_url( 'plugins/' . $slug . '/readme.txt' );
			$response   = wp_remote_head( $readme_url, [ 'timeout' => 3, 'redirection' => 0 ] );
			$code       = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			if ( $code === 200 ) {
				$results[] = [
					'plugin'  => $plugin_data['Name'],
					'slug'    => $slug,
					'url'     => $readme_url,
					'exposed' => true,
				];
			}
		}

		return $results;
	}

	/**
	 * Verifica si los directorios clave tienen listado habilitado.
	 *
	 * @return array
	 */
	private function check_directory_listing(): array {
		$dirs = [
			'wp-includes' => home_url( '/wp-includes/' ),
			'plugins'     => content_url( 'plugins/' ),
		];

		$results = [];

		foreach ( $dirs as $name => $url ) {
			$response = wp_remote_get( $url, [ 'timeout' => 5, 'redirection' => 0 ] );
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );

			// Detecta listado de directorios real (encabezado "Index of" en respuesta 200).
			$listing = $code === 200 && stripos( $body, 'Index of' ) !== false;

			$results[ $name ] = [
				'url'     => $url,
				'listing' => $listing,
				'code'    => $code,
			];
		}

		return $results;
	}

	/**
	 * Verifica el estado del archivo .htaccess.
	 *
	 * @return array
	 */
	private function check_htaccess(): array {
		$path    = ABSPATH . '.htaccess';
		$exists  = file_exists( $path );
		$exposed = false;
		$wordfence_present = false;
		$wordfence_rules   = [];

		if ( $exists ) {
			// Verificar si es accesible públicamente.
			$response = wp_remote_head( home_url( '/.htaccess' ), [ 'timeout' => 5, 'redirection' => 0 ] );
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$exposed  = $code === 200;

			// Detectar reglas de Wordfence.
			$content = file_get_contents( $path );
			if ( stripos( $content, 'Wordfence' ) !== false || stripos( $content, 'wordfence' ) !== false ) {
				$wordfence_present = true;

				// Extraer bloque de Wordfence.
				if ( preg_match( '/#\s*Wordfence.*?(?=#\s*END Wordfence|$)/si', $content, $matches ) ) {
					$lines = explode( "\n", trim( $matches[0] ) );
					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( ! empty( $line ) ) {
							$wordfence_rules[] = $line;
						}
					}
				}
			}
		}

		return [
			'exists'            => $exists,
			'exposed'           => $exposed,
			'wordfence_present' => $wordfence_present,
			'wordfence_rules'   => $wordfence_rules,
		];
	}

	/**
	 * Verifica el estado de los endpoints de WordPress.
	 *
	 * @return array
	 */
	private function check_endpoints(): array {
		// REST API.
		$rest_response = wp_remote_get( rest_url( '/' ), [ 'timeout' => 5 ] );
		$rest_active   = ! is_wp_error( $rest_response ) && wp_remote_retrieve_response_code( $rest_response ) === 200;

		// XML-RPC.
		$xmlrpc_response = wp_remote_head( home_url( '/xmlrpc.php' ), [ 'timeout' => 5 ] );
		$xmlrpc_code     = is_wp_error( $xmlrpc_response ) ? 0 : wp_remote_retrieve_response_code( $xmlrpc_response );
		$xmlrpc_active   = in_array( $xmlrpc_code, [ 200, 405 ], true );

		return [
			'rest_api'     => $rest_active,
			'xmlrpc'       => $xmlrpc_active,
			'pingbacks'    => (bool) get_option( 'default_ping_status' ) === 'open',
			'trackbacks'   => (bool) get_option( 'default_ping_status' ) === 'open',
			'oembed'       => ! has_filter( 'oembed_response_data' ),
			'rss_feed'     => (bool) get_option( 'blog_public' ),
			'wp_cron'      => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
		];
	}

	private function save_snapshot( array $data ): void {
		global $wpdb;
		$wpdb->insert(
			$this->get_table_name(),
			[
				'created_at' => current_time( 'mysql' ),
				'data'       => wp_json_encode( $data ),
			],
			[ '%s', '%s' ]
		);
		$this->enforce_history_limit();
	}

	private function enforce_history_limit(): void {
		global $wpdb;
		$table_name = $this->get_table_name();
		$limit      = (int) get_option( $this->option_limit, $this->default_limit );
		if ( $limit <= 0 ) return;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		if ( $total > $limit ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} ORDER BY id ASC LIMIT %d", $total - $limit ) );
		}
	}

	private function get_latest_snapshot(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT data, created_at FROM {$this->get_table_name()} ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( ! $row ) return null;
		$data               = json_decode( $row['data'], true );
		$data['created_at'] = $row['created_at'];
		return $data;
	}

	/**
	 * Renderiza la pestaña WordPress en Monitor.
	 *
	 * @return void
	 */
	public function render_tab(): void {
		$this->maybe_create_table();
		$snapshot = $this->get_latest_snapshot();
		?>

		<?php if ( isset( $_GET['refreshed'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e( 'Datos actualizados correctamente.', 'ultron' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="ultron-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ultron_wp_monitor_refresh' ); ?>
				<input type="hidden" name="action" value="ultron_wp_monitor_refresh">
				<button type="submit" class="button button-primary"><?php _e( 'Actualizar datos', 'ultron' ); ?></button>
			</form>
			<?php if ( $snapshot ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ultron_wp_monitor_export' ); ?>
					<input type="hidden" name="action" value="ultron_wp_monitor_export">
					<button type="submit" class="button"><?php _e( 'Exportar JSON', 'ultron' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( $snapshot ) : ?>
			<div class="ultron-actions-meta">
				<?php echo sprintf( __( 'Última actualización: %s', 'ultron' ), esc_html( $snapshot['created_at'] ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $snapshot ) : ?>
			<p><?php _e( 'No hay datos aún. Pulsa "Actualizar datos" para generar el primer snapshot.', 'ultron' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<!-- WordPress -->
		<p class="ultron-section-title"><?php _e( 'WordPress', 'ultron' ); ?></p>
		<table class="ultron-table">
			<tbody>
				<tr><td><?php _e( 'Versión', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['wordpress']['version'] ); ?></td></tr>
				<tr>
					<td><?php _e( 'Actualización disponible', 'ultron' ); ?></td>
					<td>
						<?php if ( $snapshot['wordpress']['update_available'] ) : ?>
							<span class="ultron-badge warning">↑ <?php echo esc_html( $snapshot['wordpress']['update_available'] ); ?></span>
						<?php else : ?>
							<span class="ultron-badge ok"><?php _e( 'No', 'ultron' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr><td><?php _e( 'Tema activo', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['wordpress']['theme_name'] . ' (' . $snapshot['wordpress']['theme_version'] . ')' ); ?></td></tr>
				<tr><td><?php _e( 'Idioma', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['wordpress']['language'] ); ?></td></tr>
				<tr><td><?php _e( 'Zona horaria', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['wordpress']['timezone'] ); ?></td></tr>
			</tbody>
		</table>

		<!-- Servidor -->
		<p class="ultron-section-title"><?php _e( 'Servidor', 'ultron' ); ?></p>
		<table class="ultron-table">
			<tbody>
				<tr><td>PHP</td><td><?php echo esc_html( $snapshot['server']['php_version'] ); ?></td></tr>
				<tr><td>MySQL / MariaDB</td><td><?php echo esc_html( $snapshot['server']['mysql_version'] ); ?></td></tr>
				<tr><td><?php _e( 'Sistema operativo', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['server']['os'] ); ?></td></tr>
				<tr><td><?php _e( 'Memoria PHP', 'ultron' ); ?></td><td><?php echo esc_html( $snapshot['server']['memory_limit'] ); ?></td></tr>
			</tbody>
		</table>

		<!-- Configuración (wp-config.php) -->
		<p class="ultron-section-title"><?php _e( 'Configuración (wp-config.php)', 'ultron' ); ?></p>
		<table class="ultron-table">
			<tbody>
				<?php
				$cfg = $snapshot['config'];
				$bool_row = function( string $label, ?bool $value ) {
					if ( is_null( $value ) ) {
						echo '<tr><td>' . esc_html( $label ) . '</td><td><span class="ultron-badge muted">' . esc_html__( 'No definido', 'ultron' ) . '</span></td></tr>';
						return;
					}
					$badge = $value
						? '<span class="ultron-badge ok">' . esc_html__( 'Sí', 'ultron' ) . '</span>'
						: '<span class="ultron-badge muted">' . esc_html__( 'No', 'ultron' ) . '</span>';
					echo '<tr><td>' . esc_html( $label ) . '</td><td>' . $badge . '</td></tr>';
				};
				$val_row = function( string $label, mixed $value ) {
					$display = is_null( $value ) ? '<span class="ultron-badge muted">' . esc_html__( 'No definido', 'ultron' ) . '</span>' : '<code>' . esc_html( (string) $value ) . '</code>';
					echo '<tr><td>' . esc_html( $label ) . '</td><td>' . $display . '</td></tr>';
				};

				// URLs.
				$val_row( __( 'URL del sitio', 'ultron' ),      $cfg['site_url'] );
				$val_row( __( 'URL de WordPress', 'ultron' ),   $cfg['wp_url'] );
				$val_row( 'WP_HOME',         $cfg['wp_home'] );
				$val_row( 'WP_SITEURL',      $cfg['wp_siteurl'] );
				$val_row( 'WP_CONTENT_DIR',  $cfg['wp_content_dir'] );
				$val_row( 'WP_CONTENT_URL',  $cfg['wp_content_url'] );
				$val_row( 'UPLOADS',         $cfg['uploads'] );
				$val_row( __( 'Prefijo de tablas', 'ultron' ),  $cfg['table_prefix'] );
				$bool_row( __( 'Multisitio', 'ultron' ),        $cfg['multisite'] );

				// Debug.
				$bool_row( 'WP_DEBUG',         $cfg['wp_debug'] );
				$bool_row( 'WP_DEBUG_LOG',     $cfg['wp_debug_log'] );
				$bool_row( 'WP_DEBUG_DISPLAY', $cfg['wp_debug_display'] );
				$bool_row( 'SCRIPT_DEBUG',     $cfg['script_debug'] );
				$bool_row( 'SAVEQUERIES',      $cfg['savequeries'] );

				// Rendimiento.
				$bool_row( 'WP_CACHE',          $cfg['wp_cache'] );
				$val_row( 'AUTOSAVE_INTERVAL',  $cfg['autosave_interval'] );
				$val_row( 'EMPTY_TRASH_DAYS',   $cfg['empty_trash_days'] );
				$val_row( 'WP_POST_REVISIONS',  $cfg['wp_post_revisions'] );
				$bool_row( 'COMPRESS_SCRIPTS',  $cfg['compress_scripts'] );
				$bool_row( 'COMPRESS_CSS',      $cfg['compress_css'] );

				// Seguridad.
				$bool_row( 'DISALLOW_FILE_EDIT',    $cfg['disallow_file_edit'] );
				$bool_row( 'DISALLOW_FILE_MODS',    $cfg['disallow_file_mods'] );
				$bool_row( 'FORCE_SSL_ADMIN',       $cfg['force_ssl_admin'] );
				$bool_row( 'WP_HTTP_BLOCK_EXTERNAL', $cfg['http_block_external'] );

				// BD.
				$val_row( 'DB_HOST',    $cfg['db_host'] );
				$val_row( 'DB_CHARSET', $cfg['db_charset'] );
				$val_row( 'DB_COLLATE', $cfg['db_collate'] );

				// PHP.
				$val_row( 'upload_max_filesize',  $cfg['upload_max_filesize'] );
				$val_row( 'post_max_size',        $cfg['post_max_size'] );
				$val_row( 'max_execution_time',   $cfg['max_execution_time'] );
				$val_row( 'WP_MEMORY_LIMIT',      $cfg['wp_memory_limit'] );
				$val_row( 'WP_MAX_MEMORY_LIMIT',  $cfg['wp_max_memory_limit'] );

				// Misc.
				$bool_row( __( 'Modo mantenimiento', 'ultron' ), $cfg['maintenance_mode'] );
				$bool_row( 'DISABLE_WP_CRON',                   $cfg['disable_wp_cron'] );
				?>
			</tbody>
		</table>

		<!-- Seguridad — archivos expuestos -->
		<p class="ultron-section-title"><?php _e( 'Seguridad — Archivos expuestos', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Archivo', 'ultron' ); ?></th>
					<th><?php _e( 'Estado', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot['security_files'] as $file => $info ) : ?>
					<tr>
						<td><code><?php echo esc_html( $file ); ?></code></td>
						<td>
							<?php if ( $info['exposed'] ) : ?>
								<span class="ultron-badge danger"><?php _e( 'Expuesto', 'ultron' ); ?></span>
							<?php else : ?>
								<span class="ultron-badge ok"><?php _e( 'Protegido', 'ultron' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Seguridad — listado de directorios -->
		<p class="ultron-section-title"><?php _e( 'Seguridad — Listado de directorios', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Directorio', 'ultron' ); ?></th>
					<th><?php _e( 'Estado', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot['security_dirs'] as $name => $info ) : ?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td>
							<?php if ( $info['listing'] ) : ?>
								<span class="ultron-badge danger"><?php _e( 'Listado habilitado', 'ultron' ); ?></span>
							<?php else : ?>
								<span class="ultron-badge ok"><?php _e( 'Protegido', 'ultron' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Seguridad — plugins con readme expuesto -->
		<p class="ultron-section-title"><?php _e( 'Seguridad — Plugins con readme.txt expuesto', 'ultron' ); ?></p>
		<?php if ( empty( $snapshot['security_plugins'] ) ) : ?>
			<p><span class="ultron-badge ok"><?php _e( 'Ningún plugin expone su readme.txt públicamente.', 'ultron' ); ?></span></p>
		<?php else : ?>
			<table class="ultron-table">
				<thead>
					<tr>
						<th><?php _e( 'Plugin', 'ultron' ); ?></th>
						<th><?php _e( 'URL', 'ultron' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshot['security_plugins'] as $p ) : ?>
						<tr>
							<td><?php echo esc_html( $p['plugin'] ); ?></td>
							<td><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank"><?php echo esc_html( $p['url'] ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- .htaccess -->
		<p class="ultron-section-title">.htaccess</p>
		<table class="ultron-table">
			<tbody>
				<tr>
					<td><?php _e( 'Existe', 'ultron' ); ?></td>
					<td>
						<?php echo $snapshot['htaccess']['exists']
							? '<span class="ultron-badge ok">' . __( 'Sí', 'ultron' ) . '</span>'
							: '<span class="ultron-badge muted">' . __( 'No', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
				<tr>
					<td><?php _e( 'Accesible públicamente', 'ultron' ); ?></td>
					<td>
						<?php echo $snapshot['htaccess']['exposed']
							? '<span class="ultron-badge danger">' . __( 'Sí — riesgo de seguridad', 'ultron' ) . '</span>'
							: '<span class="ultron-badge ok">' . __( 'No', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
				<tr>
					<td><?php _e( 'Modificado por Wordfence', 'ultron' ); ?></td>
					<td>
						<?php echo $snapshot['htaccess']['wordfence_present']
							? '<span class="ultron-badge ok">' . __( 'Sí', 'ultron' ) . '</span>'
							: '<span class="ultron-badge muted">' . __( 'No', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $snapshot['htaccess']['wordfence_rules'] ) ) : ?>
			<details class="ultron-details" style="max-width:700px; margin-bottom:20px;">
				<summary><?php _e( 'Ver reglas de Wordfence en .htaccess', 'ultron' ); ?></summary>
				<div style="padding:12px; background:#f6f7f7; font-family:monospace; font-size:12px; white-space:pre-wrap;">
					<?php echo esc_html( implode( "\n", $snapshot['htaccess']['wordfence_rules'] ) ); ?>
				</div>
			</details>
		<?php endif; ?>

		<!-- Endpoints -->
		<p class="ultron-section-title"><?php _e( 'Endpoints activos', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Endpoint', 'ultron' ); ?></th>
					<th><?php _e( 'Estado', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$endpoints_labels = [
					'rest_api'   => 'REST API',
					'xmlrpc'     => 'XML-RPC',
					'pingbacks'  => 'Pingbacks',
					'trackbacks' => 'Trackbacks',
					'oembed'     => 'oEmbed',
					'rss_feed'   => 'RSS Feed',
					'wp_cron'    => 'WP-Cron',
				];
				foreach ( $endpoints_labels as $key => $label ) :
					$active = $snapshot['endpoints'][ $key ] ?? false;
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td>
							<?php echo $active
								? '<span class="ultron-badge ok">' . __( 'Activo', 'ultron' ) . '</span>'
								: '<span class="ultron-badge muted">' . __( 'Inactivo', 'ultron' ) . '</span>'; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Usuarios -->
		<p class="ultron-section-title"><?php _e( 'Usuarios', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Usuario', 'ultron' ); ?></th>
					<th><?php _e( 'Nombre', 'ultron' ); ?></th>
					<th><?php _e( 'Rol', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot['users'] as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user['login'] ); ?></td>
						<td><?php echo esc_html( $user['name'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $user['roles'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
	}

	/**
	 * Widget en el Dashboard del hub.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$snapshot = $this->get_latest_snapshot();
		$css_class = 'ultron-widget';
		?>
		<div class="<?php echo esc_attr( $css_class ); ?>">
			<h3><?php _e( 'WordPress Monitor', 'ultron' ); ?></h3>
			<?php if ( ! $snapshot ) : ?>
				<p><?php _e( 'Sin datos. Ve a Monitor → WordPress para generar el primer snapshot.', 'ultron' ); ?></p>
			<?php else : ?>
				<p><strong><?php _e( 'Sitio:', 'ultron' ); ?></strong> <?php echo esc_html( $snapshot['config']['site_url'] ); ?></p>
				<p><strong><?php _e( 'WordPress:', 'ultron' ); ?></strong> <?php echo esc_html( $snapshot['wordpress']['version'] ); ?>
					<?php if ( $snapshot['wordpress']['update_available'] ) : ?>
						<span class="ultron-badge warning">↑ <?php echo esc_html( $snapshot['wordpress']['update_available'] ); ?></span>
					<?php endif; ?>
				</p>
				<p><strong><?php _e( 'Tema:', 'ultron' ); ?></strong> <?php echo esc_html( $snapshot['wordpress']['theme_name'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

}
