<?php
/**
 * Plugin Name: Parlor Pro
 * Description: (appointment management) + (POS) سسٹم برائے (salon/spa/barbershop) — (admin menus), (AJAX), (dbDelta), (roles), (enqueue), (templates)، سب ایک ہی (PHP) فائل میں۔
 * Version: 1.0.0
 * Author: Parlor Pro Team
 * Text Domain: parlor-pro
 */

// ===============================================
// Parlor Pro — (PHP Phase-1)
// Namespace + Constants + Bootstrap
// ===============================================

namespace ParlorPro\SSM;

if ( ! defined('ABSPATH') ) { exit; }

const VERSION       = '1.0.0';
const SLUG          = 'parlor-pro';
const TEXT_DOMAIN   = 'parlor-pro';
const OPTION_KEY    = 'parlor_pro_version';
const NONCE_ADMIN   = 'pp_admin_nonce';
const NONCE_BOOKING = 'pp_booking_nonce';
const ROLE_MANAGER  = 'parlor_manager';
const ROLE_STAFF    = 'parlor_staff';

/**
 * مرکزی (table registry)
 */
function table_names() : array {
	global $wpdb;
	$px = $wpdb->prefix;
	return [
		'clients'      => "{$px}pp_clients",
		'services'     => "{$px}pp_services",
		'resources'    => "{$px}pp_resources",
		'appointments' => "{$px}pp_appointments",
		'orders'       => "{$px}pp_orders",
	];
}

/**
 * (i18n) لوڈ
 */
add_action('plugins_loaded', function () {
	load_plugin_textdomain(TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// ===============================================
// Activation / Deactivation — (dbDelta) + (roles)
// ===============================================

/**
 * (roles/permissions) سیٹ اپ
 */
function add_caps_for_role( \WP_Role $role ) : void {
	$caps = [
		'pp_manage_all'  => true,
		'pp_view_reports'=> true,
		'pp_pos'         => true,
		'pp_calendar'    => true,
		'pp_settings'    => true,
	];
	foreach ($caps as $cap => $grant) { $role->add_cap($cap, $grant); }
}

function remove_caps_for_role( \WP_Role $role ) : void {
	$caps = ['pp_manage_all','pp_view_reports','pp_pos','pp_calendar','pp_settings'];
	foreach ($caps as $cap) { $role->remove_cap($cap); }
}

/**
 * (dbDelta) اسکیما
 */
function db_schema_sql() : array {
	$tables = table_names();
	$charset_collate = function_exists('maybe_get_table_charset') ? maybe_get_table_charset() : '';
	$charset_collate = $charset_collate ? " {$charset_collate} " : " DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci ";

	return [
		// Clients
		"CREATE TABLE {$tables['clients']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			phone VARCHAR(64) DEFAULT '' ,
			email VARCHAR(191) DEFAULT '' ,
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY email (email)
		) {$charset_collate};",

		// Services
		"CREATE TABLE {$tables['services']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			duration INT UNSIGNED NOT NULL DEFAULT 30, -- minutes
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};",

		// Resources (staff)
		"CREATE TABLE {$tables['resources']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			role VARCHAR(64) DEFAULT 'staff',
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};",

		// Appointments
		"CREATE TABLE {$tables['appointments']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			staff_id BIGINT UNSIGNED NOT NULL,
			start_time DATETIME NOT NULL,
			end_time DATETIME NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY staff_id (staff_id),
			KEY service_id (service_id),
			KEY start_time (start_time),
			KEY status (status)
		) {$charset_collate};",

		// Orders (POS)
		"CREATE TABLE {$tables['orders']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			appointment_id BIGINT UNSIGNED NULL,
			total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_method VARCHAR(64) DEFAULT 'cash',
			status VARCHAR(32) NOT NULL DEFAULT 'unpaid',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY appointment_id (appointment_id)
		) {$charset_collate};",
	];
}

/**
 * Plugin Activation
 */
