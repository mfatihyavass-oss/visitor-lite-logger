<?php
/**
 * Plugin Name: Visitor Lite Logger
 * Plugin URI: https://bursa.mayahukuk.com
 * Description: Hafif ve asenkron ziyaret kaydı tutan WordPress eklentisi.
 * Version: 3.1.0
 * Author: Maya Hukuk
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: visitor-lite-logger
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'VLL_VERSION' ) ) {
	define( 'VLL_VERSION', '3.1.0' );
}

if ( ! defined( 'VLL_REST_NAMESPACE' ) ) {
	define( 'VLL_REST_NAMESPACE', 'visitor-lite-logger/v1' );
}

if ( ! defined( 'VLL_REST_ROUTE' ) ) {
	define( 'VLL_REST_ROUTE', '/log' );
}

if ( ! defined( 'VLL_SCRIPT_ROUTE' ) ) {
	define( 'VLL_SCRIPT_ROUTE', '/script.js' );
}

if ( ! defined( 'VLL_CLEANUP_HOOK' ) ) {
	define( 'VLL_CLEANUP_HOOK', 'vll_daily_cleanup' );
}

if ( ! defined( 'VLL_EXPORT_ACTION' ) ) {
	define( 'VLL_EXPORT_ACTION', 'vll_export_csv' );
}

if ( ! defined( 'VLL_CLEAR_ACTION' ) ) {
	define( 'VLL_CLEAR_ACTION', 'vll_clear_logs' );
}

if ( ! defined( 'VLL_THROTTLE_SECONDS' ) ) {
	define( 'VLL_THROTTLE_SECONDS', 600 );
}

if ( ! defined( 'VLL_RETENTION_DAYS' ) ) {
	define( 'VLL_RETENTION_DAYS', 30 );
}

if ( ! defined( 'VLL_LIST_PER_PAGE' ) ) {
	define( 'VLL_LIST_PER_PAGE', 50 );
}

if ( ! defined( 'VLL_MAX_URL_LENGTH' ) ) {
	define( 'VLL_MAX_URL_LENGTH', 2000 );
}

if ( ! defined( 'VLL_MAX_TITLE_LENGTH' ) ) {
	define( 'VLL_MAX_TITLE_LENGTH', 255 );
}

if ( ! defined( 'VLL_MAX_REFERRER_LENGTH' ) ) {
	define( 'VLL_MAX_REFERRER_LENGTH', 1000 );
}

if ( ! defined( 'VLL_MAX_UA_LENGTH' ) ) {
	define( 'VLL_MAX_UA_LENGTH', 512 );
}

if ( ! defined( 'VLL_SETTINGS_OPTION' ) ) {
	define( 'VLL_SETTINGS_OPTION', 'vll_settings' );
}

if ( ! defined( 'VLL_SETTINGS_GROUP' ) ) {
	define( 'VLL_SETTINGS_GROUP', 'vll_settings_group' );
}

if ( ! defined( 'VLL_SETTINGS_PAGE' ) ) {
	define( 'VLL_SETTINGS_PAGE', 'vll-settings' );
}

if ( ! defined( 'VLL_SCHEMA_OPTION' ) ) {
	define( 'VLL_SCHEMA_OPTION', 'vll_schema_version' );
}

register_activation_hook( __FILE__, 'vll_activate' );
register_deactivation_hook( __FILE__, 'vll_deactivate' );
register_uninstall_hook( __FILE__, 'vll_uninstall' );

add_action( 'plugins_loaded', 'vll_maybe_upgrade_schema' );

add_action( 'rest_api_init', 'vll_register_rest_routes' );
add_filter( 'rest_pre_serve_request', 'vll_rest_pre_serve_script', 20, 4 );

add_action( 'wp_enqueue_scripts', 'vll_enqueue_frontend_logger', 99 );

add_action( 'admin_menu', 'vll_register_admin_menu' );
add_action( 'admin_init', 'vll_register_settings' );
add_action( 'admin_post_' . VLL_EXPORT_ACTION, 'vll_handle_csv_export' );
add_action( 'admin_post_' . VLL_CLEAR_ACTION, 'vll_handle_clear_logs' );

add_action( VLL_CLEANUP_HOOK, 'vll_cleanup_old_logs' );

if ( is_admin() && ! class_exists( 'VLL_Log_List_Table' ) ) {
	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	/**
	 * Admin log table class.
	 */
	class VLL_Log_List_Table extends WP_List_Table {
		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct(
				array(
					'singular' => 'vll_log',
					'plural'   => 'vll_logs',
					'ajax'     => false,
				)
			);
		}

		/**
		 * Returns table columns.
		 *
		 * @return array
		 */
		public function get_columns() {
			return array(
				'visit_time'      => __( 'Tarih', 'visitor-lite-logger' ),
				'visitor_ip'      => __( 'IP', 'visitor-lite-logger' ),
				'visited_url'     => __( 'Ziyaret Edilen URL', 'visitor-lite-logger' ),
				'page_title'      => __( 'Sayfa Başlığı', 'visitor-lite-logger' ),
				'referrer'        => __( 'Referrer', 'visitor-lite-logger' ),
				'time_on_page_ms' => __( 'Sayfada Kalma', 'visitor-lite-logger' ),
				'user_agent'      => __( 'User-Agent', 'visitor-lite-logger' ),
			);
		}

		/**
		 * Returns sortable columns.
		 *
		 * @return array
		 */
		protected function get_sortable_columns() {
			return array(
				'visit_time'  => array( 'visit_time', true ),
				'visitor_ip'  => array( 'visitor_ip', false ),
				'visited_url' => array( 'visited_url', false ),
			);
		}

		/**
		 * Returns primary column name.
		 *
		 * @return string
		 */
		protected function get_default_primary_column_name() {
			return 'visited_url';
		}

		/**
		 * Renders no-items text.
		 *
		 * @return void
		 */
		public function no_items() {
			echo esc_html__( 'Kayıt bulunamadı.', 'visitor-lite-logger' );
		}

		/**
		 * Renders extra table navigation controls.
		 *
		 * @param string $which Position: top or bottom.
		 * @return void
		 */
		protected function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			$start_date = vll_get_admin_start_date();
			$end_date   = vll_get_admin_end_date();

			echo '<div class="alignleft actions">';
			echo '<label class="screen-reader-text" for="vll_start_date">' . esc_html__( 'Başlangıç Tarihi', 'visitor-lite-logger' ) . '</label>';
			echo '<input type="date" id="vll_start_date" name="vll_start_date" value="' . esc_attr( $start_date ) . '" />';

			echo '<label class="screen-reader-text" for="vll_end_date">' . esc_html__( 'Bitiş Tarihi', 'visitor-lite-logger' ) . '</label>';
			echo '<input type="date" id="vll_end_date" name="vll_end_date" value="' . esc_attr( $end_date ) . '" style="margin-left:6px;" />';

			submit_button( __( 'Filtrele', 'visitor-lite-logger' ), 'button', 'filter_action', false, array( 'id' => 'vll-filter-submit' ) );
			echo '</div>';
		}

		/**
		 * Renders visit_time column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_visit_time( $item ) {
			return esc_html( isset( $item['visit_time'] ) ? (string) $item['visit_time'] : '' );
		}

		/**
		 * Renders visitor_ip column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_visitor_ip( $item ) {
			return esc_html( isset( $item['visitor_ip'] ) ? (string) $item['visitor_ip'] : '' );
		}

		/**
		 * Renders visited_url column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_visited_url( $item ) {
			$full_url  = isset( $item['visited_url'] ) ? (string) $item['visited_url'] : '';
			$short_url = vll_truncate_text( $full_url, 110 );
			$safe_url  = esc_url( $full_url );

			if ( '' !== $safe_url ) {
				return '<a href="' . esc_url( $safe_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $full_url ) . '">' . esc_html( $short_url ) . '</a>';
			}

			return esc_html( $short_url );
		}

		/**
		 * Renders page_title column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_page_title( $item ) {
			$full_title  = isset( $item['page_title'] ) ? (string) $item['page_title'] : '';
			$short_title = vll_truncate_text( $full_title, 90 );

			return '<span title="' . esc_attr( $full_title ) . '">' . esc_html( $short_title ) . '</span>';
		}

		/**
		 * Renders referrer column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_referrer( $item ) {
			$full_referrer  = isset( $item['referrer'] ) ? (string) $item['referrer'] : '';
			$short_referrer = vll_truncate_text( $full_referrer, 110 );
			$safe_referrer  = esc_url( $full_referrer );

			if ( '' !== $safe_referrer ) {
				return '<a href="' . esc_url( $safe_referrer ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $full_referrer ) . '">' . esc_html( $short_referrer ) . '</a>';
			}

			return esc_html( $short_referrer );
		}

		/**
		 * Renders time_on_page_ms column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_time_on_page_ms( $item ) {
			$duration_ms = isset( $item['time_on_page_ms'] ) ? absint( $item['time_on_page_ms'] ) : 0;
			return esc_html( vll_format_duration_ms( $duration_ms ) );
		}

		/**
		 * Renders user_agent column.
		 *
		 * @param array $item Row.
		 * @return string
		 */
		protected function column_user_agent( $item ) {
			$full_ua  = isset( $item['user_agent'] ) ? (string) $item['user_agent'] : '';
			$short_ua = vll_truncate_text( $full_ua, 140 );

			return '<span title="' . esc_attr( $full_ua ) . '">' . esc_html( $short_ua ) . '</span>';
		}

		/**
		 * Fallback renderer.
		 *
		 * @param array  $item Row.
		 * @param string $column_name Column.
		 * @return string
		 */
		protected function column_default( $item, $column_name ) {
			if ( isset( $item[ $column_name ] ) ) {
				return esc_html( (string) $item[ $column_name ] );
			}

			return '';
		}

		/**
		 * Prepares list table items.
		 *
		 * @return void
		 */
		public function prepare_items() {
			global $wpdb;

			$table_name = vll_get_table_name();
			$per_page   = absint( apply_filters( 'vll_admin_list_per_page', VLL_LIST_PER_PAGE ) );
			if ( $per_page < 1 ) {
				$per_page = VLL_LIST_PER_PAGE;
			}

			$current_page = $this->get_pagenum();
			$offset       = ( $current_page - 1 ) * $per_page;

			$search_query = vll_get_admin_search_query();
			$start_date   = vll_get_admin_start_date();
			$end_date     = vll_get_admin_end_date();
			$where_data   = vll_get_search_where_data( $search_query, $start_date, $end_date );

			$allowed_orderby = array(
				'visit_time',
				'visitor_ip',
				'visited_url',
			);
			$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'visit_time';
			if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
				$orderby = 'visit_time';
			}

			$order = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'desc';
			$order = ( 'asc' === $order ) ? 'ASC' : 'DESC';

			$count_sql = "SELECT COUNT(*) FROM {$table_name} {$where_data['sql']}";
			$count_sql = $wpdb->prepare( $count_sql, $where_data['args'] );

			$total_items = (int) $wpdb->get_var( $count_sql );

			$query_sql = "SELECT id, visitor_ip, visited_url, user_agent, referrer, page_title, time_on_page_ms, visit_time
				FROM {$table_name}
				{$where_data['sql']}
				ORDER BY {$orderby} {$order}
				LIMIT %d OFFSET %d";

			$query_args = array_merge(
				$where_data['args'],
				array(
					$per_page,
					$offset,
				)
			);
			$query_sql = $wpdb->prepare( $query_sql, $query_args );

			$this->items = $wpdb->get_results( $query_sql, ARRAY_A );

			$columns  = $this->get_columns();
			$hidden   = array();
			$sortable = $this->get_sortable_columns();
			$this->_column_headers = array( $columns, $hidden, $sortable, $this->get_default_primary_column_name() );

			$this->set_pagination_args(
				array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total_items / $per_page ),
				)
			);
		}
	}
}

