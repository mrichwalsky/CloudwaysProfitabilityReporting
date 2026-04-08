<?php

if (!defined('ABSPATH')) {
	exit;
}

function cw_profit_render_attention_box(): void {
	global $wpdb;

	$apps_table = cw_profit_table_apps();
	$servers_table = cw_profit_table_servers();
	$attention = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$apps_table} WHERE needs_attention = 1");
	$missing_price = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		 FROM {$apps_table} a
		 INNER JOIN {$servers_table} s ON s.id = a.server_id
		 WHERE a.monthly_price IS NULL
		 AND s.monthly_client_price IS NULL"
	);
	$new_apps_24h = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$apps_table} WHERE first_seen_at IS NOT NULL AND first_seen_at >= %s",
			gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
		)
	);

	echo '<div class="notice notice-warning inline">';
	echo '<p><strong>' . esc_html__('Needs attention:', 'cw-profit') . '</strong> ';
	echo esc_html(sprintf(__('%d apps', 'cw-profit'), $attention));
	echo ' — ';
	echo esc_html(sprintf(__('%d missing price', 'cw-profit'), $missing_price));
	echo ' — ';
	echo esc_html(sprintf(__('%d new (24h)', 'cw-profit'), $new_apps_24h));
	echo '</p>';
	echo '</div>';
}

add_action('cw_profit_daily_digest', function (): void {
	$enabled = (int) get_option(CW_PROFIT_OPTION_PREFIX . 'enable_daily_email', 0);
	$to = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'daily_email_to', '');
	if ($enabled !== 1 || $to === '') {
		return;
	}

	global $wpdb;
	$apps_table = cw_profit_table_apps();
	$servers_table = cw_profit_table_servers();
	$new_apps_24h = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$apps_table} WHERE first_seen_at IS NOT NULL AND first_seen_at >= %s",
			gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
		)
	);
	$missing_price = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		 FROM {$apps_table} a
		 INNER JOIN {$servers_table} s ON s.id = a.server_id
		 WHERE a.monthly_price IS NULL
		 AND s.monthly_client_price IS NULL"
	);
	$needs_attention = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$apps_table} WHERE needs_attention = 1");

	$subject = '[Cloudways Profitability] Daily attention summary';
	$body = "Daily summary:\n\n";
	$body .= "- New apps (last 24h): {$new_apps_24h}\n";
	$body .= "- Apps missing monthly price: {$missing_price}\n\n";
	$body .= "- Apps flagged for attention: {$needs_attention}\n\n";
	$body .= "Open the WordPress admin to review and fill in missing data.\n";

	wp_mail($to, $subject, $body);
});

