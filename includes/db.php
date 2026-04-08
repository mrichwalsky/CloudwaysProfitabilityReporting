<?php

if (!defined('ABSPATH')) {
	exit;
}

function cw_profit_table_servers(): string {
	global $wpdb;
	return $wpdb->prefix . 'cw_servers';
}

function cw_profit_table_apps(): string {
	global $wpdb;
	return $wpdb->prefix . 'cw_apps';
}

function cw_profit_table_sync_log(): string {
	global $wpdb;
	return $wpdb->prefix . 'cw_sync_log';
}

function cw_profit_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$servers = cw_profit_table_servers();
	$apps = cw_profit_table_apps();
	$sync_log = cw_profit_table_sync_log();
	$server_metrics = cw_profit_table_server_metrics();

	$sql_servers = "CREATE TABLE {$servers} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		cloudways_server_id VARCHAR(64) NOT NULL,
		label VARCHAR(191) NULL,
		provider VARCHAR(32) NULL,
		region VARCHAR(32) NULL,
		size VARCHAR(32) NULL,
		monthly_cost DECIMAL(12,2) NULL,
		monthly_client_price DECIMAL(12,2) NULL,
		currency CHAR(3) NOT NULL DEFAULT 'USD',
		status VARCHAR(32) NULL,
		last_seen_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY cloudways_server_id (cloudways_server_id),
		KEY status (status),
		KEY updated_at (updated_at),
		KEY last_seen_at (last_seen_at)
	) {$charset_collate};";

	$sql_apps = "CREATE TABLE {$apps} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		cloudways_app_id VARCHAR(64) NOT NULL,
		cloudways_server_id VARCHAR(64) NOT NULL,
		server_id BIGINT(20) UNSIGNED NULL,
		app_name VARCHAR(191) NULL,
		app_type VARCHAR(32) NULL,
		primary_domain VARCHAR(191) NULL,
		monthly_price DECIMAL(12,2) NULL,
		cost_share_type VARCHAR(32) NOT NULL DEFAULT 'auto_equal',
		manual_share_value DECIMAL(12,4) NULL,
		needs_attention TINYINT(1) NOT NULL DEFAULT 0,
		first_seen_at DATETIME NULL,
		last_seen_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY cloudways_app_id (cloudways_app_id),
		KEY cloudways_server_id (cloudways_server_id),
		KEY server_id (server_id),
		KEY server_attention (server_id, needs_attention),
		KEY last_seen_at (last_seen_at),
		KEY needs_attention (needs_attention)
	) {$charset_collate};";

	$sql_sync_log = "CREATE TABLE {$sync_log} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ran_at DATETIME NOT NULL,
		result VARCHAR(16) NOT NULL,
		new_servers INT UNSIGNED NOT NULL DEFAULT 0,
		new_apps INT UNSIGNED NOT NULL DEFAULT 0,
		missing_price_count INT UNSIGNED NOT NULL DEFAULT 0,
		missing_share_count INT UNSIGNED NOT NULL DEFAULT 0,
		error LONGTEXT NULL,
		PRIMARY KEY (id),
		KEY ran_at (ran_at)
	) {$charset_collate};";

	$sql_server_metrics = "CREATE TABLE {$server_metrics} (
		server_id BIGINT(20) UNSIGNED NOT NULL,
		app_count INT UNSIGNED NOT NULL DEFAULT 0,
		total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
		total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
		profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
		margin_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
		calculated_at DATETIME NOT NULL,
		PRIMARY KEY (server_id),
		KEY calculated_at (calculated_at)
	) {$charset_collate};";

	dbDelta($sql_servers);
	dbDelta($sql_apps);
	dbDelta($sql_sync_log);
	dbDelta($sql_server_metrics);

	update_option(CW_PROFIT_OPTION_PREFIX . 'db_version', CW_PROFIT_PLUGIN_VERSION, false);
}

function cw_profit_table_server_metrics(): string {
	global $wpdb;
	return $wpdb->prefix . 'cw_server_metrics';
}

/**
 * Production-safe schema migrations for existing installs.
 *
 * We keep `cloudways_server_id` string columns for sync/back-compat, but prefer integer `apps.server_id`
 * for joins and metrics.
 */
function cw_profit_maybe_migrate_schema(): void {
	global $wpdb;

	$servers = cw_profit_table_servers();
	$apps = cw_profit_table_apps();
	$server_metrics = cw_profit_table_server_metrics();

	// If tables aren't created yet, nothing to do (activation will create them).
	$servers_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $servers));
	$apps_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $apps));
	if (!$servers_exists || !$apps_exists) {
		return;
	}

	// 1) Add apps.server_id if missing.
	$has_server_id = $wpdb->get_var("SHOW COLUMNS FROM {$apps} LIKE 'server_id'");
	if (!$has_server_id) {
		$wpdb->query("ALTER TABLE {$apps} ADD COLUMN server_id BIGINT(20) UNSIGNED NULL AFTER cloudways_server_id");
	}

	// 2) Backfill apps.server_id via existing cloudways_server_id mapping.
	$wpdb->query(
		"UPDATE {$apps} a
		 INNER JOIN {$servers} s ON s.cloudways_server_id = a.cloudways_server_id
		 SET a.server_id = s.id
		 WHERE a.server_id IS NULL"
	);

	// 3) Add indexes (IF NOT EXISTS is not widely supported; use SHOW INDEX checks).
	$indexes = $wpdb->get_results("SHOW INDEX FROM {$apps}", ARRAY_A);
	$app_index_names = array();
	foreach ($indexes as $idx) {
		if (isset($idx['Key_name'])) {
			$app_index_names[(string) $idx['Key_name']] = true;
		}
	}
	if (!isset($app_index_names['server_id'])) {
		$wpdb->query("ALTER TABLE {$apps} ADD KEY server_id (server_id)");
	}
	if (!isset($app_index_names['server_attention'])) {
		$wpdb->query("ALTER TABLE {$apps} ADD KEY server_attention (server_id, needs_attention)");
	}
	if (!isset($app_index_names['last_seen_at'])) {
		$wpdb->query("ALTER TABLE {$apps} ADD KEY last_seen_at (last_seen_at)");
	}

	$server_indexes = $wpdb->get_results("SHOW INDEX FROM {$servers}", ARRAY_A);
	$server_index_names = array();
	foreach ($server_indexes as $idx) {
		if (isset($idx['Key_name'])) {
			$server_index_names[(string) $idx['Key_name']] = true;
		}
	}
	if (!isset($server_index_names['status'])) {
		$wpdb->query("ALTER TABLE {$servers} ADD KEY status (status)");
	}
	if (!isset($server_index_names['updated_at'])) {
		$wpdb->query("ALTER TABLE {$servers} ADD KEY updated_at (updated_at)");
	}

	// 4) Create server metrics table (only if missing).
	$metrics_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $server_metrics));
	if (!$metrics_exists) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$sql_server_metrics = "CREATE TABLE {$server_metrics} (
			server_id BIGINT(20) UNSIGNED NOT NULL,
			app_count INT UNSIGNED NOT NULL DEFAULT 0,
			total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			margin_percent DECIMAL(7,3) NOT NULL DEFAULT 0.000,
			calculated_at DATETIME NOT NULL,
			PRIMARY KEY (server_id),
			KEY calculated_at (calculated_at)
		) {$charset_collate};";
		dbDelta($sql_server_metrics);
	}
}