/**
 * Returns the plugin table name.
 *
 * @return string
 */
function vll_get_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'visitor_logs';
}

/**
 * Returns default plugin settings.
 *
 * @return array
 */
function vll_get_default_settings() {
	return array(
		'retention_days'           => 30,
		'throttle_seconds'         => 600,
		'anonymize_ip'             => 1,
		'delete_data_on_uninstall' => 0,
	);
}

/**
 * Returns merged plugin settings.
 *
 * @return array
 */
function vll_get_settings() {
	$defaults = vll_get_default_settings();
	$settings = get_option( VLL_SETTINGS_OPTION, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings = wp_parse_args( $settings, $defaults );

	return array(
		'retention_days'           => max( 1, absint( $settings['retention_days'] ) ),
		'throttle_seconds'         => max( 1, absint( $settings['throttle_seconds'] ) ),
		'anonymize_ip'             => ! empty( $settings['anonymize_ip'] ) ? 1 : 0,
		'delete_data_on_uninstall' => ! empty( $settings['delete_data_on_uninstall'] ) ? 1 : 0,
	);
}

/**
 * Returns a single setting value.
 *
 * @param string $key Setting key.
 * @param mixed  $fallback Fallback value.
 * @return mixed
 */
function vll_get_setting( $key, $fallback = null ) {
	$settings = vll_get_settings();

	if ( array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}

	return $fallback;
}

/**
 * Upgrades schema when plugin version changes.
 *
 * @return void
 */
function vll_maybe_upgrade_schema() {
	$stored_version = get_option( VLL_SCHEMA_OPTION, '' );
	if ( VLL_VERSION === $stored_version ) {
		return;
	}

	vll_create_logs_table();
	update_option( VLL_SCHEMA_OPTION, VLL_VERSION, false );
}

/**
 * Plugin activation callback.
 *
 * @return void
 */
function vll_activate() {
	vll_create_logs_table();
	vll_schedule_cleanup_event();
	update_option( VLL_SCHEMA_OPTION, VLL_VERSION, false );

	$existing_settings = get_option( VLL_SETTINGS_OPTION, null );
	if ( null === $existing_settings ) {
		add_option( VLL_SETTINGS_OPTION, vll_get_default_settings() );
	}
}

/**
 * Plugin deactivation callback.
 *
 * @return void
 */
function vll_deactivate() {
	wp_unschedule_hook( VLL_CLEANUP_HOOK );
}

/**
 * Plugin uninstall callback.
 *
 * @return void
 */
function vll_uninstall() {
	$settings           = get_option( VLL_SETTINGS_OPTION, array() );
	$should_delete_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	if ( $should_delete_data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'visitor_logs';
		// Tam kaldırma istenirse tablo drop işlemi burada aktif edilebilir.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( VLL_SETTINGS_OPTION );
	}
}

/**
 * Creates custom logs table.
 *
 * @return void
 */
function vll_create_logs_table() {
	global $wpdb;

	$table_name      = vll_get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		visitor_ip VARCHAR(45) NOT NULL,
		visited_url TEXT NOT NULL,
		user_agent TEXT NULL,
		referrer TEXT NULL,
		page_title TEXT NULL,
		time_on_page_ms INT UNSIGNED NOT NULL DEFAULT 0,
		visit_time DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY visit_time (visit_time),
		KEY visitor_ip (visitor_ip)
	) {$charset_collate};";

	dbDelta( $sql );
}

