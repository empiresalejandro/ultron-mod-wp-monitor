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
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ultron_WP_Monitor {

	private string $table         = 'ultron_wp_monitor';
	private string $option_limit  = 'ultron_wp_monitor_history_limit';
	private int    $default_limit = 100;

	/**
	 * Firmas conocidas de plugins que suelen modificar .htaccess.
	 *
	 * @var array<string,string> Clave: identificador interno. Valor: cadena a buscar en el archivo.
	 */
	private array $htaccess_signatures = [
		'wordfence'       => 'Wordfence',
		'aiowps'          => 'All In One WP Security',
		'ithemes'         => 'iThemes Security',
		'solid_security'  => 'Solid Security',
		'wp_super_cache'  => 'WP Super Cache',
		'w3_total_cache'  => 'W3TC',
		'litespeed_cache' => 'LSCACHE',
	];

	public function __construct() {
		add_action( 'admin_post_ultron_wp_monitor_refresh',         [ $this, 'handle_refresh' ] );
		add_action( 'admin_post_ultron_wp_monitor_export',          [ $this, 'handle_export' ] );
		add_action( 'admin_post_ultron_wp_monitor_export_history',  [ $this, 'handle_export_history' ] );
		add_action( 'admin_post_ultron_wp_monitor_toggle_maint',    [ $this, 'handle_toggle_maintenance' ] );
		add_action( 'admin_post_ultron_wp_monitor_toggle_endpoint', [ $this, 'handle_toggle_endpoint' ] );
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
	 * Exporta el historial completo de snapshots guardados como JSON.
	 *
	 * @return void
	 */
	public function handle_export_history(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'ultron' ) );
		}
		check_admin_referer( 'ultron_wp_monitor_export_history' );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT created_at, data FROM {$this->get_table_name()} ORDER BY id ASC",
			ARRAY_A
		);

		$history = [];
		foreach ( $rows as $row ) {
			$decoded               = json_decode( $row['data'], true );
			$decoded['created_at'] = $row['created_at'];
			$history[]             = $decoded;
		}

		if ( empty( $history ) ) {
			wp_die( __( 'No hay historial para exportar.', 'ultron' ) );
		}

		$filename = 'ultron-wp-monitor-historial-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $history, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Activa o desactiva el modo mantenimiento creando/eliminando el
	 * archivo .maintenance en la raíz de WordPress.
	 *
	 * @return void
	 */
	public function handle_toggle_maintenance(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'ultron' ) );
		}
		check_admin_referer( 'ultron_wp_monitor_toggle_maint' );

		$maintenance_file = ABSPATH . '.maintenance';

		if ( file_exists( $maintenance_file ) ) {
			@unlink( $maintenance_file );
		} else {
			$content = '<?php $upgrading = ' . time() . '; ' . "\n";
			@file_put_contents( $maintenance_file, $content );
		}

		wp_redirect( admin_url( 'admin.php?page=ultron-monitor&tab=wordpress&maint_toggled=1' ) );
		exit;
	}

	/**
	 * Activa o desactiva un endpoint configurable (XML-RPC, pingbacks/trackbacks,
	 * oEmbed, RSS feed) mediante una opción propia que se aplica vía filtros.
	 *
	 * @return void
	 */
	public function handle_toggle_endpoint(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sin permisos.', 'ultron' ) );
		}
		check_admin_referer( 'ultron_wp_monitor_toggle_endpoint' );

		$endpoint = isset( $_POST['endpoint'] ) ? sanitize_key( $_POST['endpoint'] ) : '';
		$valid    = [ 'xmlrpc', 'ping', 'oembed', 'rss_feed' ];

		if ( in_array( $endpoint, $valid, true ) ) {
			switch ( $endpoint ) {
				case 'xmlrpc':
					$current = get_option( 'ultron_disable_xmlrpc', false );
					update_option( 'ultron_disable_xmlrpc', ! $current );
					break;

				case 'ping':
					$current = get_option( 'default_ping_status', 'open' );
					update_option( 'default_ping_status', $current === 'open' ? 'closed' : 'open' );
					break;

				case 'oembed':
					$current = get_option( 'ultron_disable_oembed', false );
					update_option( 'ultron_disable_oembed', ! $current );
					break;

				case 'rss_feed':
					$current = get_option( 'ultron_disable_feed', false );
					update_option( 'ultron_disable_feed', ! $current );
					break;
			}
		}

		wp_redirect( admin_url( 'admin.php?page=ultron-monitor&tab=wordpress&endpoint_toggled=1' ) );
		exit;
	}

	/**
	 * Recopila todos los datos del snapshot.
	 *
	 * @return array
	 */
	private function collect_data(): array {
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

		$user_list = [];
		foreach ( get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] ) as $user ) {
			$user_list[] = [
				'id'    => $user->ID,
				'login' => $user->user_login,
				'name'  => $user->display_name,
				'roles' => get_userdata( $user->ID )->roles,
			];
		}

		return [
			'wordpress' => [
				'version'          => get_bloginfo( 'version' ),
				'update_available' => $update_available,
				'theme_name'       => $theme->get( 'Name' ),
				'theme_version'    => $theme->get( 'Version' ),
				'language'         => get_locale(),
				'timezone'         => wp_timezone_string(),
			],
			'server'           => $this->collect_server_data(),
			'config'           => $this->collect_wp_config(),
			'security_files'   => $this->check_exposed_files(),
			'security_plugins' => $this->check_exposed_plugin_readmes(),
			'security_dirs'    => $this->check_directory_listing(),
			'htaccess'         => $this->check_htaccess(),
			'endpoints'        => $this->check_endpoints(),
			'users'            => $user_list,
		];
	}

	/**
	 * Recopila datos del servidor junto con su estado de salud
	 * (OK / Warning / Danger) según mínimos recomendados para WordPress.
	 *
	 * @return array
	 */
	private function collect_server_data(): array {
		global $wpdb;

		$php_version   = phpversion();
		$mysql_version = $wpdb->db_version();
		$memory_limit  = ini_get( 'memory_limit' );

		return [
			'php_version'   => $php_version,
			'php_status'    => $this->get_php_status( $php_version ),
			'mysql_version' => $mysql_version,
			'mysql_status'  => $this->get_mysql_status( $mysql_version ),
			'os'            => PHP_OS,
			'memory_limit'  => $memory_limit,
			'memory_status' => $this->get_memory_status( $memory_limit ),
		];
	}

	/**
	 * Evalúa la versión de PHP contra los mínimos recomendados actuales.
	 *
	 * @param string $version Versión de PHP detectada.
	 * @return array{status: string, label: string, suggestion: string}
	 */
	private function get_php_status( string $version ): array {
		if ( version_compare( $version, '7.4', '<' ) ) {
			return [
				'status'     => 'danger',
				'label'      => __( 'Danger', 'ultron' ),
				'suggestion' => __( 'PHP desactualizado y sin soporte de seguridad. Actualiza a 8.3 o superior cuanto antes.', 'ultron' ),
			];
		}

		if ( version_compare( $version, '8.3', '<' ) ) {
			return [
				'status'     => 'warning',
				'label'      => __( 'Warning', 'ultron' ),
				'suggestion' => __( 'Funciona, pero se recomienda actualizar a PHP 8.3 o superior para mejor rendimiento y soporte de seguridad.', 'ultron' ),
			];
		}

		return [
			'status'     => 'ok',
			'label'      => __( 'OK', 'ultron' ),
			'suggestion' => __( 'Versión recomendada.', 'ultron' ),
		];
	}

	/**
	 * Evalúa la versión de MySQL/MariaDB contra los mínimos recomendados.
	 *
	 * @param string $version Versión detectada (puede incluir sufijo MariaDB).
	 * @return array{status: string, label: string, suggestion: string}
	 */
	private function get_mysql_status( string $version ): array {
		$is_mariadb    = stripos( $version, 'mariadb' ) !== false;
		$clean_version = preg_replace( '/[^0-9.].*$/', '', $version );

		if ( $is_mariadb ) {
			if ( version_compare( $clean_version, '10.4', '<' ) ) {
				return [
					'status'     => 'danger',
					'label'      => __( 'Danger', 'ultron' ),
					'suggestion' => __( 'MariaDB desactualizado. Actualiza a 10.6 o superior.', 'ultron' ),
				];
			}
			if ( version_compare( $clean_version, '10.6', '<' ) ) {
				return [
					'status'     => 'warning',
					'label'      => __( 'Warning', 'ultron' ),
					'suggestion' => __( 'Funciona, pero se recomienda actualizar a MariaDB 10.6 o superior.', 'ultron' ),
				];
			}
			return [
				'status'     => 'ok',
				'label'      => __( 'OK', 'ultron' ),
				'suggestion' => __( 'Versión recomendada.', 'ultron' ),
			];
		}

		if ( version_compare( $clean_version, '5.7', '<' ) ) {
			return [
				'status'     => 'danger',
				'label'      => __( 'Danger', 'ultron' ),
				'suggestion' => __( 'MySQL desactualizado. Actualiza a 8.0 o superior.', 'ultron' ),
			];
		}
		if ( version_compare( $clean_version, '8.0', '<' ) ) {
			return [
				'status'     => 'warning',
				'label'      => __( 'Warning', 'ultron' ),
				'suggestion' => __( 'Funciona, pero se recomienda actualizar a MySQL 8.0 o superior.', 'ultron' ),
			];
		}
		return [
			'status'     => 'ok',
			'label'      => __( 'OK', 'ultron' ),
			'suggestion' => __( 'Versión recomendada.', 'ultron' ),
		];
	}

	/**
	 * Evalúa el límite de memoria de PHP contra los mínimos recomendados.
	 *
	 * @param string $memory_limit Valor de memory_limit (ej. "256M").
	 * @return array{status: string, label: string, suggestion: string}
	 */
	private function get_memory_status( string $memory_limit ): array {
		$bytes = $this->convert_to_bytes( $memory_limit );
		$mb    = $bytes / ( 1024 * 1024 );

		if ( $mb < 64 ) {
			return [
				'status'     => 'danger',
				'label'      => __( 'Danger', 'ultron' ),
				'suggestion' => __( 'Memoria muy baja, riesgo alto de errores de memoria agotada. Sube a 256M o más.', 'ultron' ),
			];
		}

		if ( $mb < 256 ) {
			return [
				'status'     => 'warning',
				'label'      => __( 'Warning', 'ultron' ),
				'suggestion' => __( 'Funciona para sitios simples, pero se recomienda 256M o más, especialmente con varios plugins activos.', 'ultron' ),
			];
		}

		return [
			'status'     => 'ok',
			'label'      => __( 'OK', 'ultron' ),
			'suggestion' => __( 'Nivel recomendado.', 'ultron' ),
		];
	}

	/**
	 * Convierte un valor de configuración PHP tipo "256M" a bytes.
	 *
	 * @param string $value Valor con sufijo K/M/G.
	 * @return int
	 */
	private function convert_to_bytes( string $value ): int {
		$value = trim( $value );

		if ( $value === '-1' || $value === '' ) {
			return PHP_INT_MAX;
		}

		$unit   = strtoupper( substr( $value, -1 ) );
		$number = (int) $value;

		return match ( $unit ) {
			'G'     => $number * 1024 * 1024 * 1024,
			'M'     => $number * 1024 * 1024,
			'K'     => $number * 1024,
			default => (int) $value,
		};
	}

	/**
	 * Recopila constantes relevantes de wp-config.php.
	 *
	 * @return array
	 */
	private function collect_wp_config(): array {
		global $wpdb;

		$content_dir_relative = null;
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir_relative = '.../' . basename( WP_CONTENT_DIR );
		}

		return [
			'site_url'     => get_bloginfo( 'url' ),
			'wp_url'       => site_url(),
			'multisite'    => is_multisite(),
			'table_prefix' => $wpdb->prefix,

			'wp_debug'         => defined( 'WP_DEBUG' )         && WP_DEBUG,
			'wp_debug_log'     => defined( 'WP_DEBUG_LOG' )     && WP_DEBUG_LOG,
			'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script_debug'     => defined( 'SCRIPT_DEBUG' )     && SCRIPT_DEBUG,
			'savequeries'      => defined( 'SAVEQUERIES' )      && SAVEQUERIES,

			'wp_cache'          => defined( 'WP_CACHE' )          && WP_CACHE,
			'autosave_interval' => defined( 'AUTOSAVE_INTERVAL' ) ? AUTOSAVE_INTERVAL : null,
			'empty_trash_days'  => defined( 'EMPTY_TRASH_DAYS' )  ? EMPTY_TRASH_DAYS  : null,
			'wp_post_revisions' => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null,
			'compress_scripts'  => defined( 'COMPRESS_SCRIPTS' )  ? COMPRESS_SCRIPTS  : null,
			'compress_css'      => defined( 'COMPRESS_CSS' )      ? COMPRESS_CSS      : null,

			'disallow_file_edit'  => defined( 'DISALLOW_FILE_EDIT' )     && DISALLOW_FILE_EDIT,
			'disallow_file_mods'  => defined( 'DISALLOW_FILE_MODS' )     && DISALLOW_FILE_MODS,
			'force_ssl_admin'     => defined( 'FORCE_SSL_ADMIN' )        && FORCE_SSL_ADMIN,
			'http_block_external' => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,

			'db_host'    => defined( 'DB_HOST' )    ? DB_HOST    : null,
			'db_charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : null,
			'db_collate' => defined( 'DB_COLLATE' ) ? DB_COLLATE : null,

			'wp_home'        => defined( 'WP_HOME' )        ? WP_HOME        : null,
			'wp_siteurl'     => defined( 'WP_SITEURL' )     ? WP_SITEURL     : null,
			'wp_content_dir' => $content_dir_relative,
			'wp_content_url' => defined( 'WP_CONTENT_URL' ) ? WP_CONTENT_URL : null,
			'uploads'        => defined( 'UPLOADS' )        ? UPLOADS        : null,

			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' )     ? WP_MEMORY_LIMIT     : null,
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,

			'maintenance_mode' => file_exists( ABSPATH . '.maintenance' ),
			'disable_wp_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
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
			$response         = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 0 ] );
			$code             = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$results[ $file ] = [
				'url'     => $url,
				'exposed' => $code === 200,
				'code'    => $code,
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
			$slug       = explode( '/', $plugin_file )[0];
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
	 * Verifica el estado del archivo .htaccess, incluyendo detección
	 * ampliada de modificaciones por varios plugins conocidos.
	 *
	 * @return array
	 */
	private function check_htaccess(): array {
		$path             = ABSPATH . '.htaccess';
		$exists           = file_exists( $path );
		$exposed          = false;
		$detected_by      = [];
		$unidentified_mod = false;
		$rules_preview    = [];

		if ( $exists ) {
			$response = wp_remote_head( home_url( '/.htaccess' ), [ 'timeout' => 5, 'redirection' => 0 ] );
			$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$exposed  = $code === 200;

			$content = file_get_contents( $path );

			foreach ( $this->htaccess_signatures as $key => $signature ) {
				if ( stripos( $content, $signature ) !== false ) {
					$detected_by[] = $key;
				}
			}

			$is_standard_only = (bool) preg_match(
				'/^\s*#\s*BEGIN WordPress.*?#\s*END WordPress\s*$/si',
				trim( $content )
			);

			if ( empty( $detected_by ) && ! $is_standard_only && trim( $content ) !== '' ) {
				$unidentified_mod = true;
			}

			$lines = explode( "\n", $content );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line !== '' ) {
					$rules_preview[] = $line;
				}
				if ( count( $rules_preview ) >= 30 ) {
					break;
				}
			}
		}

		return [
			'exists'           => $exists,
			'exposed'          => $exposed,
			'detected_by'      => $detected_by,
			'unidentified_mod' => $unidentified_mod,
			'rules_preview'    => $rules_preview,
		];
	}

	/**
	 * Verifica el estado de los endpoints de WordPress.
	 *
	 * @return array
	 */
	private function check_endpoints(): array {
		$rest_response = wp_remote_get( rest_url( '/' ), [ 'timeout' => 5 ] );
		$rest_active   = ! is_wp_error( $rest_response ) && wp_remote_retrieve_response_code( $rest_response ) === 200;

		$xmlrpc_response = wp_remote_head( home_url( '/xmlrpc.php' ), [ 'timeout' => 5 ] );
		$xmlrpc_code     = is_wp_error( $xmlrpc_response ) ? 0 : wp_remote_retrieve_response_code( $xmlrpc_response );
		$xmlrpc_active   = in_array( $xmlrpc_code, [ 200, 405 ], true ) && ! get_option( 'ultron_disable_xmlrpc', false );

		$ping_active = get_option( 'default_ping_status', 'open' ) === 'open';

		return [
			'rest_api'   => $rest_active,
			'xmlrpc'     => $xmlrpc_active,
			'pingbacks'  => $ping_active,
			'trackbacks' => $ping_active,
			'oembed'     => ! get_option( 'ultron_disable_oembed', false ),
			'rss_feed'   => ! get_option( 'ultron_disable_feed', false ),
			'wp_cron'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
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
		if ( $limit <= 0 ) {
			return;
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		if ( $total > $limit ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} ORDER BY id ASC LIMIT %d", $total - $limit ) );
		}
	}

	private function get_latest_snapshot(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT data, created_at FROM {$this->get_table_name()} ORDER BY id DESC LIMIT 1", ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$data               = json_decode( $row['data'], true );
		$data['created_at'] = $row['created_at'];
		return $data;
	}

	/**
	 * Renderiza un ícono de información con tooltip nativo (atributo title),
	 * completamente autocontenido vía estilos inline.
	 *
	 * @param string $text Texto explicativo breve.
	 * @return string Markup HTML del ícono.
	 */
	private function info_icon( string $text ): string {
		return sprintf(
			' <span title="%s" style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%%;background:#787c82;color:#fff;font-size:10px;font-weight:700;font-style:normal;cursor:help;line-height:1;vertical-align:middle;">i</span>',
			esc_attr( $text )
		);
	}

	/**
	 * Helper para imprimir una fila de la tabla de Configuración.
	 *
	 * @param string $label Etiqueta de la constante/dato.
	 * @param string $info  Texto del tooltip explicativo.
	 * @param mixed  $value Valor a mostrar.
	 * @param string $type  'value' o 'bool'.
	 * @return void
	 */
	private function render_config_row( string $label, string $info, mixed $value, string $type = 'value' ): void {
		$icon = $this->info_icon( $info );

		if ( $type === 'bool' ) {
			if ( is_null( $value ) ) {
				$display = '<span class="ultron-badge muted">' . esc_html__( 'No definido', 'ultron' ) . '</span>';
			} else {
				$display = $value
					? '<span class="ultron-badge ok">' . esc_html__( 'Sí', 'ultron' ) . '</span>'
					: '<span class="ultron-badge muted">' . esc_html__( 'No', 'ultron' ) . '</span>';
			}
		} else {
			$display = is_null( $value )
				? '<span class="ultron-badge muted">' . esc_html__( 'No definido', 'ultron' ) . '</span>'
				: '<code>' . esc_html( (string) $value ) . '</code>';
		}

		echo '<tr><td>' . esc_html( $label ) . $icon . '</td><td>' . $display . '</td></tr>';
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

		<?php if ( isset( $_GET['maint_toggled'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e( 'Modo mantenimiento actualizado.', 'ultron' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['endpoint_toggled'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php _e( 'Endpoint actualizado. Vuelve a generar un snapshot para ver el cambio reflejado.', 'ultron' ); ?></p>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ultron_wp_monitor_export_history' ); ?>
					<input type="hidden" name="action" value="ultron_wp_monitor_export_history">
					<button type="submit" class="button"><?php _e( 'Exportar historial', 'ultron' ); ?></button>
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
			<thead>
				<tr>
					<th><?php _e( 'Dato', 'ultron' ); ?></th>
					<th><?php _e( 'Valor', 'ultron' ); ?></th>
					<th><?php _e( 'Ir a', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php _e( 'Versión', 'ultron' ); ?><?php echo $this->info_icon( __( 'Versión de WordPress actualmente instalada.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['wordpress']['version'] ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'about.php' ) ); ?>"><?php _e( 'Ver info', 'ultron' ); ?></a></td>
				</tr>
				<tr>
					<td><?php _e( 'Actualización disponible', 'ultron' ); ?><?php echo $this->info_icon( __( 'Indica si hay una nueva versión de WordPress disponible para instalar.', 'ultron' ) ); ?></td>
					<td>
						<?php if ( $snapshot['wordpress']['update_available'] ) : ?>
							<span class="ultron-badge warning">↑ <?php echo esc_html( $snapshot['wordpress']['update_available'] ); ?></span>
						<?php else : ?>
							<span class="ultron-badge ok"><?php _e( 'No', 'ultron' ); ?></span>
						<?php endif; ?>
					</td>
					<td><a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php _e( 'Actualizar', 'ultron' ); ?></a></td>
				</tr>
				<tr>
					<td><?php _e( 'Tema activo', 'ultron' ); ?><?php echo $this->info_icon( __( 'Tema visual actualmente en uso por el sitio.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['wordpress']['theme_name'] . ' (' . $snapshot['wordpress']['theme_version'] . ')' ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>"><?php _e( 'Ver temas', 'ultron' ); ?></a></td>
				</tr>
				<tr>
					<td><?php _e( 'Idioma', 'ultron' ); ?><?php echo $this->info_icon( __( 'Idioma configurado para el panel de administración y el sitio.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['wordpress']['language'] ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'options-general.php#WPLANG' ) ); ?>"><?php _e( 'Cambiar', 'ultron' ); ?></a></td>
				</tr>
				<tr>
					<td><?php _e( 'Zona horaria', 'ultron' ); ?><?php echo $this->info_icon( __( 'Zona horaria usada para fechas y la programación de publicaciones.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['wordpress']['timezone'] ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'options-general.php#timezone_string' ) ); ?>"><?php _e( 'Cambiar', 'ultron' ); ?></a></td>
				</tr>
			</tbody>
		</table>

		<!-- Servidor -->
		<p class="ultron-section-title"><?php _e( 'Servidor', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Dato', 'ultron' ); ?></th>
					<th><?php _e( 'Valor', 'ultron' ); ?></th>
					<th><?php _e( 'Estado', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>PHP<?php echo $this->info_icon( __( 'Lenguaje en el que está escrito WordPress. Una versión más reciente mejora rendimiento y seguridad.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['server']['php_version'] ); ?></td>
					<td>
						<span class="ultron-badge <?php echo esc_attr( $snapshot['server']['php_status']['status'] ); ?>" title="<?php echo esc_attr( $snapshot['server']['php_status']['suggestion'] ); ?>">
							<?php echo esc_html( $snapshot['server']['php_status']['label'] ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td>MySQL / MariaDB<?php echo $this->info_icon( __( 'Motor de base de datos donde WordPress guarda todo el contenido del sitio.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['server']['mysql_version'] ); ?></td>
					<td>
						<span class="ultron-badge <?php echo esc_attr( $snapshot['server']['mysql_status']['status'] ); ?>" title="<?php echo esc_attr( $snapshot['server']['mysql_status']['suggestion'] ); ?>">
							<?php echo esc_html( $snapshot['server']['mysql_status']['label'] ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td><?php _e( 'Sistema operativo', 'ultron' ); ?><?php echo $this->info_icon( __( 'Sistema operativo del servidor donde corre WordPress.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['server']['os'] ); ?></td>
					<td>—</td>
				</tr>
				<tr>
					<td><?php _e( 'Memoria PHP', 'ultron' ); ?><?php echo $this->info_icon( __( 'Cantidad máxima de memoria que PHP puede usar para procesar cada solicitud.', 'ultron' ) ); ?></td>
					<td><?php echo esc_html( $snapshot['server']['memory_limit'] ); ?></td>
					<td>
						<span class="ultron-badge <?php echo esc_attr( $snapshot['server']['memory_status']['status'] ); ?>" title="<?php echo esc_attr( $snapshot['server']['memory_status']['suggestion'] ); ?>">
							<?php echo esc_html( $snapshot['server']['memory_status']['label'] ); ?>
						</span>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Configuración (wp-config.php) -->
		<p class="ultron-section-title"><?php _e( 'Configuración (wp-config.php)', 'ultron' ); ?></p>
		<table class="ultron-table">
			<thead>
				<tr>
					<th><?php _e( 'Dato', 'ultron' ); ?></th>
					<th><?php _e( 'Valor', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$cfg = $snapshot['config'];

				$this->render_config_row( __( 'URL del sitio', 'ultron' ), __( 'Dirección pública desde la que se visita el sitio.', 'ultron' ), $cfg['site_url'] );
				$this->render_config_row( __( 'URL de WordPress', 'ultron' ), __( 'Dirección donde están instalados los archivos de WordPress.', 'ultron' ), $cfg['wp_url'] );
				$this->render_config_row( 'WP_HOME', __( 'URL que WordPress usa para generar enlaces del contenido visible al público.', 'ultron' ), $cfg['wp_home'] );
				$this->render_config_row( 'WP_SITEURL', __( 'URL donde vive el núcleo de WordPress (admin, core). Puede diferir de WP_HOME en instalaciones avanzadas.', 'ultron' ), $cfg['wp_siteurl'] );
				$this->render_config_row( 'WP_CONTENT_DIR', __( 'Carpeta de contenido (temas, plugins, uploads) dentro de la instalación. Se muestra solo el nombre de carpeta por seguridad.', 'ultron' ), $cfg['wp_content_dir'] );
				$this->render_config_row( 'WP_CONTENT_URL', __( 'URL pública de la carpeta de contenido (wp-content).', 'ultron' ), $cfg['wp_content_url'] );
				$this->render_config_row( 'UPLOADS', __( 'Ruta personalizada para subidas, si el sitio movió la carpeta uploads de su ubicación por defecto.', 'ultron' ), $cfg['uploads'] );
				$this->render_config_row( __( 'Prefijo de tablas', 'ultron' ), __( 'Prefijo usado en los nombres de las tablas de la base de datos.', 'ultron' ), $cfg['table_prefix'] );
				$this->render_config_row( __( 'Multisitio', 'ultron' ), __( 'Indica si esta instalación gestiona varios sitios desde un mismo WordPress.', 'ultron' ), $cfg['multisite'], 'bool' );

				$this->render_config_row( 'WP_DEBUG', __( 'Activa el modo de depuración de WordPress. Solo informativo: no se puede cambiar desde aquí porque vive en wp-config.php.', 'ultron' ), $cfg['wp_debug'], 'bool' );
				$this->render_config_row( 'WP_DEBUG_LOG', __( 'Guarda los errores de depuración en un archivo de log en vez de mostrarlos. Solo informativo.', 'ultron' ), $cfg['wp_debug_log'], 'bool' );
				$this->render_config_row( 'WP_DEBUG_DISPLAY', __( 'Muestra los errores de depuración directamente en pantalla. Solo informativo.', 'ultron' ), $cfg['wp_debug_display'], 'bool' );
				$this->render_config_row( 'SCRIPT_DEBUG', __( 'Usa versiones sin minificar de los archivos JS/CSS del core, útil para depurar. Solo informativo.', 'ultron' ), $cfg['script_debug'], 'bool' );
				$this->render_config_row( 'SAVEQUERIES', __( 'Guarda todas las consultas SQL ejecutadas para depuración. Puede afectar el rendimiento si queda activo en producción. Solo informativo.', 'ultron' ), $cfg['savequeries'], 'bool' );

				$this->render_config_row( 'WP_CACHE', __( 'Indica si hay un sistema de caché de páginas activo. Normalmente lo activa un plugin de caché automáticamente.', 'ultron' ), $cfg['wp_cache'], 'bool' );
				$this->render_config_row( 'AUTOSAVE_INTERVAL', __( 'Cada cuántos segundos WordPress autoguarda un borrador mientras editas.', 'ultron' ), $cfg['autosave_interval'] !== null ? $cfg['autosave_interval'] . ' ' . __( 'segundos', 'ultron' ) : null );
				$this->render_config_row( 'EMPTY_TRASH_DAYS', __( 'Días que un elemento permanece en la papelera antes de eliminarse automáticamente.', 'ultron' ), $cfg['empty_trash_days'] );
				$this->render_config_row( 'WP_POST_REVISIONS', __( 'Número máximo de revisiones guardadas por publicación.', 'ultron' ), $cfg['wp_post_revisions'] );
				$this->render_config_row( 'COMPRESS_SCRIPTS', __( 'Comprime (gzip) los archivos JavaScript del admin antes de enviarlos al navegador.', 'ultron' ), $cfg['compress_scripts'], 'bool' );
				$this->render_config_row( 'COMPRESS_CSS', __( 'Comprime (gzip) los archivos CSS del admin antes de enviarlos al navegador.', 'ultron' ), $cfg['compress_css'], 'bool' );

				$this->render_config_row( 'DISALLOW_FILE_EDIT', __( 'Deshabilita el editor de archivos de temas/plugins dentro del admin, evitando edición de código vía navegador.', 'ultron' ), $cfg['disallow_file_edit'], 'bool' );
				$this->render_config_row( 'DISALLOW_FILE_MODS', __( 'Deshabilita instalar, actualizar o editar plugins/temas desde el admin (más estricto que DISALLOW_FILE_EDIT).', 'ultron' ), $cfg['disallow_file_mods'], 'bool' );
				$this->render_config_row( 'FORCE_SSL_ADMIN', __( 'Obliga a que el panel de administración solo sea accesible vía HTTPS.', 'ultron' ), $cfg['force_ssl_admin'], 'bool' );
				$this->render_config_row( 'WP_HTTP_BLOCK_EXTERNAL', __( 'Bloquea que WordPress haga peticiones HTTP hacia el exterior, salvo dominios permitidos explícitamente.', 'ultron' ), $cfg['http_block_external'], 'bool' );

				$this->render_config_row( 'DB_HOST', __( 'Servidor donde corre la base de datos.', 'ultron' ), $cfg['db_host'] );
				$this->render_config_row( 'DB_CHARSET', __( 'Juego de caracteres usado por la base de datos (afecta soporte de idiomas y emojis).', 'ultron' ), $cfg['db_charset'] );
				$this->render_config_row( 'DB_COLLATE', __( 'Reglas de comparación y orden de texto en la base de datos (mayúsculas, acentos). Normalmente vacío y usa el valor por defecto.', 'ultron' ), $cfg['db_collate'] );

				$this->render_config_row( 'upload_max_filesize', __( 'Tamaño máximo permitido por archivo subido.', 'ultron' ), $cfg['upload_max_filesize'] );
				$this->render_config_row( 'post_max_size', __( 'Tamaño máximo total permitido por solicitud (debe ser igual o mayor que upload_max_filesize).', 'ultron' ), $cfg['post_max_size'] );
				$this->render_config_row( 'max_execution_time', __( 'Segundos máximos que un script PHP puede ejecutarse antes de ser detenido.', 'ultron' ), $cfg['max_execution_time'] . ' ' . __( 'segundos', 'ultron' ) );
				$this->render_config_row( 'WP_MEMORY_LIMIT', __( 'Límite de memoria que WordPress solicita para el front-end. Solo informativo, vive en wp-config.php.', 'ultron' ), $cfg['wp_memory_limit'] );
				$this->render_config_row( 'WP_MAX_MEMORY_LIMIT', __( 'Límite de memoria que WordPress solicita para tareas del admin. Solo informativo, vive en wp-config.php.', 'ultron' ), $cfg['wp_max_memory_limit'] );
				?>

				<tr>
					<td><?php _e( 'Modo mantenimiento', 'ultron' ); ?><?php echo $this->info_icon( __( 'Muestra una pantalla de mantenimiento a los visitantes públicos; el admin sigue accediendo normalmente.', 'ultron' ) ); ?></td>
					<td>
						<?php echo $cfg['maintenance_mode']
							? '<span class="ultron-badge warning">' . __( 'Activo', 'ultron' ) . '</span>'
							: '<span class="ultron-badge ok">' . __( 'Inactivo', 'ultron' ) . '</span>'; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:8px;">
							<?php wp_nonce_field( 'ultron_wp_monitor_toggle_maint' ); ?>
							<input type="hidden" name="action" value="ultron_wp_monitor_toggle_maint">
							<button type="submit" class="button" style="padding:0 8px; height:24px; line-height:22px; font-size:12px;">
								<?php echo $cfg['maintenance_mode'] ? __( 'Desactivar', 'ultron' ) : __( 'Activar', 'ultron' ); ?>
							</button>
						</form>
					</td>
				</tr>
				<tr>
					<td>DISABLE_WP_CRON<?php echo $this->info_icon( __( 'Indica si el cron simulado de WordPress está deshabilitado. Solo informativo, vive en wp-config.php.', 'ultron' ) ); ?></td>
					<td>
						<?php echo $cfg['disable_wp_cron']
							? '<span class="ultron-badge warning">' . __( 'Deshabilitado', 'ultron' ) . '</span>'
							: '<span class="ultron-badge ok">' . __( 'Habilitado', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
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
				<?php
				$file_explanations = [
					'readme.html'          => __( 'Revela la versión exacta de WordPress. Protégelo eliminándolo o bloqueándolo vía .htaccess.', 'ultron' ),
					'license.txt'          => __( 'Confirma el uso de WordPress, sin datos sensibles. Puedes eliminarlo o bloquearlo sin riesgo.', 'ultron' ),
					'wp-config-sample.php' => __( 'Plantilla de configuración de ejemplo. No contiene credenciales reales, pero revela estructura interna. Elimínalo tras instalar.', 'ultron' ),
					'xmlrpc.php'           => __( 'Endpoint usado por apps externas y Jetpack. Vector común de fuerza bruta. Bloquéalo si no lo necesitas.', 'ultron' ),
					'wp-login.php'         => __( 'Pantalla de inicio de sesión. No se bloquea, se refuerza con límite de intentos o 2FA.', 'ultron' ),
				];
				foreach ( $snapshot['security_files'] as $file => $info ) :
					$explanation = $file_explanations[ $file ] ?? '';
					?>
					<tr>
						<td><code><?php echo esc_html( $file ); ?></code><?php echo $this->info_icon( $explanation ); ?></td>
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
				<?php
				$dir_explanations = [
					'wp-includes' => __( 'Código núcleo de WordPress. Listado habilitado facilita reconocimiento. Bloquéalo con "Options -Indexes" en .htaccess.', 'ultron' ),
					'plugins'     => __( 'Permite ver qué plugins están instalados solo navegando la carpeta, incluso inactivos. Bloquéalo con "Options -Indexes".', 'ultron' ),
				];
				foreach ( $snapshot['security_dirs'] as $name => $info ) :
					$explanation = $dir_explanations[ $name ] ?? '';
					?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code><?php echo $this->info_icon( $explanation ); ?></td>
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
							<td>
								<?php echo esc_html( $p['plugin'] ); ?>
								<?php echo $this->info_icon( sprintf( __( 'Expone la versión instalada de %s, facilitando identificar vulnerabilidades conocidas de esa versión.', 'ultron' ), $p['plugin'] ) ); ?>
							</td>
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
					<td><?php _e( 'Existe', 'ultron' ); ?><?php echo $this->info_icon( __( 'Archivo de configuración del servidor Apache, usado por WordPress para reglas de reescritura de URL y seguridad.', 'ultron' ) ); ?></td>
					<td>
						<?php echo $snapshot['htaccess']['exists']
							? '<span class="ultron-badge ok">' . __( 'Sí', 'ultron' ) . '</span>'
							: '<span class="ultron-badge muted">' . __( 'No', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
				<tr>
					<td><?php _e( 'Accesible públicamente', 'ultron' ); ?><?php echo $this->info_icon( __( 'El archivo .htaccess nunca debería poder descargarse o verse desde el navegador.', 'ultron' ) ); ?></td>
					<td>
						<?php echo $snapshot['htaccess']['exposed']
							? '<span class="ultron-badge danger">' . __( 'Sí — riesgo de seguridad', 'ultron' ) . '</span>'
							: '<span class="ultron-badge ok">' . __( 'No', 'ultron' ) . '</span>'; ?>
					</td>
				</tr>
				<tr>
					<td><?php _e( 'Modificado por', 'ultron' ); ?><?php echo $this->info_icon( __( 'Detecta si algún plugin conocido de seguridad o caché añadió sus propias reglas al archivo.', 'ultron' ) ); ?></td>
					<td>
						<?php if ( ! empty( $snapshot['htaccess']['detected_by'] ) ) : ?>
							<span class="ultron-badge ok">
								<?php echo esc_html( implode( ', ', array_map( fn( $k ) => $this->htaccess_signatures[ $k ] ?? $k, $snapshot['htaccess']['detected_by'] ) ) ); ?>
							</span>
						<?php elseif ( $snapshot['htaccess']['unidentified_mod'] ) : ?>
							<span class="ultron-badge warning"><?php _e( 'Modificado por fuente no identificada', 'ultron' ); ?></span>
						<?php else : ?>
							<span class="ultron-badge muted"><?php _e( 'Sin modificaciones detectadas', 'ultron' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $snapshot['htaccess']['rules_preview'] ) ) : ?>
			<details class="ultron-details" style="max-width:700px; margin-bottom:20px;">
				<summary><?php _e( 'Ver contenido de .htaccess (vista previa)', 'ultron' ); ?></summary>
				<div style="padding:12px; background:#f6f7f7; font-family:monospace; font-size:12px; white-space:pre-wrap;">
					<?php echo esc_html( implode( "\n", $snapshot['htaccess']['rules_preview'] ) ); ?>
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
					<th><?php _e( 'Acción', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$endpoints_info = [
					'rest_api'   => [ 'label' => 'REST API',   'info' => __( 'Permite a aplicaciones y al propio editor de WordPress leer/escribir datos vía peticiones HTTP. La usan el editor de bloques y muchos plugins modernos.', 'ultron' ), 'toggle' => null ],
					'xmlrpc'     => [ 'label' => 'XML-RPC',    'info' => __( 'Protocolo más antiguo para apps externas (apps móviles, Jetpack). Puede desactivarse si no lo usas.', 'ultron' ), 'toggle' => 'xmlrpc' ],
					'pingbacks'  => [ 'label' => 'Pingbacks',  'info' => __( 'Notificaciones automáticas entre sitios al enlazarse. Vector conocido de abuso para amplificación de ataques.', 'ultron' ), 'toggle' => 'ping' ],
					'trackbacks' => [ 'label' => 'Trackbacks', 'info' => __( 'Variante de pingbacks, mismo mecanismo y misma opción de activación/desactivación.', 'ultron' ), 'toggle' => 'ping' ],
					'oembed'     => [ 'label' => 'oEmbed',     'info' => __( 'Convierte URLs (YouTube, Twitter, etc.) en vistas previas incrustadas automáticamente, en ambos sentidos.', 'ultron' ), 'toggle' => 'oembed' ],
					'rss_feed'   => [ 'label' => 'RSS Feed',   'info' => __( 'Permite que lectores RSS y agregadores se suscriban a las actualizaciones del sitio.', 'ultron' ), 'toggle' => 'rss_feed' ],
					'wp_cron'    => [ 'label' => 'WP-Cron',    'info' => __( 'Sistema interno que simula tareas programadas (publicaciones agendadas, limpieza). No se gestiona desde aquí por requerir cambios en wp-config.php.', 'ultron' ), 'toggle' => null ],
				];
				foreach ( $endpoints_info as $key => $info ) :
					$active = $snapshot['endpoints'][ $key ] ?? false;
					?>
					<tr>
						<td><?php echo esc_html( $info['label'] ); ?><?php echo $this->info_icon( $info['info'] ); ?></td>
						<td>
							<?php echo $active
								? '<span class="ultron-badge ok">' . __( 'Activo', 'ultron' ) . '</span>'
								: '<span class="ultron-badge muted">' . __( 'Inactivo', 'ultron' ) . '</span>'; ?>
						</td>
						<td>
							<?php if ( $info['toggle'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'ultron_wp_monitor_toggle_endpoint' ); ?>
									<input type="hidden" name="action" value="ultron_wp_monitor_toggle_endpoint">
									<input type="hidden" name="endpoint" value="<?php echo esc_attr( $info['toggle'] ); ?>">
									<button type="submit" class="button" style="padding:0 8px; height:24px; line-height:22px; font-size:12px;">
										<?php echo $active ? __( 'Desactivar', 'ultron' ) : __( 'Activar', 'ultron' ); ?>
									</button>
								</form>
							<?php else : ?>
								—
							<?php endif; ?>
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
					<th>ID</th>
					<th><?php _e( 'Usuario', 'ultron' ); ?></th>
					<th><?php _e( 'Nombre', 'ultron' ); ?></th>
					<th><?php _e( 'Rol', 'ultron' ); ?></th>
					<th><?php _e( 'Acción', 'ultron' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot['users'] as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user['id'] ); ?></td>
						<td><?php echo esc_html( $user['login'] ); ?></td>
						<td><?php echo esc_html( $user['name'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $user['roles'] ) ); ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $user['id'] ) ); ?>"><?php _e( 'Ver perfil', 'ultron' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
	}

	/**
	 * Renderiza el widget en el Dashboard del hub.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$snapshot = $this->get_latest_snapshot();
		?>
		<div class="ultron-widget">
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
