<?php
/**
 * Plugin Name: Cloudways Profitability Tracker
 * Description: Track Cloudways servers and applications, set costs and prices, and view profitability by server (optional app-level allocation).
 * Version: 0.1.0
 * Author: Gas Mark 8, Ltd.
 * Author URI: https://gasmark8.com
 * License: GPLv2 or later
 * Text Domain: cw-profit
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CW_PROFIT_PLUGIN_VERSION', '0.1.0');
define('CW_PROFIT_PLUGIN_FILE', __FILE__);
define('CW_PROFIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CW_PROFIT_OPTION_PREFIX', 'cw_profit_');

require_once CW_PROFIT_PLUGIN_DIR . 'includes/db.php';
require_once CW_PROFIT_PLUGIN_DIR . 'includes/cloudways-client.php';
require_once CW_PROFIT_PLUGIN_DIR . 'includes/sync.php';
require_once CW_PROFIT_PLUGIN_DIR . 'includes/reports.php';
require_once CW_PROFIT_PLUGIN_DIR . 'includes/reminders.php';
require_once CW_PROFIT_PLUGIN_DIR . 'includes/admin-menu.php';

register_activation_hook(__FILE__, 'cw_profit_activate');
register_deactivation_hook(__FILE__, 'cw_profit_deactivate');

function cw_profit_activate(): void {
	cw_profit_install_tables();
	cw_profit_schedule_events();
}

function cw_profit_deactivate(): void {
	cw_profit_clear_scheduled_events();
}

add_action('plugins_loaded', function () {
	// Safe, incremental migrations for production installs.
	if (function_exists('cw_profit_maybe_migrate_schema')) {
		cw_profit_maybe_migrate_schema();
	}
	if (is_admin()) {
		cw_profit_admin_init();
	}
});