/**
 * Schedules the daily cleanup event.
 *
 * @return void
 */
function vll_schedule_cleanup_event() {
	if ( ! wp_next_scheduled( VLL_CLEANUP_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', VLL_CLEANUP_HOOK );
	}
}

/**
 * Cleans logs older than retention period.
 *
 * @return void
 */
function vll_cleanup_old_logs() {
	global $wpdb;

	$retention_days = absint( vll_get_setting( 'retention_days', VLL_RETENTION_DAYS ) );
	$retention_days = absint( apply_filters( 'vll_retention_days', $retention_days ) );
	if ( $retention_days < 1 ) {
		return;
	}

	$table_name = vll_get_table_name();
	$now_local  = current_time( 'mysql' );

	$sql = $wpdb->prepare(
		"DELETE FROM {$table_name} WHERE visit_time < DATE_SUB(%s, INTERVAL %d DAY)",
		$now_local,
		$retention_days
	);

	$wpdb->query( $sql );
}

/**
 * Registers REST routes.
 *
 * @return void
 */
function vll_register_rest_routes() {
	register_rest_route(
		VLL_REST_NAMESPACE,
		VLL_REST_ROUTE,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'vll_rest_log_visit',
			'permission_callback' => '__return_true',
			'args'                => array(
				'visited_url'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
				'page_title'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'referrer'        => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'time_on_page_ms' => array(
					'type'              => 'integer',
					'required'          => false,
					'sanitize_callback' => 'absint',
				),
				'nonce'           => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		VLL_REST_NAMESPACE,
		VLL_SCRIPT_ROUTE,
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'vll_rest_script_payload',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * REST callback for logging visitor.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function vll_rest_log_visit( WP_REST_Request $request ) {
	if ( is_user_logged_in() ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'vll_log_visit' ) ) {
		return new WP_Error(
			'vll_invalid_nonce',
			__( 'Geçersiz istek.', 'visitor-lite-logger' ),
			array( 'status' => 403 )
		);
	}

	$user_agent = vll_get_user_agent();
	if ( '' === $user_agent || vll_is_known_bot( $user_agent ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$visited_url = esc_url_raw( (string) $request->get_param( 'visited_url' ) );
	$visited_url = vll_truncate_text( $visited_url, VLL_MAX_URL_LENGTH );

	if ( '' === $visited_url || vll_is_technical_url( $visited_url ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$referrer        = sanitize_text_field( (string) $request->get_param( 'referrer' ) );
	$page_title      = sanitize_text_field( (string) $request->get_param( 'page_title' ) );
	$time_on_page_ms = absint( $request->get_param( 'time_on_page_ms' ) );

	$referrer        = vll_truncate_text( $referrer, VLL_MAX_REFERRER_LENGTH );
	$page_title      = vll_truncate_text( $page_title, VLL_MAX_TITLE_LENGTH );
	$time_on_page_ms = min( $time_on_page_ms, DAY_IN_SECONDS * 1000 );

	$visitor_ip = vll_get_client_ip();
	if ( '' === $visitor_ip ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	$throttle_seconds = absint( vll_get_setting( 'throttle_seconds', VLL_THROTTLE_SECONDS ) );
	$throttle_seconds = absint( apply_filters( 'vll_throttle_seconds', $throttle_seconds ) );
	if ( $throttle_seconds < 1 ) {
		$throttle_seconds = VLL_THROTTLE_SECONDS;
	}

	$transient_key = 'vll_seen_' . md5( $visitor_ip . '|' . $visited_url );
	if ( get_transient( $transient_key ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	set_transient( $transient_key, 1, $throttle_seconds );

	global $wpdb;

	$inserted = $wpdb->insert(
		vll_get_table_name(),
		array(
			'visitor_ip'  => $visitor_ip,
			'visited_url' => $visited_url,
			'user_agent'  => vll_truncate_text( $user_agent, VLL_MAX_UA_LENGTH ),
			'referrer'    => $referrer,
			'page_title'  => $page_title,
			'time_on_page_ms' => $time_on_page_ms,
			'visit_time'       => current_time( 'mysql' ),
		),
		array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
		)
	);

	if ( false === $inserted ) {
		return new WP_Error(
			'vll_insert_failed',
			__( 'Log kaydı eklenemedi.', 'visitor-lite-logger' ),
			array( 'status' => 500 )
		);
	}

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * REST callback for dynamic JS payload.
 *
 * @return WP_REST_Response
 */
function vll_rest_script_payload() {
	return new WP_REST_Response(
		array(
			'script' => vll_get_dynamic_frontend_script(),
		),
		200
	);
}

/**
 * Sends JS output for script route.
 *
 * @param bool            $served  Whether request served.
 * @param WP_HTTP_Response $result  Result response.
 * @param WP_REST_Request $request Request.
 * @param WP_REST_Server  $server  Server.
 * @return bool
 */
function vll_rest_pre_serve_script( $served, $result, $request, $server ) {
	if ( true === $served || ! ( $request instanceof WP_REST_Request ) ) {
		return $served;
	}

	if ( vll_get_script_route_path() !== $request->get_route() ) {
		return $served;
	}

	$script = '';
	if ( $result instanceof WP_HTTP_Response ) {
		$data = $result->get_data();
		if ( is_array( $data ) && isset( $data['script'] ) ) {
			$script = (string) $data['script'];
		}
	}

	$server->send_header( 'Content-Type', 'application/javascript; charset=' . get_option( 'blog_charset' ) );
	$server->send_header( 'X-Content-Type-Options', 'nosniff' );
	foreach ( wp_get_nocache_headers() as $name => $value ) {
		$server->send_header( $name, $value );
	}

	echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return true;
}

/**
 * Returns REST route path for script endpoint.
 *
 * @return string
 */
function vll_get_script_route_path() {
	return '/' . trim( VLL_REST_NAMESPACE . VLL_SCRIPT_ROUTE, '/' );
}

/**
 * Builds dynamic frontend logger script.
 *
 * @return string
 */
function vll_get_dynamic_frontend_script() {
	$config = array(
		'endpoint' => esc_url_raw( rest_url( VLL_REST_NAMESPACE . VLL_REST_ROUTE ) ),
		'nonce'    => wp_create_nonce( 'vll_log_visit' ),
	);

	$config_json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $config_json ) {
		$config_json = '{}';
	}

	return "(function () {\n"
		. "\t'use strict';\n\n"
		. "\tvar cfg = " . $config_json . ";\n"
		. "\tvar sent = false;\n"
		. "\tvar startedAt = (window.performance && performance.timeOrigin) ? performance.timeOrigin : Date.now();\n\n"
		. "\tif (!cfg.endpoint || !cfg.nonce) {\n"
		. "\t\treturn;\n"
		. "\t}\n\n"
		. "\tfunction vllGetDurationMs() {\n"
		. "\t\tvar duration = Date.now() - startedAt;\n"
		. "\t\tif (!isFinite(duration) || duration < 0) {\n"
		. "\t\t\treturn 0;\n"
		. "\t\t}\n"
		. "\t\tif (duration > 86400000) {\n"
		. "\t\t\treturn 86400000;\n"
		. "\t\t}\n"
		. "\t\treturn Math.round(duration);\n"
		. "\t}\n\n"
		. "\tfunction vllSendLog() {\n"
		. "\t\tif (sent) {\n"
		. "\t\t\treturn;\n"
		. "\t\t}\n"
		. "\t\ttry {\n"
		. "\t\t\tvar payload = {\n"
		. "\t\t\t\tvisited_url: window.location.href || '',\n"
		. "\t\t\t\tpage_title: document.title || '',\n"
		. "\t\t\t\treferrer: document.referrer || '',\n"
		. "\t\t\t\ttime_on_page_ms: vllGetDurationMs(),\n"
		. "\t\t\t\tnonce: cfg.nonce\n"
		. "\t\t\t};\n"
		. "\t\t\tvar body = JSON.stringify(payload);\n\n"
		. "\t\t\tif (navigator.sendBeacon) {\n"
		. "\t\t\t\tvar blob = new Blob([body], { type: 'application/json; charset=UTF-8' });\n"
		. "\t\t\t\tvar queued = navigator.sendBeacon(cfg.endpoint, blob);\n"
		. "\t\t\t\tif (queued) {\n"
		. "\t\t\t\t\tsent = true;\n"
		. "\t\t\t\t\treturn;\n"
		. "\t\t\t\t}\n"
		. "\t\t\t}\n\n"
		. "\t\t\tif (window.fetch) {\n"
		. "\t\t\t\tsent = true;\n"
		. "\t\t\t\twindow.fetch(cfg.endpoint, {\n"
		. "\t\t\t\t\tmethod: 'POST',\n"
		. "\t\t\t\t\tcredentials: 'same-origin',\n"
		. "\t\t\t\t\tkeepalive: true,\n"
		. "\t\t\t\t\theaders: {\n"
		. "\t\t\t\t\t\t'Content-Type': 'application/json'\n"
		. "\t\t\t\t\t},\n"
		. "\t\t\t\t\tbody: body\n"
		. "\t\t\t\t}).catch(function () {\n"
		. "\t\t\t\t\tsent = false;\n"
		. "\t\t\t\t});\n"
		. "\t\t\t}\n"
		. "\t\t} catch (e) {}\n"
		. "\t}\n\n"
		. "\tfunction vllOnVisibilityChange() {\n"
		. "\t\tif (document.visibilityState === 'hidden') {\n"
		. "\t\t\tvllSendLog();\n"
		. "\t\t}\n"
		. "\t}\n\n"
		. "\tdocument.addEventListener('visibilitychange', vllOnVisibilityChange);\n"
		. "\twindow.addEventListener('pagehide', vllSendLog);\n"
		. "}());\n";
}

/**
 * Enqueues frontend logger script for guests.
 *
 * @return void
 */
function vll_enqueue_frontend_logger() {
	if ( ! vll_should_enqueue_frontend_script() ) {
		return;
	}

	$script_url = add_query_arg(
		array(
			'vllv' => rawurlencode( VLL_VERSION ),
		),
		rest_url( VLL_REST_NAMESPACE . VLL_SCRIPT_ROUTE )
	);

	wp_enqueue_script(
		'vll-frontend-logger',
		$script_url,
		array(),
		VLL_VERSION,
		true
	);
}

/**
 * Checks if frontend logger script should be enqueued.
 *
 * @return bool
 */
function vll_should_enqueue_frontend_script() {
	if ( is_user_logged_in() ) {
		return false;
	}

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}

	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return false;
	}

	if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
		return false;
	}

	if ( function_exists( 'wp_is_rest_endpoint' ) && wp_is_rest_endpoint() ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( function_exists( 'is_login' ) && is_login() ) {
		return false;
	}

	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return false;
	}

	if ( function_exists( 'is_preview' ) && is_preview() ) {
		return false;
	}

	if ( function_exists( 'is_trackback' ) && is_trackback() ) {
		return false;
	}

	$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	if ( 'wp-login.php' === $pagenow || 'admin-ajax.php' === $pagenow ) {
		return false;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( vll_is_technical_uri( $request_uri ) ) {
		return false;
	}

	return true;
}

/**
 * Checks if URI represents a technical endpoint.
 *
 * @param string $request_uri Raw request URI.
 * @return bool
 */
function vll_is_technical_uri( $request_uri ) {
	$parts = wp_parse_url( (string) $request_uri );
	if ( false === $parts ) {
		return false;
	}

	$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

	if ( vll_is_technical_path( $path ) ) {
		return true;
	}

	if ( vll_has_technical_query_flags( $query ) ) {
		return true;
	}

	return false;
}

/**
 * Checks if URL points to technical areas.
 *
 * @param string $url URL.
 * @return bool
 */
function vll_is_technical_url( $url ) {
	$parsed = wp_parse_url( $url );
	if ( false === $parsed ) {
		return true;
	}

	$path  = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
	$query = isset( $parsed['query'] ) ? (string) $parsed['query'] : '';

	if ( vll_is_technical_path( $path ) || vll_has_technical_query_flags( $query ) ) {
		return true;
	}

	return false;
}

/**
 * Checks technical path patterns precisely.
 *
 * @param string $path URL path.
 * @return bool
 */
function vll_is_technical_path( $path ) {
	$path = '/' . ltrim( strtolower( (string) $path ), '/' );
	$path = ( '/' === $path ) ? $path : rtrim( $path, '/' );

	if ( '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) ) {
		return true;
	}

	if ( '/wp-login.php' === $path || '/admin-ajax.php' === $path || '/wp-admin/admin-ajax.php' === $path ) {
		return true;
	}

	if ( '/xmlrpc.php' === $path ) {
		return true;
	}

	if ( '/wp-json' === $path || 0 === strpos( $path, '/wp-json/' ) ) {
		return true;
	}

	if ( preg_match( '#(?:^|/)feed(?:/|$)#', $path ) ) {
		return true;
	}

	if ( preg_match( '#(?:^|/)(?:wp-sitemap(?:-[a-z0-9-]+)?\.(?:xml|xsl)|sitemap(?:_index)?\.xml|[a-z0-9_-]+-sitemap(?:[0-9]+)?\.xml)(?:$|/)#', $path ) ) {
		return true;
	}

	return false;
}

/**
 * Checks technical query flags.
 *
 * @param string $query Query string.
 * @return bool
 */
function vll_has_technical_query_flags( $query ) {
	if ( '' === $query ) {
		return false;
	}

	parse_str( $query, $query_args );

	if ( ! is_array( $query_args ) || empty( $query_args ) ) {
		return false;
	}

	$technical_keys = array(
		'rest_route',
		'feed',
		'preview',
		'sitemap',
		'sitemap-subtype',
		'sitemap-stylesheet',
	);

	$normalized_query_args = array_change_key_case( $query_args, CASE_LOWER );

	foreach ( $technical_keys as $key ) {
		if ( array_key_exists( $key, $normalized_query_args ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns sanitized visitor IP from server.
 *
 * @return string
 */
function vll_get_client_ip() {
	$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$raw_ip = trim( sanitize_text_field( (string) $raw_ip ) );

	if ( '' === $raw_ip || ! filter_var( $raw_ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$ip = $raw_ip;

	// IP adresi KVKK/GDPR kapsamında kişisel veri sayılabilir. Kullanım öncesinde gizlilik politikası ve aydınlatma metni buna göre değerlendirilmelidir.
	if ( ! empty( vll_get_setting( 'anonymize_ip', 1 ) ) ) {
		$ip = vll_anonymize_ip( $ip );
	}

	return vll_truncate_text( $ip, 45 );
}

/**
 * Optional IP anonymizer.
 *
 * @param string $ip Raw IP.
 * @return string
 */
function vll_anonymize_ip( $ip ) {
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$parts = explode( '.', $ip );
		if ( 4 !== count( $parts ) ) {
			return '';
		}
		$parts[3] = '0';
		return implode( '.', $parts );
	}

	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$packed = @inet_pton( $ip );
		if ( false !== $packed ) {
			$anonymized = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
			$unpacked   = @inet_ntop( $anonymized );
			if ( false !== $unpacked ) {
				return $unpacked;
			}
		}
	}

	return $ip;
}

/**
 * Returns sanitized user agent from server.
 *
 * @return string
 */
function vll_get_user_agent() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
	$ua = sanitize_text_field( (string) $ua );
	$ua = trim( $ua );

	return vll_truncate_text( $ua, VLL_MAX_UA_LENGTH );
}

/**
 * Case-insensitive known bot check.
 *
 * @param string $user_agent User agent.
 * @return bool
 */
function vll_is_known_bot( $user_agent ) {
	$ua = strtolower( $user_agent );
	if ( '' === $ua ) {
		return true;
	}

	$bot_markers = array(
		'googlebot',
		'bingbot',
		'yandex',
		'baiduspider',
		'duckduckbot',
		'slurp',
		'facebookexternalhit',
		'twitterbot',
		'linkedinbot',
		'ahrefsbot',
		'semrushbot',
		'mj12bot',
		'dotbot',
		'petalbot',
		'applebot',
		'bytespider',
		'crawler',
		'spider',
		'bot',
	);

	foreach ( $bot_markers as $marker ) {
		if ( false !== strpos( $ua, $marker ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Registers admin pages under Tools.
 *
 * @return void
 */
function vll_register_admin_menu() {
	add_management_page(
		__( 'Ziyaretçi Kayıtları', 'visitor-lite-logger' ),
		__( 'Ziyaretçi Kayıtları', 'visitor-lite-logger' ),
		'manage_options',
		'vll-visitor-logs',
		'vll_render_admin_page'
	);

	add_submenu_page(
		'tools.php',
		__( 'Visitor Lite Logger Ayarlar', 'visitor-lite-logger' ),
		__( 'VLL Ayarlar', 'visitor-lite-logger' ),
		'manage_options',
		VLL_SETTINGS_PAGE,
		'vll_render_settings_page'
	);
}

/**
 * Registers plugin settings using Settings API.
 *
 * @return void
 */
function vll_register_settings() {
	register_setting(
		VLL_SETTINGS_GROUP,
		VLL_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'vll_sanitize_settings',
			'default'           => vll_get_default_settings(),
		)
	);

	add_settings_section(
		'vll_settings_section_main',
		__( 'Temel Ayarlar', 'visitor-lite-logger' ),
		'vll_render_settings_section_main',
		VLL_SETTINGS_PAGE
	);

	add_settings_field(
		'vll_retention_days',
		__( 'Saklama Süresi (Gün)', 'visitor-lite-logger' ),
		'vll_render_field_retention_days',
		VLL_SETTINGS_PAGE,
		'vll_settings_section_main'
	);

	add_settings_field(
		'vll_throttle_seconds',
		__( 'Throttle Süresi (Saniye)', 'visitor-lite-logger' ),
		'vll_render_field_throttle_seconds',
		VLL_SETTINGS_PAGE,
		'vll_settings_section_main'
	);

	add_settings_field(
		'vll_anonymize_ip',
		__( 'IP Anonimleştir', 'visitor-lite-logger' ),
		'vll_render_field_anonymize_ip',
		VLL_SETTINGS_PAGE,
		'vll_settings_section_main'
	);

	add_settings_field(
		'vll_delete_data_on_uninstall',
		__( 'Kaldırmada Veriyi Sil', 'visitor-lite-logger' ),
		'vll_render_field_delete_data_on_uninstall',
		VLL_SETTINGS_PAGE,
		'vll_settings_section_main'
	);
}

/**
 * Sanitizes settings option.
 *
 * @param array $input Raw form input.
 * @return array
 */
function vll_sanitize_settings( $input ) {
	$defaults = vll_get_default_settings();
	$input    = is_array( $input ) ? $input : array();

	$retention_days   = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : $defaults['retention_days'];
	$throttle_seconds = isset( $input['throttle_seconds'] ) ? absint( $input['throttle_seconds'] ) : $defaults['throttle_seconds'];

	return array(
		'retention_days'           => max( 1, $retention_days ),
		'throttle_seconds'         => max( 1, $throttle_seconds ),
		'anonymize_ip'             => ! empty( $input['anonymize_ip'] ) ? 1 : 0,
		'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ) ? 1 : 0,
	);
}

/**
 * Settings section description callback.
 *
 * @return void
 */
function vll_render_settings_section_main() {
	echo '<p>' . esc_html__( 'Visitor Lite Logger için temel çalışma ayarları.', 'visitor-lite-logger' ) . '</p>';
}

/**
 * Renders retention days field.
 *
 * @return void
 */
function vll_render_field_retention_days() {
	$value = absint( vll_get_setting( 'retention_days', 30 ) );

	echo '<input type="number" min="1" step="1" id="vll_retention_days" name="' . esc_attr( VLL_SETTINGS_OPTION ) . '[retention_days]" value="' . esc_attr( $value ) . '" class="small-text" />';
}

/**
 * Renders throttle seconds field.
 *
 * @return void
 */
function vll_render_field_throttle_seconds() {
	$value = absint( vll_get_setting( 'throttle_seconds', 600 ) );

	echo '<input type="number" min="1" step="1" id="vll_throttle_seconds" name="' . esc_attr( VLL_SETTINGS_OPTION ) . '[throttle_seconds]" value="' . esc_attr( $value ) . '" class="small-text" />';
}

/**
 * Renders anonymize IP checkbox field.
 *
 * @return void
 */
function vll_render_field_anonymize_ip() {
	$value = ! empty( vll_get_setting( 'anonymize_ip', 1 ) ) ? 1 : 0;

	echo '<label for="vll_anonymize_ip">';
	echo '<input type="checkbox" id="vll_anonymize_ip" name="' . esc_attr( VLL_SETTINGS_OPTION ) . '[anonymize_ip]" value="1" ' . checked( 1, $value, false ) . ' />';
	echo ' ' . esc_html__( 'IP adreslerini anonimleştir (önerilir)', 'visitor-lite-logger' );
	echo '</label>';
}

/**
 * Renders delete data on uninstall checkbox field.
 *
 * @return void
 */
function vll_render_field_delete_data_on_uninstall() {
	$value = ! empty( vll_get_setting( 'delete_data_on_uninstall', 0 ) ) ? 1 : 0;

	echo '<label for="vll_delete_data_on_uninstall">';
	echo '<input type="checkbox" id="vll_delete_data_on_uninstall" name="' . esc_attr( VLL_SETTINGS_OPTION ) . '[delete_data_on_uninstall]" value="1" ' . checked( 1, $value, false ) . ' />';
	echo ' ' . esc_html__( 'Eklenti kaldırılırken veritabanı kayıtlarını da sil', 'visitor-lite-logger' );
	echo '</label>';
}

/**
 * Renders plugin settings page.
 *
 * @return void
 */
function vll_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'visitor-lite-logger' ) );
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Visitor Lite Logger Ayarları', 'visitor-lite-logger' ) . '</h1>';
	echo '<form method="post" action="options.php">';

	settings_fields( VLL_SETTINGS_GROUP );
	do_settings_sections( VLL_SETTINGS_PAGE );
	submit_button( esc_html__( 'Ayarları Kaydet', 'visitor-lite-logger' ) );

	echo '</form>';
	echo '</div>';
}

/**
 * Renders admin page.
 *
 * @return void
 */
function vll_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'visitor-lite-logger' ) );
	}

	if ( ! class_exists( 'VLL_Log_List_Table' ) ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Ziyaretçi Kayıtları', 'visitor-lite-logger' ) . '</h1>';
		echo '<p>' . esc_html__( 'Liste tablosu yüklenemedi.', 'visitor-lite-logger' ) . '</p></div>';
		return;
	}

	$list_table = new VLL_Log_List_Table();
	$list_table->prepare_items();

	global $wpdb;
	$table_name = vll_get_table_name();

	$now      = time();
	$last_24h = wp_date( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS, wp_timezone() );

	$total_count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE id >= %d", 0 );
	$total_count     = (int) $wpdb->get_var( $total_count_sql );

	$last_24h_count_sql = $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table_name} WHERE visit_time >= %s",
		$last_24h
	);
	$last_24h_count     = (int) $wpdb->get_var( $last_24h_count_sql );

	$oldest_date_sql = $wpdb->prepare( "SELECT MIN(visit_time) FROM {$table_name} WHERE id >= %d", 0 );
	$oldest_date     = $wpdb->get_var( $oldest_date_sql );

	$search_query = vll_get_admin_search_query();
	$start_date   = vll_get_admin_start_date();
	$end_date     = vll_get_admin_end_date();
	$export_args  = array(
		'action' => VLL_EXPORT_ACTION,
	);
	if ( '' !== $search_query ) {
		$export_args['s'] = $search_query;
	}
	if ( '' !== $start_date ) {
		$export_args['vll_start_date'] = $start_date;
	}
	if ( '' !== $end_date ) {
		$export_args['vll_end_date'] = $end_date;
	}

	$export_url = wp_nonce_url(
		add_query_arg( $export_args, admin_url( 'admin-post.php' ) ),
		VLL_EXPORT_ACTION,
		'vll_export_nonce'
	);
	$clear_url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => VLL_CLEAR_ACTION,
			),
			admin_url( 'admin-post.php' )
		),
		VLL_CLEAR_ACTION,
		'vll_clear_nonce'
	);
	$clear_confirm = esc_attr__( 'Tüm ziyaret kayıtları silinecek. Devam etmek istiyor musunuz?', 'visitor-lite-logger' );
	$notice        = isset( $_GET['vll_notice'] ) ? sanitize_key( wp_unslash( $_GET['vll_notice'] ) ) : '';

	echo '<div class="wrap">';
	echo '<h1 class="wp-heading-inline"><span class="dashicons dashicons-chart-bar" style="margin-top:6px;margin-right:6px;" aria-hidden="true"></span>' . esc_html__( 'Ziyaretçi Kayıtları', 'visitor-lite-logger' ) . '</h1>';
	echo '<a href="' . esc_url( $export_url ) . '" class="page-title-action"><span class="dashicons dashicons-download" style="margin-top:3px;margin-right:4px;" aria-hidden="true"></span>' . esc_html__( 'CSV Olarak İndir', 'visitor-lite-logger' ) . '</a>';
	echo '<a href="' . esc_url( $clear_url ) . '" class="page-title-action" style="color:#b32d2e;" onclick="return confirm(\'' . $clear_confirm . '\');"><span class="dashicons dashicons-trash" style="margin-top:3px;margin-right:4px;" aria-hidden="true"></span>' . esc_html__( 'Kayıtları Sıfırla', 'visitor-lite-logger' ) . '</a>';
	echo '<hr class="wp-header-end" />';
	if ( 'logs_cleared' === $notice ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tüm ziyaret kayıtları temizlendi.', 'visitor-lite-logger' ) . '</p></div>';
	} elseif ( 'logs_clear_failed' === $notice ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Kayıtlar temizlenirken bir hata oluştu.', 'visitor-lite-logger' ) . '</p></div>';
	}

	echo '<style>
	.vll-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:14px 0 16px;}
	.vll-summary-card{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;box-shadow:0 1px 1px rgba(0,0,0,.04);}
	.vll-summary-icon{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#f0f6fc;color:#2271b1;font-size:20px;line-height:1;}
	.vll-summary-label{display:block;color:#50575e;font-size:12px;margin:0 0 2px;}
	.vll-summary-value{display:block;font-size:20px;font-weight:600;color:#1d2327;line-height:1.2;}
	</style>';

	echo '<div class="vll-summary-grid" aria-label="' . esc_attr__( 'Özet İstatistikler', 'visitor-lite-logger' ) . '">';
	echo '<div class="vll-summary-card">';
	echo '<span class="vll-summary-icon dashicons dashicons-chart-bar" aria-hidden="true"></span>';
	echo '<div><span class="vll-summary-label">' . esc_html__( 'Toplam Kayıt', 'visitor-lite-logger' ) . '</span><span class="vll-summary-value">' . esc_html( number_format_i18n( $total_count ) ) . '</span></div>';
	echo '</div>';

	echo '<div class="vll-summary-card">';
	echo '<span class="vll-summary-icon dashicons dashicons-clock" aria-hidden="true"></span>';
	echo '<div><span class="vll-summary-label">' . esc_html__( 'Son 24 Saat', 'visitor-lite-logger' ) . '</span><span class="vll-summary-value">' . esc_html( number_format_i18n( $last_24h_count ) ) . '</span></div>';
	echo '</div>';

	echo '<div class="vll-summary-card">';
	echo '<span class="vll-summary-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>';
	echo '<div><span class="vll-summary-label">' . esc_html__( 'En Eski Kayıt', 'visitor-lite-logger' ) . '</span><span class="vll-summary-value">' . esc_html( $oldest_date ? $oldest_date : '-' ) . '</span></div>';
	echo '</div>';
	echo '</div>';

	echo '<form method="get">';
	echo '<input type="hidden" name="page" value="vll-visitor-logs" />';
	$list_table->search_box( esc_html__( 'Kayıtlarda Ara', 'visitor-lite-logger' ), 'vll-search-logs' );
	$list_table->display();
	echo '</form>';
	echo '</div>';
}

/**
 * Handles clear all logs request.
 *
 * @return void
 */
function vll_handle_clear_logs() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'visitor-lite-logger' ) );
	}

	$nonce = isset( $_GET['vll_clear_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['vll_clear_nonce'] ) ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, VLL_CLEAR_ACTION ) ) {
		wp_die( esc_html__( 'Geçersiz istek.', 'visitor-lite-logger' ) );
	}

	global $wpdb;
	$table_name = vll_get_table_name();

	$result = $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( false === $result ) {
		$result = $wpdb->query( "DELETE FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$notice       = ( false === $result ) ? 'logs_clear_failed' : 'logs_cleared';
	$redirect_url = add_query_arg(
		array(
			'page'       => 'vll-visitor-logs',
			'vll_notice' => $notice,
		),
		admin_url( 'tools.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Handles CSV export request.
 *
 * @return void
 */
function vll_handle_csv_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'visitor-lite-logger' ) );
	}

	$nonce = isset( $_GET['vll_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['vll_export_nonce'] ) ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, VLL_EXPORT_ACTION ) ) {
		wp_die( esc_html__( 'Geçersiz istek.', 'visitor-lite-logger' ) );
	}

	global $wpdb;

	$table_name   = vll_get_table_name();
	$search_query = vll_get_admin_search_query();
	$start_date   = vll_get_admin_start_date();
	$end_date     = vll_get_admin_end_date();
	$where_data   = vll_get_search_where_data( $search_query, $start_date, $end_date );

	if ( ob_get_level() ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
	}

	$filename = 'visitor-lite-logger-' . gmdate( 'Y-m-d-His' ) . '.csv';
	$chunk_size = 5000;
	$offset     = 0;

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	$output = fopen( 'php://output', 'w' );
	if ( false === $output ) {
		exit;
	}

	echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	fputcsv(
		$output,
		array(
			'Tarih',
			'IP',
			'Ziyaret Edilen URL',
			'Sayfa Başlığı',
			'Referrer',
			'Sayfada Kalma (ms)',
			'User-Agent',
		)
	);

	@set_time_limit( 0 );

	while ( true ) {
		$sql = "SELECT visit_time, visitor_ip, visited_url, page_title, referrer, time_on_page_ms, user_agent
			FROM {$table_name}
			{$where_data['sql']}
			ORDER BY visit_time DESC
			LIMIT %d OFFSET %d";

		$query_args = array_merge(
			$where_data['args'],
			array(
				$chunk_size,
				$offset,
			)
		);
		$sql = $wpdb->prepare( $sql, $query_args );

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			break;
		}

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					isset( $row['visit_time'] ) ? (string) $row['visit_time'] : '',
					isset( $row['visitor_ip'] ) ? (string) $row['visitor_ip'] : '',
					isset( $row['visited_url'] ) ? (string) $row['visited_url'] : '',
					isset( $row['page_title'] ) ? (string) $row['page_title'] : '',
					isset( $row['referrer'] ) ? (string) $row['referrer'] : '',
					isset( $row['time_on_page_ms'] ) ? (string) absint( $row['time_on_page_ms'] ) : '0',
					isset( $row['user_agent'] ) ? (string) $row['user_agent'] : '',
				)
			);
		}

		$offset += $chunk_size;

		if ( function_exists( 'ob_flush' ) && ob_get_level() > 0 ) {
			ob_flush();
		}
		flush();
	}

	fclose( $output );
	exit;
}

