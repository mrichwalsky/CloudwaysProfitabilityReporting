<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Server-level reporting: revenue/profit/margin using stored costs/prices.
 * Allocation is implemented later; this file provides the core calculations.
 */

/**
 * @return array<string,float|null>
 */
function cw_profit_calculate_server_totals(string $cloudways_server_id): array {
	global $wpdb;

	$servers_table = cw_profit_table_servers();
	$apps_table = cw_profit_table_apps();

	$server_row = $wpdb->get_row(
		$wpdb->prepare("SELECT id, monthly_cost, monthly_client_price FROM {$servers_table} WHERE cloudways_server_id = %s", $cloudways_server_id),
		ARRAY_A
	);
	$server_pk = 0;
	$server_cost = null;
	$client_price = null;
	if (is_array($server_row)) {
		$server_pk = isset($server_row['id']) ? (int) $server_row['id'] : 0;
		$server_cost = is_null($server_row['monthly_cost']) ? null : (float) $server_row['monthly_cost'];
		$client_price = is_null($server_row['monthly_client_price']) ? null : (float) $server_row['monthly_client_price'];
	}

	if (!is_null($client_price)) {
		$revenue = $client_price;
	} else {
		$revenue = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(monthly_price), 0)
				 FROM {$apps_table}
				 WHERE ((server_id = %d) OR (server_id IS NULL AND cloudways_server_id = %s))
				 AND monthly_price IS NOT NULL",
				$server_pk,
				$cloudways_server_id
			)
		);
	}

	$profit = null;
	$margin = null;
	if (!is_null($server_cost)) {
		$profit = $revenue - $server_cost;
		if ($revenue > 0) {
			$margin = $profit / $revenue;
		}
	}

	return array(
		'server_cost' => $server_cost,
		'revenue' => $revenue,
		'profit' => $profit,
		'margin' => $margin,
		'client_price' => $client_price,
	);
}

function cw_profit_format_money($value): string {
	if (is_null($value)) {
		return '—';
	}
	$currency = strtoupper((string) get_option(CW_PROFIT_OPTION_PREFIX . 'currency', 'USD'));
	$amount = number_format((float) $value, 2, '.', ',');

	// Prefer a symbol for common currencies to avoid "USD 40.00" visual noise.
	$symbols = array(
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'AUD' => 'A$',
		'CAD' => 'C$',
		'NZD' => 'NZ$',
	);
	if (isset($symbols[$currency])) {
		return $symbols[$currency] . $amount;
	}

	return $currency . ' ' . $amount;
}

function cw_profit_format_percent($value): string {
	if (is_null($value)) {
		return '—';
	}
	return number_format(((float) $value) * 100.0, 1, '.', ',') . '%';
}

/**
 * Allocate a server's monthly cost across its apps.
 *
 * Manual entries are applied first. Remaining cost is distributed via `$default_mode`.
 *
 * Supported cost_share_type:
 * - manual_amount: manual_share_value is a currency amount
 * - manual_percent: manual_share_value is 0-100
 * - auto_equal: participates in default distribution
 * - auto_revenue_weighted: participates in revenue-weight distribution
 * - none: treated like auto_equal for now (so totals still roll up)
 *
 * @param float $server_cost
 * @param array<int,array<string,mixed>> $apps rows from wp_cw_apps for a server
 * @param string $default_mode 'auto_equal'|'auto_revenue_weighted'
 * @return array<string,float> map cloudways_app_id => allocated_cost
 */
function cw_profit_allocate_server_cost(float $server_cost, array $apps, string $default_mode = 'auto_equal'): array {
	$alloc = array();
	$remaining = max(0.0, $server_cost);

	// First pass: manual allocations.
	foreach ($apps as $app) {
		if (!is_array($app)) {
			continue;
		}
		$app_id = (string) ($app['cloudways_app_id'] ?? '');
		if ($app_id === '') {
			continue;
		}
		$type = (string) ($app['cost_share_type'] ?? '');
		$value = isset($app['manual_share_value']) ? (float) $app['manual_share_value'] : 0.0;

		$amount = 0.0;
		if ($type === 'manual_amount') {
			$amount = max(0.0, $value);
		} elseif ($type === 'manual_percent') {
			$pct = min(100.0, max(0.0, $value));
			$amount = ($server_cost * ($pct / 100.0));
		}

		if ($amount > 0) {
			$alloc[$app_id] = $amount;
			$remaining -= $amount;
		}
	}

	$remaining = max(0.0, $remaining);

	// Second pass: distribute remainder.
	$auto_apps = array();
	foreach ($apps as $app) {
		$app_id = (string) ($app['cloudways_app_id'] ?? '');
		if ($app_id === '' || isset($alloc[$app_id])) {
			continue;
		}
		$type = (string) ($app['cost_share_type'] ?? '');
		if ($type === '' || $type === 'none') {
			$type = $default_mode;
		}
		$auto_apps[] = array(
			'id' => $app_id,
			'type' => $type,
			'price' => isset($app['monthly_price']) ? (float) $app['monthly_price'] : 0.0,
		);
	}

	if ($remaining <= 0 || empty($auto_apps)) {
		// Ensure all apps have an entry.
		foreach ($apps as $app) {
			$app_id = (string) ($app['cloudways_app_id'] ?? '');
			if ($app_id !== '' && !isset($alloc[$app_id])) {
				$alloc[$app_id] = 0.0;
			}
		}
		return $alloc;
	}

	$weighted = ($default_mode === 'auto_revenue_weighted');
	if ($weighted) {
		$weight_sum = 0.0;
		foreach ($auto_apps as $a) {
			$weight_sum += max(0.0, (float) $a['price']);
		}
		if ($weight_sum > 0) {
			foreach ($auto_apps as $a) {
				$w = max(0.0, (float) $a['price']);
				$alloc[(string) $a['id']] = $remaining * ($w / $weight_sum);
			}
		} else {
			$weighted = false;
		}
	}

	if (!$weighted) {
		$per = $remaining / count($auto_apps);
		foreach ($auto_apps as $a) {
			$alloc[(string) $a['id']] = $per;
		}
	}

	// Final normalization: floating point drift, and guarantee all apps present.
	$sum = 0.0;
	foreach ($alloc as $v) {
		$sum += (float) $v;
	}
	$drift = $server_cost - $sum;
	if (abs($drift) > 0.01) {
		$first_key = array_key_first($alloc);
		if (is_string($first_key)) {
			$alloc[$first_key] = max(0.0, (float) $alloc[$first_key] + $drift);
		}
	}

	foreach ($apps as $app) {
		$app_id = (string) ($app['cloudways_app_id'] ?? '');
		if ($app_id !== '' && !isset($alloc[$app_id])) {
			$alloc[$app_id] = 0.0;
		}
	}

	return $alloc;
}