function on_activate() : void {
	// Roles
	add_role( ROLE_MANAGER, __('Parlor Manager', TEXT_DOMAIN), [] );
	add_role( ROLE_STAFF,   __('Parlor Staff', TEXT_DOMAIN), [] );

	// Give caps
	if ( $m = get_role(ROLE_MANAGER) ) { add_caps_for_role($m); }
	if ( $s = get_role(ROLE_STAFF) )   {
		$s->add_cap('pp_calendar', true);
		$s->add_cap('pp_pos', true);
	}

	// Administrators inherit all
	if ( $a = get_role('administrator') ) { add_caps_for_role($a); }

	// DB
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( db_schema_sql() as $sql ) {
		dbDelta( $sql );
	}

	update_option( OPTION_KEY, VERSION, false );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activate' );

/**
 * Plugin Deactivation (caps safe-remove, data preserved)
 */
function on_deactivate() : void {
	// keep data; only clean transient options if any in future
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\on_deactivate' );

// ===============================================
// Admin Menus + Templates
// ===============================================

/**
 * (template) لوڈر — اگر فائل نہ ملے تو نرم پیغام
 */
function render_template( string $rel_path, array $vars = [] ) : void {
	$file = plugin_dir_path(__FILE__) . 'templates/' . ltrim($rel_path, '/');
	if ( ! file_exists( $file ) ) {
		echo '<div class="notice notice-warning"><p>' .
		     esc_html__( 'Teamplate نہیں ملی۔ براہِ کرم templates فولڈر چیک کریں:', TEXT_DOMAIN ) .
		     ' ' . esc_html( $rel_path ) . '</p></div>';
		return;
	}
	extract( $vars, EXTR_SKIP );
	include $file;
}

/**
 * (admin menus)
 */
add_action('admin_menu', function () {
	if ( ! current_user_can('pp_calendar') && ! current_user_can('pp_manage_all') ) {
		// پھر بھی ایڈمن بار میں نظر نہ آئے
		return;
	}

	$cap_main = current_user_can('pp_manage_all') ? 'pp_manage_all' : 'pp_calendar';

	$hook = add_menu_page(
		__('Parlor Pro', TEXT_DOMAIN),
		__('Parlor Pro', TEXT_DOMAIN),
		$cap_main,
		SLUG,
		__NAMESPACE__ . '\\screen_dashboard',
		'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g fill="#7A3CFF"><path d="M3 4h18v4H3z"/><rect x="3" y="10" width="10" height="10" rx="2"/><rect x="15" y="10" width="6" height="6" rx="2"/></g></svg>'),
		25
	);

	add_submenu_page(SLUG, __('Dashboard', TEXT_DOMAIN), __('Dashboard', TEXT_DOMAIN), $cap_main, SLUG, __NAMESPACE__ . '\\screen_dashboard');
	add_submenu_page(SLUG, __('Calendar', TEXT_DOMAIN),  __('Calendar', TEXT_DOMAIN),  'pp_calendar', SLUG . '-calendar', __NAMESPACE__ . '\\screen_calendar');
	add_submenu_page(SLUG, __('POS', TEXT_DOMAIN),       __('POS', TEXT_DOMAIN),       'pp_pos',      SLUG . '-pos',      __NAMESPACE__ . '\\screen_pos');
	add_submenu_page(SLUG, __('Reports', TEXT_DOMAIN),   __('Reports', TEXT_DOMAIN),   'pp_view_reports', SLUG . '-reports', __NAMESPACE__ . '\\screen_reports');
	add_submenu_page(SLUG, __('Settings', TEXT_DOMAIN),  __('Settings', TEXT_DOMAIN),  'pp_settings', SLUG . '-settings', __NAMESPACE__ . '\\screen_settings');

	// (enqueue) صرف انہی اسکرین پر
	add_action("load-$hook", __NAMESPACE__ . '\\ensure_assets');
});

/**
 * اسکرین کال بیکس
 */
function screen_dashboard() { echo '<div class="wrap"><h1>' . esc_html__('Parlor Pro — Dashboard', TEXT_DOMAIN) . '</h1>'; render_template('admin-dashboard.php'); echo '</div>'; }
function screen_calendar()  { echo '<div class="wrap"><h1>' . esc_html__('Parlor Pro — Calendar', TEXT_DOMAIN) . '</h1>';  render_template('admin-calendar.php');  echo '</div>'; }
function screen_pos()       { echo '<div class="wrap"><h1>' . esc_html__('Parlor Pro — POS', TEXT_DOMAIN) . '</h1>';       render_template('admin-pos.php');       echo '</div>'; }
function screen_reports()   { echo '<div class="wrap"><h1>' . esc_html__('Parlor Pro — Reports', TEXT_DOMAIN) . '</h1>';   render_template('admin-reports.php');   echo '</div>'; }
function screen_settings()  {
	echo '<div class="wrap"><h1>' . esc_html__('Parlor Pro — Settings', TEXT_DOMAIN) . '</h1>';
	// کم سے کم سیٹنگز (example)
	echo '<form method="post" action="options.php">';
	settings_fields( 'parlor_pro_settings' );
	do_settings_sections( 'parlor_pro_settings' );
	submit_button();
	echo '</form></div>';
}

// ===============================================
// Assets (enqueue) + (wp_localize_script)
// ===============================================

/**
 * موجودہ اسکرین چیک کر کے (assets) لوڈ
 */
add_action('admin_enqueue_scripts', function ($hook_suffix) {
	$screen = get_current_screen();
	if ( empty($screen) || (strpos($screen->id, SLUG) === false && strpos($hook_suffix, SLUG) === false) ) {
		return;
	}
	enqueue_admin_assets();
});

function ensure_assets() : void {
	enqueue_admin_assets();
}

function enqueue_admin_assets() : void {
	$assets_url = plugins_url('assets', __FILE__);
	wp_enqueue_style( SLUG . '-admin', $assets_url . '/admin.css', [], VERSION );

	wp_enqueue_script( SLUG . '-admin', $assets_url . '/admin.js', ['jquery'], VERSION, true );

	$caps = [
		'manage_all'   => current_user_can('pp_manage_all'),
		'view_reports' => current_user_can('pp_view_reports'),
		'pos'          => current_user_can('pp_pos'),
		'calendar'     => current_user_can('pp_calendar'),
		'settings'     => current_user_can('pp_settings'),
	];

	wp_localize_script( SLUG . '-admin', 'ssmData', [
		'ajaxUrl'   => admin_url('admin-ajax.php'),
		'nonce'     => wp_create_nonce( NONCE_ADMIN ),
		'i18n'      => [
			'saved'   => __('محفوظ ہو گیا', TEXT_DOMAIN),
			'error'   => __('کوئی مسئلہ آ گیا ہے', TEXT_DOMAIN),
			'loading' => __('لوڈ ہو رہا ہے...', TEXT_DOMAIN),
		],
		'caps'      => $caps,
		'version'   => VERSION,
		'assetsUrl' => $assets_url,
		'siteUrl'   => site_url('/'),
	] );
}

// ===============================================
// Utilities — Sanitization + Helpers
// ===============================================

function pp_absint( $val ) : int { return absint( $val ); }
function pp_text( $val ) : string { return sanitize_text_field( (string) $val ); }
function pp_float( $val ) : float { return (float) preg_replace('/[^0-9\.\-]/','',(string)$val); }

/**
 * پیجینیٹر ان پٹس
 */
function read_paging() : array {
	$page = isset($_REQUEST['page_no']) ? max(1, absint($_REQUEST['page_no'])) : 1;
	$per  = isset($_REQUEST['per_page']) ? max(1, min(100, absint($_REQUEST['per_page']))) : 10;
	$offset = ($page - 1) * $per;
	return [$page, $per, $offset];
}

// ===============================================
// AJAX Endpoints — secured (nonce + caps)
// ===============================================

/**
 * عمومی (AJAX) رسپانس ہیلپرز
 */
function json_ok( $data = [] ) { wp_send_json_success( [ 'data' => $data ] ); }
function json_fail( $message = '', $code = 400 ) { wp_send_json_error( [ 'message' => $message ], $code ); }

/**
 * سکیورٹی چیک
 */
function ensure_nonce_and_cap( string $cap ) : void {
	check_ajax_referer( NONCE_ADMIN, 'nonce' );
	if ( ! current_user_can( $cap ) && ! current_user_can('pp_manage_all') ) {
		json_fail( __('آپ کے پاس اس عمل کی اجازت نہیں', TEXT_DOMAIN), 403 );
	}
}

/**
 * Dashboard metrics
 */
add_action('wp_ajax_pp_get_dashboard', function () {
	ensure_nonce_and_cap('pp_calendar');

	global $wpdb; $t = table_names();
	$today = gmdate('Y-m-d');

	$tot_clients = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['clients']}");
	$tot_appts   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['appointments']} WHERE DATE(start_time) = %s", $today));
	$tot_orders  = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total),0) FROM {$t['orders']} WHERE DATE(created_at) = %s", $today));

	json_ok([
		'total_clients' => $tot_clients,
		'today_appts'   => $tot_appts,
		'today_sales'   => number_format($tot_orders, 2, '.', ''),
	]);
});