/**
 * Returns current admin search query.
 *
 * @return string
 */
function vll_get_admin_search_query() {
	$search = isset( $_REQUEST['s'] ) ? wp_unslash( $_REQUEST['s'] ) : '';
	$search = sanitize_text_field( (string) $search );

	return trim( $search );
}

/**
 * Returns sanitized start date from admin request.
 *
 * @return string
 */
function vll_get_admin_start_date() {
	$start_date = isset( $_REQUEST['vll_start_date'] ) ? wp_unslash( $_REQUEST['vll_start_date'] ) : '';

	return vll_sanitize_admin_date( $start_date );
}

/**
 * Returns sanitized end date from admin request.
 *
 * @return string
 */
function vll_get_admin_end_date() {
	$end_date = isset( $_REQUEST['vll_end_date'] ) ? wp_unslash( $_REQUEST['vll_end_date'] ) : '';

	return vll_sanitize_admin_date( $end_date );
}

/**
 * Sanitizes a date value in Y-m-d format.
 *
 * @param string $date_value Raw date.
 * @return string
 */
function vll_sanitize_admin_date( $date_value ) {
	$date_value = sanitize_text_field( (string) $date_value );
	$date_value = trim( $date_value );

	if ( '' === $date_value ) {
		return '';
	}

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_value ) ) {
		return '';
	}

	$date = DateTime::createFromFormat( 'Y-m-d', $date_value );
	if ( ! ( $date instanceof DateTime ) ) {
		return '';
	}

	return ( $date->format( 'Y-m-d' ) === $date_value ) ? $date_value : '';
}

