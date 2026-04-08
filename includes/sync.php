<?php

if (!defined('ABSPATH')) {
	exit;
}

const CW_PROFIT_SYNC_HOOK = 'cw_profit_daily_sync';

function cw_profit_schedule_events(): void {
	if (!wp_next_scheduled(CW_PROFIT_SYNC_HOOK)) {
		wp_schedule_event(time() + 60, 'daily', CW_PROFIT_SYNC_HOOK);
	}
	if (!wp_next_scheduled('cw_profit_daily_digest')) {
		wp_schedule_event(time() + 120, 'daily', 'cw_profit_daily_digest');
	}
}

function cw_profit_clear_scheduled_events(): void {
	$ts = wp_next_scheduled(CW_PROFIT_SYNC_HOOK);
	if ($ts) {
		wp_unschedule_event($ts, CW_PROFIT_SYNC_HOOK);
	}
	$ts2 = wp_next_scheduled('cw_profit_daily_digest');
	if ($ts2) {
		wp_unschedule_event($ts2, 'cw_profit_daily_digest');
	}
}

add_action(CW_PROFIT_SYNC_HOOK, 'cw_profit_run_sync');

/**
 * Runs Cloudways sync; returns summary array for UI/logging.
 * @return array<string,mixed>
 */
function cw_profit_run_sync(): array {
	global $wpdb;

	$client = new CW_Profit_Cloudways_Client();

	$new_servers = 0;
	$new_apps = 0;
	$missing_price_count = 0;
	$missing_share_count = 0;
	$error = null;

	$now_gmt = gmdate('Y-m-d H:i:s');

	try {
		$servers = $client->list_servers();

		// Prefetch existing server rows to avoid per-server SELECTs (performance).
		$servers_table = cw_profit_table_servers();
		$existing_servers = array(); // cloudways_server_id => array('id'=>int,'monthly_client_price'=>mixed)
		$server_ids = array();
		foreach ($servers as $srv) {
			if (!is_array($srv)) {
				continue;
			}
			$sid = (string) ($srv['id'] ?? $srv['server_id'] ?? '');
			if ($sid !== '') {
				$server_ids[] = $sid;
			}
		}
		$server_ids = array_values(array_unique($server_ids));
		if (!empty($server_ids)) {
			$placeholders = implode(',', array_fill(0, count($server_ids), '%s'));
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, cloudways_server_id, monthly_client_price FROM {$servers_table} WHERE cloudways_server_id IN ($placeholders)",
					...$server_ids
				),
				ARRAY_A
			);
			foreach ($rows as $r) {
				$key = (string) ($r['cloudways_server_id'] ?? '');
				if ($key !== '') {
					$existing_servers[$key] = array(
						'id' => (int) ($r['id'] ?? 0),
						'monthly_client_price' => $r['monthly_client_price'] ?? null,
					);
				}
			}
		}

		foreach ($servers as $server) {
			if (!is_array($server)) {
				continue;
			}
			$cloudways_server_id = (string) ($server['id'] ?? $server['server_id'] ?? '');
			if ($cloudways_server_id === '') {
				continue;
			}

			$existing_id = isset($existing_servers[$cloudways_server_id]) ? (int) $existing_servers[$cloudways_server_id]['id'] : 0;

			$server_row = array(
				'cloudways_server_id' => $cloudways_server_id,
				'label' => isset($server['label']) ? sanitize_text_field((string) $server['label']) : (isset($server['server_label']) ? sanitize_text_field((string) $server['server_label']) : null),
				'provider' => isset($server['provider']) ? sanitize_text_field((string) $server['provider']) : null,
				'region' => isset($server['region']) ? sanitize_text_field((string) $server['region']) : null,
				'size' => isset($server['size']) ? sanitize_text_field((string) $server['size']) : null,
				'status' => isset($server['status']) ? sanitize_text_field((string) $server['status']) : null,
				'last_seen_at' => $now_gmt,
				'updated_at' => $now_gmt,
			);

			if ($existing_id > 0) {
				$wpdb->update($servers_table, $server_row, array('id' => $existing_id));
			} else {
				$server_row['created_at'] = $now_gmt;
				$server_row['currency'] = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'currency', 'USD');
				$wpdb->insert($servers_table, $server_row);
				$new_servers++;
				$existing_id = (int) $wpdb->insert_id;
			}

			$server_client_price = $existing_servers[$cloudways_server_id]['monthly_client_price'] ?? null;
			$server_has_client_price = !is_null($server_client_price);

			// In the Cloudways spec example, `GET /server` returns each server with an embedded `apps` array.
			$apps = array();
			if (isset($server['apps']) && is_array($server['apps'])) {
				$apps = $server['apps'];
			} else {
				$apps = $client->list_server_apps($cloudways_server_id);
			}
			// Prefetch existing app ids for this server in one query (performance).
			$apps_table = cw_profit_table_apps();
			$app_ids = array();
			foreach ($apps as $app) {
				if (!is_array($app)) {
					continue;
				}
				$aid = (string) ($app['id'] ?? $app['app_id'] ?? '');
				if ($aid !== '') {
					$app_ids[] = $aid;
				}
			}
			$app_ids = array_values(array_unique($app_ids));
			$existing_apps = array(); // cloudways_app_id => id
			if (!empty($app_ids)) {
				$app_placeholders = implode(',', array_fill(0, count($app_ids), '%s'));
				$app_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, cloudways_app_id FROM {$apps_table} WHERE cloudways_app_id IN ($app_placeholders)",
						...$app_ids
					),
					ARRAY_A
				);
				foreach ($app_rows as $ar) {
					$k = (string) ($ar['cloudways_app_id'] ?? '');
					if ($k !== '') {
						$existing_apps[$k] = (int) ($ar['id'] ?? 0);
					}
				}
			}

			foreach ($apps as $app) {
				if (!is_array($app)) {
					continue;
				}
				$cloudways_app_id = (string) ($app['id'] ?? $app['app_id'] ?? '');
				if ($cloudways_app_id === '') {
					continue;
				}

				$existing_app_id = isset($existing_apps[$cloudways_app_id]) ? (int) $existing_apps[$cloudways_app_id] : 0;

				$app_row = array(
					'cloudways_app_id' => $cloudways_app_id,
					'cloudways_server_id' => $cloudways_server_id,
					'server_id' => $existing_id > 0 ? $existing_id : null,
					'app_name' => isset($app['label']) ? sanitize_text_field((string) $app['label']) : (isset($app['app_label']) ? sanitize_text_field((string) $app['app_label']) : null),
					'app_type' => isset($app['application']) ? sanitize_text_field((string) $app['application']) : (isset($app['type']) ? sanitize_text_field((string) $app['type']) : null),
					'primary_domain' => isset($app['primary_domain']) ? sanitize_text_field((string) $app['primary_domain']) : (isset($app['domain']) ? sanitize_text_field((string) $app['domain']) : null),
					'last_seen_at' => $now_gmt,
					'updated_at' => $now_gmt,
				);

				if ($existing_app_id > 0) {
					$wpdb->update($apps_table, $app_row, array('id' => $existing_app_id));
				} else {
					$app_row['created_at'] = $now_gmt;
					$app_row['first_seen_at'] = $now_gmt;
					// New apps only need attention if this server is app-priced (no client total price override).
					$app_row['needs_attention'] = $server_has_client_price ? 0 : 1;
					$wpdb->insert($apps_table, $app_row);
					$new_apps++;
				}
			}

			// Lightweight rollup for dashboards/charts.
			$metrics_table = cw_profit_table_server_metrics();
			if ($existing_id > 0) {
				$app_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$apps_table} WHERE server_id = %d", $existing_id));
				$app_sum = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(COALESCE(monthly_price, 0)), 0) FROM {$apps_table} WHERE server_id = %d", $existing_id));
				$server_cost = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(monthly_cost, 0) FROM {$servers_table} WHERE id = %d", $existing_id));
				$client_price = $wpdb->get_var($wpdb->prepare("SELECT monthly_client_price FROM {$servers_table} WHERE id = %d", $existing_id));
				$client_price = is_null($client_price) ? null : (float) $client_price;

				$revenue = !is_null($client_price) ? (float) $client_price : (float) $app_sum;
				$profit = $revenue - $server_cost;
				$margin_pct = $revenue > 0 ? (($profit / $revenue) * 100.0) : 0.0;

				$wpdb->replace(
					$metrics_table,
					array(
						'server_id' => $existing_id,
						'app_count' => $app_count,
						'total_revenue' => $revenue,
						'total_cost' => $server_cost,
						'profit' => $profit,
						'margin_percent' => $margin_pct,
						'calculated_at' => $now_gmt,
					),
					array('%d', '%d', '%f', '%f', '%f', '%f', '%s')
				);
			}
		}

		$apps_table = cw_profit_table_apps();
		$servers_table = cw_profit_table_servers();

		// Missing price only needs attention when server has no client price override.
		$missing_price_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$apps_table} a
			 INNER JOIN {$servers_table} s ON s.id = a.server_id
			 WHERE a.monthly_price IS NULL
			 AND s.monthly_client_price IS NULL"
		);

		// Missing share is only meaningful if allocation view is enabled; keep tracked regardless.
		$missing_share_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$apps_table} WHERE cost_share_type IS NULL OR cost_share_type = ''");

		// Keep attention flag sticky if missing price AND server doesn't use client price override.
		$wpdb->query(
			"UPDATE {$apps_table} a
			 INNER JOIN {$servers_table} s ON s.id = a.server_id
			 SET a.needs_attention = 1
			 WHERE a.monthly_price IS NULL
			 AND s.monthly_client_price IS NULL"
		);

		update_option(CW_PROFIT_OPTION_PREFIX . 'last_sync_at', $now_gmt, false);
	} catch (Throwable $t) {
		$error = $t->getMessage();
	}

	$sync_log_table = cw_profit_table_sync_log();
	$wpdb->insert(
		$sync_log_table,
		array(
			'ran_at' => $now_gmt,
			'result' => $error ? 'error' : 'ok',
			'new_servers' => $new_servers,
			'new_apps' => $new_apps,
			'missing_price_count' => $missing_price_count,
			'missing_share_count' => $missing_share_count,
			'error' => $error,
		)
	);

	return array(
		'result' => $error ? 'error' : 'ok',
		'new_servers' => $new_servers,
		'new_apps' => $new_apps,
		'missing_price_count' => $missing_price_count,
		'missing_share_count' => $missing_share_count,
		'error' => $error,
	);
}