/**
 * Services list (with paging)
 */
add_action('wp_ajax_pp_get_services', function () {
	ensure_nonce_and_cap('pp_calendar');
	global $wpdb; $t = table_names();
	list($page,$per,$offset) = read_paging();

	$where = 'WHERE active = 1';
	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['services']} {$where}");
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, title, price, duration, active
		 FROM {$t['services']}
		 {$where}
		 ORDER BY id DESC
		 LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );

	json_ok([
		'page' => $page, 'per_page' => $per, 'total' => $total, 'rows' => $rows
	]);
});

/**
 * Save Appointment (create/update)
 */
add_action('wp_ajax_pp_save_appointment', function () {
	ensure_nonce_and_cap('pp_calendar');
	global $wpdb; $t = table_names();

	$id         = isset($_POST['id']) ? absint($_POST['id']) : 0;
	$client_id  = isset($_POST['client_id']) ? absint($_POST['client_id']) : 0;
	$service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
	$staff_id   = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;
	$start_time = isset($_POST['start_time']) ? pp_text($_POST['start_time']) : '';
	$end_time   = isset($_POST['end_time']) ? pp_text($_POST['end_time']) : '';
	$status     = isset($_POST['status']) ? pp_text($_POST['status']) : 'pending';
	$notes      = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

	if ( ! $client_id || ! $service_id || ! $staff_id || ! $start_time || ! $end_time ) {
		json_fail( __('درکار فیلڈز فراہم کریں', TEXT_DOMAIN), 422 );
	}

	$data = [
		'client_id'  => $client_id,
		'service_id' => $service_id,
		'staff_id'   => $staff_id,
		'start_time' => $start_time,
		'end_time'   => $end_time,
		'status'     => $status,
		'notes'      => $notes,
	];

	if ( $id > 0 ) {
		$wpdb->update( $t['appointments'], $data, ['id' => $id] );
		$affected = $wpdb->rows_affected;
		json_ok([ 'updated' => $affected, 'id' => $id ]);
	} else {
		$wpdb->insert( $t['appointments'], $data );
		$new_id = (int) $wpdb->insert_id;
		json_ok([ 'created' => $new_id > 0, 'id' => $new_id ]);
	}
});