/**
 * Builds WHERE clause data for search queries.
 *
 * @param string $search_query Search query.
 * @param string $start_date Start date.
 * @param string $end_date End date.
 * @return array{sql:string,args:array}
 */
function vll_get_search_where_data( $search_query, $start_date = '', $end_date = '' ) {
	global $wpdb;

	$start_date = vll_sanitize_admin_date( $start_date );
	$end_date   = vll_sanitize_admin_date( $end_date );

	if ( '' !== $start_date && '' !== $end_date && $start_date > $end_date ) {
		$temp       = $start_date;
		$start_date = $end_date;
		$end_date   = $temp;
	}

	$where_sql  = 'WHERE id >= %d';
	$where_args = array( 0 );

	if ( '' !== $search_query ) {
		$like = '%' . $wpdb->esc_like( $search_query ) . '%';

		$where_sql .= ' AND (visitor_ip LIKE %s OR visited_url LIKE %s OR user_agent LIKE %s)';
		$where_args[] = $like;
		$where_args[] = $like;
		$where_args[] = $like;
	}

	if ( '' !== $start_date ) {
		$where_sql   .= ' AND visit_time >= %s';
		$where_args[] = $start_date . ' 00:00:00';
	}

	if ( '' !== $end_date ) {
		$where_sql   .= ' AND visit_time <= %s';
		$where_args[] = $end_date . ' 23:59:59';
	}

	return array(
		'sql'  => $where_sql,
		'args' => $where_args,
	);
}