/**
 * Reports (basic totals with range)
 */
add_action('wp_ajax_pp_get_reports', function () {
	ensure_nonce_and_cap('pp_view_reports');
	global $wpdb; $t = table_names();

	$from = isset($_GET['from']) ? pp_text($_GET['from']) : gmdate('Y-m-01');
	$to   = isset($_GET['to'])   ? pp_text($_GET['to'])   : gmdate('Y-m-t');

	$total_sales = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(total),0) FROM {$t['orders']}
		 WHERE DATE(created_at) BETWEEN %s AND %s", $from, $to) );

	$appts = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$t['appointments']}
		 WHERE DATE(start_time) BETWEEN %s AND %s", $from, $to) );

	json_ok([
		'range' => ['from' => $from, 'to' => $to],
		'total_sales' => number_format($total_sales, 2, '.', ''),
		'appointments'=> $appts,
	]);
});

// ===============================================
// Shortcode — [parlor_booking]
// ===============================================

add_shortcode('parlor_booking', function ($atts = []) {
	ob_start();
	render_template('shortcode-booking.php', ['atts' => $atts]);
	return ob_get_clean();
});

// ===============================================
// Settings API (minimal registration)
// ===============================================

add_action('admin_init', function () {
	register_setting( 'parlor_pro_settings', 'parlor_pro_options', [
		'type' => 'array',
		'sanitize_callback' => function ($opts) {
			$opts = is_array($opts) ? $opts : [];
			return [
				'business_name' => isset($opts['business_name']) ? pp_text($opts['business_name']) : '',
				'whatsapp'      => isset($opts['whatsapp']) ? pp_text($opts['whatsapp']) : '',
			];
		},
		'default' => [
			'business_name' => '',
			'whatsapp'      => '',
		],
	] );

	add_settings_section('pp_main', __('General', TEXT_DOMAIN), function () {
		echo '<p>' . esc_html__('عام سیٹنگز', TEXT_DOMAIN) . '</p>';
	}, 'parlor_pro_settings');

	add_settings_field('pp_business_name', __('Business Name', TEXT_DOMAIN), function () {
		$opts = (array) get_option('parlor_pro_options', []);
		$val = isset($opts['business_name']) ? $opts['business_name'] : '';
		echo '<input type="text" name="parlor_pro_options[business_name]" value="' . esc_attr($val) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__('آپ کے کاروبار کا نام', TEXT_DOMAIN) . '</p>';
	}, 'parlor_pro_settings', 'pp_main');

	add_settings_field('pp_whatsapp', __('WhatsApp Number', TEXT_DOMAIN), function () {
		$opts = (array) get_option('parlor_pro_options', []);
		$val = isset($opts['whatsapp']) ? $opts['whatsapp'] : '';
		echo '<input type="text" name="parlor_pro_options[whatsapp]" value="' . esc_attr($val) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__('واٹس ایپ نمبر (اختیاری)', TEXT_DOMAIN) . '</p>';
	}, 'parlor_pro_settings', 'pp_main');
});