/**
 * Truncates a string to max length.
 *
 * @param string $text Text value.
 * @param int    $max_length Max length.
 * @return string
 */
function vll_truncate_text( $text, $max_length ) {
	$max_length = absint( $max_length );
	if ( $max_length < 1 ) {
		return '';
	}

	$text = (string) $text;

	if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
		if ( mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		return mb_substr( $text, 0, $max_length );
	}

	if ( strlen( $text ) <= $max_length ) {
		return $text;
	}

	return substr( $text, 0, $max_length );
}

/**
 * Formats milliseconds into a readable duration.
 *
 * @param int $duration_ms Duration in milliseconds.
 * @return string
 */
function vll_format_duration_ms( $duration_ms ) {
	$duration_ms = absint( $duration_ms );
	if ( $duration_ms < 1000 ) {
		return __( '< 1 sn', 'visitor-lite-logger' );
	}

	$seconds = (int) floor( $duration_ms / 1000 );
	$hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
	$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
	$secs    = (int) ( $seconds % MINUTE_IN_SECONDS );

	if ( $hours > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes, 3: seconds */
			__( '%1$s sa %2$s dk %3$s sn', 'visitor-lite-logger' ),
			number_format_i18n( $hours ),
			number_format_i18n( $minutes ),
			number_format_i18n( $secs )
		);
	}

	if ( $minutes > 0 ) {
		return sprintf(
			/* translators: 1: minutes, 2: seconds */
			__( '%1$s dk %2$s sn', 'visitor-lite-logger' ),
			number_format_i18n( $minutes ),
			number_format_i18n( $secs )
		);
	}

	return sprintf(
		/* translators: %s: seconds */
		__( '%s sn', 'visitor-lite-logger' ),
		number_format_i18n( $secs )
	);
}