// ===============================================
// Admin Notices — Version check
// ===============================================

add_action('admin_init', function () {
	$ver = get_option( OPTION_KEY );
	if ( $ver !== VERSION ) {
		update_option( OPTION_KEY, VERSION, false );
	}
});

add_action('admin_notices', function () {
	if ( ! current_user_can('pp_manage_all') ) { return; }
	$screen = get_current_screen();
	if ( empty($screen) || strpos($screen->id, SLUG) === false ) { return; }
	echo '<div class="notice notice-info is-dismissible"><p>' .
	     esc_html__( 'Parlor Pro فعال ہے — اگر کسی جگہ مسئلہ ہو تو کنسول لاگ چیک کریں۔', TEXT_DOMAIN ) .
	     '</p></div>';
});

// ===============================================
// Security — Public AJAX (none for now), Booking Nonce helper
// ===============================================

/**
 * مستقبل کے پبلک (AJAX) کے لیے (nonce) جنریٹر (shortcode) میں استعمال ہو سکتا ہے
 */
function booking_nonce() : string {
	return wp_create_nonce( NONCE_BOOKING );
}

// ===============================================
// Uninstall Hook (optional — data preserve by default)
// ===============================================

/*
register_uninstall_hook(__FILE__, __NAMESPACE__ . '\\on_uninstall');
function on_uninstall() : void {
	// اگر مکمل ڈیلیٹ مقصود ہو تو یہاں ٹیبلز ڈراپ کریں (production میں محتاط رہیں)
}
*/

// ----- End of Phase-1 (PHP) -----


/** 
 * Part 4 — Demo Data Seeder 
 * File: parlor-pro.php
 * Location: فائل کے آخر میں پیسٹ کریں (?> سے پہلے)
 */

add_action('admin_post_pp_seed_demo', function () {
    if ( ! current_user_can('pp_manage_all') ) {
        wp_die(__('آپ کے پاس اجازت نہیں', 'parlor-pro'));
    }
    check_admin_referer('pp_seed_demo');

    global $wpdb;
    $t = table_names();

    // Demo Clients
    $clients = [
        ['name'=>'علی رضا','phone'=>'03001112222','email'=>'ali@example.com'],
        ['name'=>'سارہ خان','phone'=>'03002223333','email'=>'sara@example.com'],
        ['name'=>'حسن جاوید','phone'=>'03003334444','email'=>'hassan@example.com'],
        ['name'=>'ایمن فاطمہ','phone'=>'03004445555','email'=>'aymen@example.com'],
        ['name'=>'نعیم صدیقی','phone'=>'03005556666','email'=>'naeem@example.com'],
    ];
    foreach ($clients as $c) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['clients']} WHERE phone=%s", $c['phone']));
        if (! $exists) { $wpdb->insert($t['clients'], $c); }
    }

    // Demo Services
    $services = [
        ['title'=>'ہیئر کٹ','price'=>500,'duration'=>30],
        ['title'=>'فیشل','price'=>1200,'duration'=>60],
        ['title'=>'بلیچ','price'=>800,'duration'=>45],
        ['title'=>'مینیکیور','price'=>700,'duration'=>40],
    ];
    foreach ($services as $s) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['services']} WHERE title=%s", $s['title']));
        if (! $exists) { $wpdb->insert($t['services'], $s); }
    }

    // Demo Appointment
    $client_id  = (int) $wpdb->get_var("SELECT id FROM {$t['clients']} LIMIT 1");
    $service_id = (int) $wpdb->get_var("SELECT id FROM {$t['services']} LIMIT 1");
    if ($client_id && $service_id) {
        $exists = $wpdb->get_var("SELECT id FROM {$t['appointments']} LIMIT 1");
        if (! $exists) {
            $now = current_time('mysql');
            $end = date('Y-m-d H:i:s', strtotime('+1 hour', strtotime($now)));
            $wpdb->insert($t['appointments'], [
                'client_id'=>$client_id,
                'service_id'=>$service_id,
                'staff_id'=>1,
                'start_time'=>$now,
                'end_time'=>$end,
                'status'=>'confirmed',
                'notes'=>'ڈیمو اپائنٹمنٹ',
            ]);
        }
    }

    // Demo Order
    $appt_id = (int) $wpdb->get_var("SELECT id FROM {$t['appointments']} LIMIT 1");
    if ($appt_id) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['orders']} WHERE appointment_id=%d", $appt_id));
        if (! $exists) {
            $wpdb->insert($t['orders'], [
                'appointment_id'=>$appt_id,
                'total'=>500,
                'payment_method'=>'cash',
                'status'=>'paid',
            ]);
        }
    }

    wp_redirect(admin_url('admin.php?page=parlor-pro&seed=done'));
    exit;
});

/**
 * Admin notice & seed button
 */
add_action('admin_notices', function () {
    if ( ! current_user_can('pp_manage_all') ) { return; }
    $screen = get_current_screen();
    if ( empty($screen) || strpos($screen->id, SLUG) === false ) { return; }

    $url = wp_nonce_url(admin_url('admin-post.php?action=pp_seed_demo'), 'pp_seed_demo');
    echo '<div class="notice notice-success"><p>';
    echo esc_html__('ڈیمو ڈیٹا شامل کرنے کے لیے نیچے بٹن دبائیں:', 'parlor-pro') . ' ';
    echo '<a href="' . esc_url($url) . '" class="button button-primary">' . esc_html__('Seed Demo Data', 'parlor-pro') . '</a>';
    echo '</p></div>';

    if ( isset($_GET['seed']) && $_GET['seed'] === 'done' ) {
        echo '<div class="notice notice-success is-dismissible"><p>' .
             esc_html__('ڈیمو ڈیٹا بن چکا ہے ✅', 'parlor-pro') . '</p></div>';
    }
});

