<?php

if (!defined('ABSPATH')) {
	exit;
}

function cw_profit_admin_init(): void {
	add_action('admin_menu', 'cw_profit_register_admin_menu');
	add_action('admin_post_cw_profit_run_sync', 'cw_profit_handle_run_sync');
	add_action('admin_post_cw_profit_save_server_cost', 'cw_profit_handle_save_server_cost');
	add_action('admin_post_cw_profit_save_app', 'cw_profit_handle_save_app');
	add_action('admin_enqueue_scripts', 'cw_profit_admin_enqueue_assets');
}

function cw_profit_admin_enqueue_assets(string $hook): void {
	// Only load on our plugin pages.
	$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
	if (!in_array($page, array('cw-profit-dashboard', 'cw-profit-apps', 'cw-profit-settings', 'cw-profit-server'), true)) {
		return;
	}

	// Chart.js via CDN (admin-only pages).
	wp_enqueue_script(
		'cw-profit-chartjs',
		'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
		array(),
		'4.4.3',
		true
	);

	// Minimal admin-safe styling for chart cards.
	$css = ".cw-profit-chart-row{display:block;margin:20px 0 32px;}
.cw-profit-card{width:100%;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 14px 10px;box-shadow:0 1px 2px rgba(0,0,0,.05);margin:0 0 18px;}
.cw-profit-card:last-child{margin-bottom:0;}
.cw-profit-card h2{margin:0 0 6px;font-size:13px;font-weight:600;letter-spacing:.2px;}
.cw-profit-card .description{margin:0 0 10px;color:#646970;line-height:1.35;}
.cw-profit-card canvas{width:100% !important;display:block;}
#cwProfitChart1{height:320px !important;}
#cwProfitChart2{height:340px !important;}
#cwProfitChart3{height:320px !important;}";
	wp_register_style('cw-profit-admin-inline', false);
	wp_enqueue_style('cw-profit-admin-inline');
	wp_add_inline_style('cw-profit-admin-inline', $css);
}

function cw_profit_register_admin_menu(): void {
	$cap = 'manage_options';

	add_menu_page(
		__('Cloudways Profitability', 'cw-profit'),
		__('Cloudways Profitability', 'cw-profit'),
		$cap,
		'cw-profit-dashboard',
		'cw_profit_render_dashboard_home_page',
		'dashicons-chart-line',
		56
	);

	add_submenu_page(
		'cw-profit-dashboard',
		__('Dashboard', 'cw-profit'),
		__('Dashboard', 'cw-profit'),
		$cap,
		'cw-profit-dashboard',
		'cw_profit_render_dashboard_home_page'
	);

	add_submenu_page(
		'cw-profit-dashboard',
		__('Servers', 'cw-profit'),
		__('Servers', 'cw-profit'),
		$cap,
		'cw-profit-servers',
		'cw_profit_render_servers_page'
	);

	add_submenu_page(
		'cw-profit-dashboard',
		__('Apps', 'cw-profit'),
		__('Apps', 'cw-profit'),
		$cap,
		'cw-profit-apps',
		'cw_profit_render_apps_page'
	);

	add_submenu_page(
		'cw-profit-dashboard',
		__('Settings', 'cw-profit'),
		__('Settings', 'cw-profit'),
		$cap,
		'cw-profit-settings',
		'cw_profit_render_settings_page'
	);

	// Hidden detail page (linked from dashboard).
	add_submenu_page(
		null,
		__('Server Detail', 'cw-profit'),
		__('Server Detail', 'cw-profit'),
		$cap,
		'cw-profit-server',
		'cw_profit_render_server_detail_page'
	);
}

function cw_profit_render_servers_page(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}

	global $wpdb;

	$last_sync = get_option(CW_PROFIT_OPTION_PREFIX . 'last_sync_at');
	$run_sync_url = wp_nonce_url(admin_url('admin-post.php?action=cw_profit_run_sync'), 'cw_profit_run_sync');
	$current_url = admin_url('admin.php?page=cw-profit-dashboard');

	$orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'label';
	$order = isset($_GET['order']) ? strtolower(sanitize_key(wp_unslash($_GET['order']))) : 'asc';
	if ($order !== 'asc' && $order !== 'desc') {
		$order = 'asc';
	}

	$allowed_orderby = array(
		'label' => 'server_label',
		'apps' => 'app_count',
		'cost' => 'monthly_cost',
		'revenue' => 'revenue',
		'profit' => 'profit',
		'margin' => 'margin',
		'attention' => 'attention_count',
	);
	if (!isset($allowed_orderby[$orderby])) {
		$orderby = 'label';
	}
	$order_sql = $order === 'desc' ? 'DESC' : 'ASC';
	$orderby_sql = $allowed_orderby[$orderby];

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Servers', 'cw-profit') . '</h1>';
	cw_profit_render_attention_box();
	echo '<p>' . esc_html__('Profitability by server.', 'cw-profit') . '</p>';
	echo '<p><a class="button button-primary" href="' . esc_url($run_sync_url) . '">' . esc_html__('Run sync now', 'cw-profit') . '</a></p>';
	echo '<p><strong>' . esc_html__('Last sync:', 'cw-profit') . '</strong> ' . esc_html($last_sync ? $last_sync : __('Never', 'cw-profit')) . '</p>';

	$servers_table = cw_profit_table_servers();
	$apps_table = cw_profit_table_apps();

	// Single query so we can sort by computed columns.
	$servers = $wpdb->get_results(
		"SELECT
			s.cloudways_server_id,
			COALESCE(NULLIF(s.label, ''), s.cloudways_server_id) AS server_label,
			s.monthly_cost,
			s.monthly_client_price,
			COUNT(a.id) AS app_count,
			SUM(CASE WHEN a.needs_attention = 1 AND s.monthly_client_price IS NULL THEN 1 ELSE 0 END) AS attention_count,
			COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) AS revenue,
			(COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) - COALESCE(s.monthly_cost, 0)) AS profit,
			CASE
				WHEN COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) > 0
				THEN (COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) - COALESCE(s.monthly_cost, 0)) / COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0))
				ELSE NULL
			END AS margin
		FROM {$servers_table} s
		LEFT JOIN {$apps_table} a ON a.server_id = s.id
		GROUP BY s.id
		ORDER BY {$orderby_sql} {$order_sql}, server_label ASC",
		ARRAY_A
	);

	if (empty($servers)) {
		echo '<p>' . esc_html__('No servers found yet. Configure Cloudways credentials in Settings, then run a sync.', 'cw-profit') . '</p>';
		echo '</div>';
		return;
	}

	$sort_link = function (string $col, string $label) use ($current_url, $orderby, $order): string {
		$next_order = ($orderby === $col && $order === 'asc') ? 'desc' : 'asc';
		$url = add_query_arg(
			array(
				'orderby' => $col,
				'order' => $next_order,
			),
			$current_url
		);
		$indicator = '';
		if ($orderby === $col) {
			$indicator = $order === 'asc' ? ' ▲' : ' ▼';
		}
		return '<a href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span><span class="screen-reader-text">' . esc_html($indicator) . '</span></a>' . esc_html($indicator);
	};

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . $sort_link('label', __('Server', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('apps', __('Apps', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('cost', __('Monthly Cost', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('revenue', __('Revenue', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('profit', __('Profit', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('margin', __('Margin', 'cw-profit')) . '</th>';
	echo '<th>' . $sort_link('attention', __('Attention', 'cw-profit')) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ($servers as $server) {
		$server_id = (string) $server['cloudways_server_id'];
		$server_label = (string) ($server['server_label'] ?: $server_id);
		$app_count = (int) $server['app_count'];
		$attention_count = (int) $server['attention_count'];

		$totals = array(
			'server_cost' => is_null($server['monthly_cost']) ? null : (float) $server['monthly_cost'],
			'revenue' => (float) $server['revenue'],
			'profit' => (float) $server['profit'],
			'margin' => is_null($server['margin']) ? null : (float) $server['margin'],
			'client_price' => is_null($server['monthly_client_price']) ? null : (float) $server['monthly_client_price'],
		);

		$detail_url = add_query_arg(
			array(
				'page' => 'cw-profit-server',
				'server_id' => $server_id,
			),
			admin_url('admin.php')
		);

		$row_style = '';
		if (!is_null($totals['margin'])) {
			$row_style = $totals['margin'] < 0 ? ' style="background:#fde8e8;"' : ' style="background:#e7f6ea;"';
		}
		echo '<tr' . $row_style . '>';
		echo '<td><a href="' . esc_url($detail_url) . '"><strong>' . esc_html($server_label) . '</strong></a><br/><code>' . esc_html($server_id) . '</code></td>';
		echo '<td>' . esc_html((string) $app_count) . '</td>';
		echo '<td>' . esc_html(cw_profit_format_money($totals['server_cost'])) . '</td>';
		$rev_label = '';
		if (isset($totals['client_price']) && !is_null($totals['client_price'])) {
			$rev_label = ' <span class="description">(' . esc_html__('client price', 'cw-profit') . ')</span>';
		}
		echo '<td>' . esc_html(cw_profit_format_money($totals['revenue'])) . $rev_label . '</td>';
		echo '<td>' . esc_html(cw_profit_format_money($totals['profit'])) . '</td>';
		echo '<td>' . esc_html(cw_profit_format_percent($totals['margin'])) . '</td>';
		echo '<td>' . esc_html((string) $attention_count) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	// Charts data preparation.
	$servers_by_profit = $servers;
	usort(
		$servers_by_profit,
		function (array $a, array $b): int {
			return ((float) $b['profit']) <=> ((float) $a['profit']);
		}
	);
	$servers_by_margin = $servers;
	usort(
		$servers_by_margin,
		function (array $a, array $b): int {
			$ma = is_null($a['margin']) ? -INF : (float) $a['margin'];
			$mb = is_null($b['margin']) ? -INF : (float) $b['margin'];
			return $ma <=> $mb;
		}
	);

	$chart1 = array(
		'labels' => array_map(fn($s) => (string) ($s['server_label'] ?? $s['cloudways_server_id']), $servers_by_profit),
		'revenue' => array_map(fn($s) => (float) ($s['revenue'] ?? 0), $servers_by_profit),
		'cost' => array_map(fn($s) => (float) ($s['monthly_cost'] ?? 0), $servers_by_profit),
		'profit' => array_map(fn($s) => (float) ($s['profit'] ?? 0), $servers_by_profit),
	);

	$chart2 = array(
		'labels' => array_map(fn($s) => (string) ($s['server_label'] ?? $s['cloudways_server_id']), $servers_by_margin),
		'margin_percent' => array_map(fn($s) => is_null($s['margin']) ? null : ((float) $s['margin'] * 100.0), $servers_by_margin),
	);

	// Scatter: each point represents a server.
	$chart3_points = array();
	foreach ($servers as $s) {
		$rev = (float) ($s['revenue'] ?? 0);
		$chart3_points[] = array(
			'x' => (int) ($s['app_count'] ?? 0),
			'y' => (float) ($s['profit'] ?? 0),
			'r' => max(3, (int) round(min(18, $rev / 50))), // scale revenue into bubble radius
			'server' => (string) ($s['server_label'] ?? $s['cloudways_server_id']),
			'apps' => (int) ($s['app_count'] ?? 0),
			'revenue' => $rev,
			'cost' => (float) ($s['monthly_cost'] ?? 0),
			'profit' => (float) ($s['profit'] ?? 0),
		);
	}


	echo '</div>';
}

function cw_profit_render_dashboard_home_page(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}

	global $wpdb;

	$last_sync = get_option(CW_PROFIT_OPTION_PREFIX . 'last_sync_at');
	$run_sync_url = wp_nonce_url(admin_url('admin-post.php?action=cw_profit_run_sync'), 'cw_profit_run_sync');

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Dashboard', 'cw-profit') . '</h1>';
	cw_profit_render_attention_box();
	echo '<p><a class="button button-primary" href="' . esc_url($run_sync_url) . '">' . esc_html__('Run sync now', 'cw-profit') . '</a></p>';
	echo '<p><strong>' . esc_html__('Last sync:', 'cw-profit') . '</strong> ' . esc_html($last_sync ? $last_sync : __('Never', 'cw-profit')) . '</p>';

	$servers_table = cw_profit_table_servers();
	$apps_table = cw_profit_table_apps();

	$servers = $wpdb->get_results(
		"SELECT
			s.cloudways_server_id,
			COALESCE(NULLIF(s.label, ''), s.cloudways_server_id) AS server_label,
			s.monthly_cost,
			s.monthly_client_price,
			COUNT(a.id) AS app_count,
			COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) AS revenue,
			(COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) - COALESCE(s.monthly_cost, 0)) AS profit,
			CASE
				WHEN COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) > 0
				THEN (COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0)) - COALESCE(s.monthly_cost, 0)) / COALESCE(s.monthly_client_price, COALESCE(SUM(COALESCE(a.monthly_price, 0)), 0))
				ELSE NULL
			END AS margin
		FROM {$servers_table} s
		LEFT JOIN {$apps_table} a ON a.server_id = s.id
		GROUP BY s.id",
		ARRAY_A
	);

	if (empty($servers)) {
		echo '<p>' . esc_html__('No servers found yet. Configure Cloudways credentials in Settings, then run a sync.', 'cw-profit') . '</p>';
		echo '</div>';
		return;
	}

	// Chart prep (reuse earlier logic).
	$servers_by_profit = $servers;
	usort($servers_by_profit, fn($a, $b) => ((float) $b['profit']) <=> ((float) $a['profit']));
	$servers_by_margin = $servers;
	usort(
		$servers_by_margin,
		function (array $a, array $b): int {
			$ma = is_null($a['margin']) ? -INF : (float) $a['margin'];
			$mb = is_null($b['margin']) ? -INF : (float) $b['margin'];
			return $ma <=> $mb;
		}
	);

	$chart1 = array(
		'labels' => array_map(fn($s) => (string) ($s['server_label'] ?? $s['cloudways_server_id']), $servers_by_profit),
		'revenue' => array_map(fn($s) => (float) ($s['revenue'] ?? 0), $servers_by_profit),
		'cost' => array_map(fn($s) => (float) ($s['monthly_cost'] ?? 0), $servers_by_profit),
		'profit' => array_map(fn($s) => (float) ($s['profit'] ?? 0), $servers_by_profit),
	);

	$chart2 = array(
		'labels' => array_map(fn($s) => (string) ($s['server_label'] ?? $s['cloudways_server_id']), $servers_by_margin),
		'margin_percent' => array_map(fn($s) => is_null($s['margin']) ? null : ((float) $s['margin'] * 100.0), $servers_by_margin),
	);

	$chart3_points = array();
	foreach ($servers as $s) {
		$rev = (float) ($s['revenue'] ?? 0);
		$chart3_points[] = array(
			'x' => (int) ($s['app_count'] ?? 0),
			'y' => (float) ($s['profit'] ?? 0),
			'r' => max(3, (int) round(min(18, $rev / 50))),
			'server' => (string) ($s['server_label'] ?? $s['cloudways_server_id']),
			'apps' => (int) ($s['app_count'] ?? 0),
			'revenue' => $rev,
			'cost' => (float) ($s['monthly_cost'] ?? 0),
			'profit' => (float) ($s['profit'] ?? 0),
		);
	}

	echo '<div class="cw-profit-chart-row">';
	echo '<div class="cw-profit-card">';
	echo '<h2>' . esc_html__('Server Profitability Overview', 'cw-profit') . '</h2>';
	echo '<p class="description">' . esc_html__('Revenue vs cost vs profit per server (sorted by profit).', 'cw-profit') . '</p>';
	echo '<canvas id="cwProfitChart1"></canvas>';
	echo '</div>';

	echo '<div class="cw-profit-card">';
	echo '<h2>' . esc_html__('Profit Margin % by Server', 'cw-profit') . '</h2>';
	echo '<p class="description">' . esc_html__('Margin % per server (sorted lowest to highest).', 'cw-profit') . '</p>';
	echo '<canvas id="cwProfitChart2"></canvas>';
	echo '</div>';

	echo '<div class="cw-profit-card">';
	echo '<h2>' . esc_html__('App Density vs Profit', 'cw-profit') . '</h2>';
	echo '<p class="description">' . esc_html__('Apps vs profit. Bubble size approximates revenue.', 'cw-profit') . '</p>';
	echo '<canvas id="cwProfitChart3"></canvas>';
	echo '</div>';
	echo '</div>';

	$chart_payload = array(
		'chart1' => $chart1,
		'chart2' => $chart2,
		'chart3' => array('points' => $chart3_points),
		'currency' => (string) get_option(CW_PROFIT_OPTION_PREFIX . 'currency', 'USD'),
	);

	$js = "window.cwProfitChartsData = " . wp_json_encode($chart_payload) . ";\n";
	$js .= "(function(){\n";
	$js .= "  function run(){\n";
	$js .= "    if (!window.Chart || !window.cwProfitChartsData) return false;\n";
	$js .= "    const data = window.cwProfitChartsData;\n";
	$js .= "    const COLORS = {\n";
	$js .= "      revenue: 'rgba(33, 150, 243, 0.75)',\n";
	$js .= "      cost: 'rgba(158, 158, 158, 0.75)',\n";
	$js .= "      profitPos: 'rgba(46, 125, 50, 0.85)',\n";
	$js .= "      profitNeg: 'rgba(211, 47, 47, 0.85)',\n";
	$js .= "      grid: 'rgba(0,0,0,0.08)',\n";
	$js .= "      tick: '#3c434a'\n";
	$js .= "    };\n";
	$js .= "    const fmtMoney = (n) => {\n";
	$js .= "      const v = (typeof n === 'number' && isFinite(n)) ? n : 0;\n";
	$js .= "      const amount = v.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});\n";
	$js .= "      const c = String(data.currency || 'USD').toUpperCase();\n";
	$js .= "      const symbols = { USD: '$', EUR: '€', GBP: '£', AUD: 'A$', CAD: 'C$', NZD: 'NZ$' };\n";
	$js .= "      return (symbols[c] ? (symbols[c] + amount) : (c + ' ' + amount));\n";
	$js .= "    };\n";
	$js .= "    const fmtPct = (n) => {\n";
	$js .= "      if (n === null || n === undefined || !isFinite(n)) return '—';\n";
	$js .= "      return n.toFixed(1) + '%';\n";
	$js .= "    };\n";
	$js .= "    const baseOptions = {\n";
	$js .= "      responsive: true,\n";
	$js .= "      maintainAspectRatio: false,\n";
	$js .= "      animation: { duration: 250 },\n";
	$js .= "      layout: { padding: { left: 6, right: 10, top: 6, bottom: 4 } },\n";
	$js .= "      plugins: {\n";
	$js .= "        legend: { labels: { boxWidth: 10, boxHeight: 10, color: COLORS.tick, font: { size: 11 } } },\n";
	$js .= "        tooltip: { mode: 'index', intersect: false, padding: 10, displayColors: true }\n";
	$js .= "      }\n";
	$js .= "    };\n";
	$js .= "    const subtleGrid = {\n";
	$js .= "      grid: { color: COLORS.grid, drawBorder: false },\n";
	$js .= "      ticks: { color: COLORS.tick, font: { size: 11 } }\n";
	$js .= "    };\n";
	$js .= "    const yZeroLinePlugin = {\n";
	$js .= "      id: 'yZeroLinePlugin',\n";
	$js .= "      afterDraw(chart){\n";
	$js .= "        const yScale = chart.scales && chart.scales.y;\n";
	$js .= "        const xScale = chart.scales && chart.scales.x;\n";
	$js .= "        if (!yScale || !xScale) return;\n";
	$js .= "        const y0 = yScale.getPixelForValue(0);\n";
	$js .= "        const ctx = chart.ctx;\n";
	$js .= "        ctx.save();\n";
	$js .= "        ctx.strokeStyle = 'rgba(0,0,0,0.30)';\n";
	$js .= "        ctx.lineWidth = 1;\n";
	$js .= "        ctx.beginPath();\n";
	$js .= "        ctx.moveTo(xScale.left, y0);\n";
	$js .= "        ctx.lineTo(xScale.right, y0);\n";
	$js .= "        ctx.stroke();\n";
	$js .= "        ctx.restore();\n";
	$js .= "      }\n";
	$js .= "    };\n";
	$js .= "    const xReferenceLinesPlugin = {\n";
	$js .= "      id: 'xReferenceLinesPlugin',\n";
	$js .= "      afterDraw(chart, args, opts){\n";
	$js .= "        const xScale = chart.scales && chart.scales.x;\n";
	$js .= "        const yScale = chart.scales && chart.scales.y;\n";
	$js .= "        if (!xScale || !yScale) return;\n";
	$js .= "        const values = (opts && Array.isArray(opts.values)) ? opts.values : [];\n";
	$js .= "        const ctx = chart.ctx;\n";
	$js .= "        ctx.save();\n";
	$js .= "        values.forEach(v => {\n";
	$js .= "          const x = xScale.getPixelForValue(v);\n";
	$js .= "          ctx.strokeStyle = (v === 0) ? 'rgba(0,0,0,0.35)' : 'rgba(0,0,0,0.18)';\n";
	$js .= "          ctx.lineWidth = 1;\n";
	$js .= "          ctx.beginPath();\n";
	$js .= "          ctx.moveTo(x, yScale.top);\n";
	$js .= "          ctx.lineTo(x, yScale.bottom);\n";
	$js .= "          ctx.stroke();\n";
	$js .= "        });\n";
	$js .= "        ctx.restore();\n";
	$js .= "      }\n";
	$js .= "    };\n";
	$js .= "    const barValueLabelsPlugin = {\n";
	$js .= "      id: 'barValueLabelsPlugin',\n";
	$js .= "      afterDatasetsDraw(chart){\n";
	$js .= "        const ds = chart.data && chart.data.datasets && chart.data.datasets[0];\n";
	$js .= "        if (!ds) return;\n";
	$js .= "        const meta = chart.getDatasetMeta(0);\n";
	$js .= "        const ctx = chart.ctx;\n";
	$js .= "        ctx.save();\n";
	$js .= "        ctx.font = '12px sans-serif';\n";
	$js .= "        ctx.fillStyle = '#1d2327';\n";
	$js .= "        meta.data.forEach((bar, i) => {\n";
	$js .= "          const val = ds.data[i];\n";
	$js .= "          if (val === null || val === undefined || !isFinite(val)) return;\n";
	$js .= "          const txt = fmtPct(val);\n";
	$js .= "          const pad = 6;\n";
	$js .= "          const w = ctx.measureText(txt).width;\n";
	$js .= "          const lx = (val >= 0) ? (bar.x + pad) : (bar.x - pad - w);\n";
	$js .= "          ctx.fillText(txt, lx, bar.y + 4);\n";
	$js .= "        });\n";
	$js .= "        ctx.restore();\n";
	$js .= "      }\n";
	$js .= "    };\n";
	$js .= "    const c1 = document.getElementById('cwProfitChart1');\n";
	$js .= "    if (c1) {\n";
	$js .= "      const rows = data.chart1.labels.map((label, i) => ({\n";
	$js .= "        label,\n";
	$js .= "        revenue: Number(data.chart1.revenue[i] ?? 0),\n";
	$js .= "        cost: Number(data.chart1.cost[i] ?? 0),\n";
	$js .= "        profit: Number(data.chart1.profit[i] ?? 0)\n";
	$js .= "      }));\n";
	$js .= "      // Presentation-only: keep top servers to avoid overcrowding.\n";
	$js .= "      rows.sort((a,b) => (b.revenue - a.revenue) || (b.profit - a.profit));\n";
	$js .= "      const top = rows.slice(0, Math.min(10, rows.length));\n";
	$js .= "      const labels = top.map(r => r.label);\n";
	$js .= "      const rev = top.map(r => r.revenue);\n";
	$js .= "      const cost = top.map(r => r.cost);\n";
	$js .= "      const profit = top.map(r => r.profit);\n";
	$js .= "      const allY = rev.concat(cost, profit).filter(v => typeof v === 'number' && isFinite(v));\n";
	$js .= "      const minY = Math.min.apply(null, allY.concat([0]));\n";
	$js .= "      const maxY = Math.max.apply(null, allY.concat([0]));\n";
	$js .= "      const pad = (maxY - minY) * 0.12;\n";
	$js .= "      const suggestedMin = (isFinite(minY) ? (minY - pad) : 0);\n";
	$js .= "      const suggestedMax = (isFinite(maxY) ? (maxY + pad) : 0);\n";
	$js .= "      new Chart(c1.getContext('2d'), {\n";
	$js .= "        type: 'bar',\n";
	$js .= "        data: {\n";
	$js .= "          labels,\n";
	$js .= "          datasets: [\n";
	$js .= "            { label: 'Revenue', data: rev, backgroundColor: COLORS.revenue, borderRadius: 4, maxBarThickness: 22 },\n";
	$js .= "            { label: 'Cost', data: cost, backgroundColor: COLORS.cost, borderRadius: 4, maxBarThickness: 22 },\n";
	$js .= "            { label: 'Profit', data: profit, backgroundColor: profit.map(v => v < 0 ? COLORS.profitNeg : COLORS.profitPos), borderRadius: 4, maxBarThickness: 22 }\n";
	$js .= "          ]\n";
	$js .= "        },\n";
	$js .= "        options: {\n";
	$js .= "          ...baseOptions,\n";
	$js .= "          interaction: { mode: 'index', intersect: false },\n";
	$js .= "          datasets: { bar: { categoryPercentage: 0.72, barPercentage: 0.92 } },\n";
	$js .= "          plugins: {\n";
	$js .= "            ...baseOptions.plugins,\n";
	$js .= "            legend: { position: 'top' },\n";
	$js .= "            tooltip: {\n";
	$js .= "              ...baseOptions.plugins.tooltip,\n";
	$js .= "              callbacks: {\n";
	$js .= "                title: (items) => (items && items[0] && items[0].label ? ('Server: ' + items[0].label) : ''),\n";
	$js .= "                label: (ctx) => (ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y))\n";
	$js .= "              }\n";
	$js .= "            }\n";
	$js .= "          },\n";
	$js .= "          scales: {\n";
	$js .= "            x: {\n";
	$js .= "              ...subtleGrid,\n";
	$js .= "              grid: { display: false, drawBorder: false },\n";
	$js .= "              ticks: { ...subtleGrid.ticks, maxRotation: 45, minRotation: 45, autoSkip: false }\n";
	$js .= "            },\n";
	$js .= "            y: {\n";
	$js .= "              ...subtleGrid,\n";
	$js .= "              suggestedMin,\n";
	$js .= "              suggestedMax,\n";
	$js .= "              ticks: { ...subtleGrid.ticks, callback: (v) => fmtMoney(v) }\n";
	$js .= "            }\n";
	$js .= "          }\n";
	$js .= "        },\n";
	$js .= "        plugins: [yZeroLinePlugin]\n";
	$js .= "      });\n";
	$js .= "    }\n";
	$js .= "    const c2 = document.getElementById('cwProfitChart2');\n";
	$js .= "    if (c2) {\n";
	$js .= "      // Presentation-only: show only servers with meaningful margin values (revenue > 0 typically yields finite margin).\n";
	$js .= "      const rows = data.chart2.labels.map((label, i) => ({ label, margin: data.chart2.margin_percent[i] }));\n";
	$js .= "      const filtered = rows.filter(r => r.margin !== null && r.margin !== undefined && isFinite(r.margin));\n";
	$js .= "      filtered.sort((a,b) => a.margin - b.margin);\n";
	$js .= "      const labels = filtered.map(r => r.label);\n";
	$js .= "      const vals = filtered.map(r => r.margin);\n";
	$js .= "      const colorForMargin = (v) => {\n";
	$js .= "        // Shades: negative => red, positive => green. Intensity increases with magnitude.\n";
	$js .= "        if (!isFinite(v)) return 'rgba(189,189,189,0.5)';\n";
	$js .= "        if (v < 0) {\n";
	$js .= "          const t = Math.min(Math.abs(v), 50) / 50; // clamp to -50%\n";
	$js .= "          const a = 0.45 + (0.40 * t);\n";
	$js .= "          return 'rgba(211,47,47,' + a.toFixed(2) + ')';\n";
	$js .= "        }\n";
	$js .= "        const t = Math.min(v, 100) / 100; // clamp to 100%\n";
	$js .= "        const a = 0.45 + (0.40 * t);\n";
	$js .= "        return 'rgba(46,125,50,' + a.toFixed(2) + ')';\n";
	$js .= "      };\n";
	$js .= "      const colors = vals.map(colorForMargin);\n";
	$js .= "      new Chart(c2.getContext('2d'), {\n";
	$js .= "        type: 'bar',\n";
	$js .= "        data: {\n";
	$js .= "          labels,\n";
	$js .= "          datasets: [{ label: 'Margin %', data: vals, backgroundColor: colors, borderRadius: 5, barThickness: 18 }]\n";
	$js .= "        },\n";
	$js .= "        options: {\n";
	$js .= "          indexAxis: 'y',\n";
	$js .= "          ...baseOptions,\n";
	$js .= "          // Make hover forgiving so tooltips trigger reliably.\n";
	$js .= "          interaction: { mode: 'nearest', axis: 'y', intersect: false },\n";
	$js .= "          plugins: {\n";
	$js .= "            ...baseOptions.plugins,\n";
	$js .= "            legend: {\n";
	$js .= "              display: true,\n";
	$js .= "              position: 'top',\n";
	$js .= "              labels: {\n";
	$js .= "                ...baseOptions.plugins.legend.labels,\n";
	$js .= "                generateLabels: () => ([\n";
	$js .= "                  { text: 'Positive margin', fillStyle: 'rgba(46,125,50,0.80)', strokeStyle: 'rgba(46,125,50,0.80)', lineWidth: 0, hidden: false },\n";
	$js .= "                  { text: 'Negative margin', fillStyle: 'rgba(211,47,47,0.80)', strokeStyle: 'rgba(211,47,47,0.80)', lineWidth: 0, hidden: false }\n";
	$js .= "                ])\n";
	$js .= "              }\n";
	$js .= "            },\n";
	$js .= "            xReferenceLinesPlugin: { values: [0, 20, 50] },\n";
	$js .= "            tooltip: { mode: 'nearest', intersect: false, ...baseOptions.plugins.tooltip, callbacks: { title: (items) => (items && items[0] && items[0].label ? ('Server: ' + items[0].label) : ''), label: (ctx) => ('Margin: ' + fmtPct(ctx.parsed.x)) } }\n";
	$js .= "          },\n";
	$js .= "          scales: {\n";
	$js .= "            x: {\n";
	$js .= "              ...subtleGrid,\n";
	$js .= "              suggestedMin: -50,\n";
	$js .= "              suggestedMax: 100,\n";
	$js .= "              ticks: { ...subtleGrid.ticks, callback: (v) => v + '%' }\n";
	$js .= "            },\n";
	$js .= "            y: {\n";
	$js .= "              ...subtleGrid,\n";
	$js .= "              grid: { display: false, drawBorder: false },\n";
	$js .= "              ticks: { ...subtleGrid.ticks, autoSkip: false }\n";
	$js .= "            }\n";
	$js .= "          }\n";
	$js .= "        },\n";
	$js .= "        plugins: [barValueLabelsPlugin, xReferenceLinesPlugin]\n";
	$js .= "      });\n";
	$js .= "    }\n";
	$js .= "    const c3 = document.getElementById('cwProfitChart3');\n";
	$js .= "    if (c3) {\n";
	$js .= "      const pts = data.chart3.points || [];\n";
	$js .= "      const bg = pts.map(p => (p.y < 0 ? 'rgba(211,47,47,0.70)' : 'rgba(46,125,50,0.70)'));\n";
	$js .= "      // Optional: label the worst 3 negative-profit servers.\n";
	$js .= "      const worst = pts.filter(p => p && isFinite(p.y) && p.y < 0).slice().sort((a,b) => a.y - b.y).slice(0,3);\n";
	$js .= "      const worstLabelPlugin = {\n";
	$js .= "        id: 'worstLabelPlugin',\n";
	$js .= "        afterDatasetsDraw(chart){\n";
	$js .= "          if (!worst.length) return;\n";
	$js .= "          const ctx = chart.ctx;\n";
	$js .= "          const meta = chart.getDatasetMeta(0);\n";
	$js .= "          ctx.save();\n";
	$js .= "          ctx.font = '12px sans-serif';\n";
	$js .= "          ctx.fillStyle = 'rgba(29,35,39,0.9)';\n";
	$js .= "          ctx.textBaseline = 'middle';\n";
	$js .= "          meta.data.forEach((el, idx) => {\n";
	$js .= "            const p = pts[idx];\n";
	$js .= "            if (!p) return;\n";
	$js .= "            const match = worst.find(w => w.server === p.server && w.y === p.y && w.x === p.x);\n";
	$js .= "            if (!match) return;\n";
	$js .= "            ctx.fillText(p.server, el.x + 10, el.y);\n";
	$js .= "          });\n";
	$js .= "          ctx.restore();\n";
	$js .= "        }\n";
	$js .= "      };\n";
	$js .= "      new Chart(c3.getContext('2d'), {\n";
	$js .= "        type: 'bubble',\n";
	$js .= "        data: { datasets: [{ label: 'Servers', data: pts.map(p => ({...p, r: Math.max(6, Number(p.r ?? 6))})), backgroundColor: bg, borderColor: 'rgba(0,0,0,0.08)', borderWidth: 1 }] },\n";
	$js .= "        options: {\n";
	$js .= "          ...baseOptions,\n";
	$js .= "          interaction: { mode: 'nearest', intersect: true },\n";
	$js .= "          plugins: {\n";
	$js .= "            ...baseOptions.plugins,\n";
	$js .= "            legend: { display: false },\n";
	$js .= "            tooltip: {\n";
	$js .= "              ...baseOptions.plugins.tooltip,\n";
	$js .= "              callbacks: {\n";
	$js .= "                title: (items) => (items && items[0] && items[0].raw && items[0].raw.server ? ('Server: ' + items[0].raw.server) : ''),\n";
	$js .= "                label: (ctx) => {\n";
	$js .= "                  const p = ctx.raw;\n";
	$js .= "                  return [\n";
	$js .= "                    'Apps: ' + p.apps,\n";
	$js .= "                    'Revenue: ' + fmtMoney(p.revenue),\n";
	$js .= "                    'Cost: ' + fmtMoney(p.cost),\n";
	$js .= "                    'Profit: ' + fmtMoney(p.profit),\n";
	$js .= "                  ];\n";
	$js .= "                }\n";
	$js .= "              }\n";
	$js .= "            }\n";
	$js .= "          },\n";
	$js .= "          scales: {\n";
	$js .= "            x: { ...subtleGrid, title: { display: true, text: 'App count', color: COLORS.tick, font: { size: 11, weight: '600' } }, beginAtZero: true },\n";
	$js .= "            y: { ...subtleGrid, title: { display: true, text: 'Profit', color: COLORS.tick, font: { size: 11, weight: '600' } }, ticks: { ...subtleGrid.ticks, callback: (v) => fmtMoney(v) } }\n";
	$js .= "          }\n";
	$js .= "        },\n";
	$js .= "        plugins: [yZeroLinePlugin, worstLabelPlugin]\n";
	$js .= "      });\n";
	$js .= "    }\n";
	$js .= "    return true;\n";
	$js .= "  }\n";
	$js .= "  function boot(){\n";
	$js .= "    if (run()) return;\n";
	$js .= "    let tries = 0;\n";
	$js .= "    const t = setInterval(() => {\n";
	$js .= "      tries++;\n";
	$js .= "      if (run() || tries > 40) clearInterval(t);\n";
	$js .= "    }, 250);\n";
	$js .= "  }\n";
	$js .= "  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);\n";
	$js .= "  else boot();\n";
	$js .= "})();\n";

	if (function_exists('wp_add_inline_script')) {
		wp_add_inline_script('cw-profit-chartjs', $js, 'after');
	}

	echo '</div>';
}

function cw_profit_render_apps_page(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}
	global $wpdb;

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Apps', 'cw-profit') . '</h1>';
	cw_profit_render_attention_box();

	$apps_table = cw_profit_table_apps();
	$servers_table = cw_profit_table_servers();

	$apps = $wpdb->get_results(
		"SELECT a.*, s.label AS server_label,
			CASE WHEN s.monthly_client_price IS NULL THEN a.needs_attention ELSE 0 END AS effective_needs_attention
		 FROM {$apps_table} a
		 LEFT JOIN {$servers_table} s ON s.id = a.server_id
		 ORDER BY effective_needs_attention DESC, a.app_name ASC, a.cloudways_app_id ASC",
		ARRAY_A
	);

	if (empty($apps)) {
		echo '<p>' . esc_html__('No apps found yet. Run a sync first.', 'cw-profit') . '</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__('App', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Server', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Monthly Price', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Cost Share', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Attention', 'cw-profit') . '</th>';
	echo '</tr></thead><tbody>';

	foreach ($apps as $app) {
		$app_id = (string) $app['cloudways_app_id'];
		$server_id = (string) $app['cloudways_server_id'];
		$app_label = (string) ($app['app_name'] ?: $app_id);
		$server_label = (string) ($app['server_label'] ?: $server_id);

		$save_url = admin_url('admin-post.php?action=cw_profit_save_app');

		echo '<tr>';
		echo '<td><strong>' . esc_html($app_label) . '</strong><br/><code>' . esc_html($app_id) . '</code></td>';
		echo '<td>' . esc_html($server_label) . '</td>';
		echo '<td>';
		echo '<form method="post" action="' . esc_url($save_url) . '">';
		wp_nonce_field('cw_profit_save_app', 'cw_profit_save_app_nonce');
		echo '<input type="hidden" name="cloudways_app_id" value="' . esc_attr($app_id) . '"/>';
		echo '<input type="number" step="0.01" min="0" name="monthly_price" value="' . esc_attr((string) $app['monthly_price']) . '" class="small-text" />';
		echo ' <input type="submit" class="button" value="' . esc_attr__('Save', 'cw-profit') . '"/>';
		echo '</form>';
		echo '</td>';
		echo '<td>' . esc_html((string) $app['cost_share_type']) . '</td>';
		$needs = isset($app['effective_needs_attention']) ? (int) $app['effective_needs_attention'] : (int) $app['needs_attention'];
		echo '<td>' . ($needs ? '<strong>' . esc_html__('Yes', 'cw-profit') . '</strong>' : esc_html__('No', 'cw-profit')) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	echo '</div>';
}

function cw_profit_render_server_detail_page(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}

	global $wpdb;

	$server_id = isset($_GET['server_id']) ? sanitize_text_field(wp_unslash($_GET['server_id'])) : '';
	if ($server_id === '') {
		wp_die(__('Missing server id.', 'cw-profit'));
	}

	$servers_table = cw_profit_table_servers();
	$apps_table = cw_profit_table_apps();

	$server = $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM {$servers_table} WHERE cloudways_server_id = %s", $server_id),
		ARRAY_A
	);
	if (!$server) {
		wp_die(__('Server not found.', 'cw-profit'));
	}

	$server_pk = isset($server['id']) ? (int) $server['id'] : 0;
	$apps = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$apps_table}
			 WHERE (server_id = %d OR (server_id IS NULL AND cloudways_server_id = %s))
			 ORDER BY needs_attention DESC, app_name ASC",
			$server_pk,
			$server_id
		),
		ARRAY_A
	);

	$back_url = add_query_arg(array('page' => 'cw-profit-dashboard'), admin_url('admin.php'));
	$save_cost_url = admin_url('admin-post.php?action=cw_profit_save_server_cost');
	$save_app_url = admin_url('admin-post.php?action=cw_profit_save_app');

	$totals = cw_profit_calculate_server_totals($server_id);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Server Detail', 'cw-profit') . '</h1>';
	cw_profit_render_attention_box();
	echo '<p><a href="' . esc_url($back_url) . '">&larr; ' . esc_html__('Back to servers', 'cw-profit') . '</a></p>';

	echo '<h2>' . esc_html((string) ($server['label'] ?: $server_id)) . '</h2>';
	echo '<p><code>' . esc_html($server_id) . '</code></p>';

	echo '<h3>' . esc_html__('Totals', 'cw-profit') . '</h3>';
	echo '<ul>';
	$rev_source = (isset($totals['client_price']) && !is_null($totals['client_price']))
		? esc_html__('(client price override)', 'cw-profit')
		: esc_html__('(sum of app prices)', 'cw-profit');
	echo '<li><strong>' . esc_html__('Revenue:', 'cw-profit') . '</strong> ' . esc_html(cw_profit_format_money($totals['revenue'])) . ' <span class="description">' . $rev_source . '</span></li>';
	echo '<li><strong>' . esc_html__('Server cost:', 'cw-profit') . '</strong> ' . esc_html(cw_profit_format_money($totals['server_cost'])) . '</li>';
	echo '<li><strong>' . esc_html__('Profit:', 'cw-profit') . '</strong> ' . esc_html(cw_profit_format_money($totals['profit'])) . '</li>';
	echo '<li><strong>' . esc_html__('Margin:', 'cw-profit') . '</strong> ' . esc_html(cw_profit_format_percent($totals['margin'])) . '</li>';
	echo '</ul>';

	echo '<h3>' . esc_html__('Client monthly price (optional override)', 'cw-profit') . '</h3>';
	echo '<p class="description">' . esc_html__('If set, this server’s revenue is this single amount (app prices are ignored for server profitability).', 'cw-profit') . '</p>';
	echo '<form method="post" action="' . esc_url($save_cost_url) . '">';
	wp_nonce_field('cw_profit_save_server_cost', 'cw_profit_save_server_cost_nonce');
	echo '<input type="hidden" name="cloudways_server_id" value="' . esc_attr($server_id) . '"/>';
	echo '<input type="number" step="0.01" min="0" name="monthly_client_price" value="' . esc_attr((string) $server['monthly_client_price']) . '" class="small-text" />';
	echo ' <input type="submit" class="button" value="' . esc_attr__('Save client price', 'cw-profit') . '"/>';
	echo '</form>';

	echo '<h3>' . esc_html__('Server monthly cost', 'cw-profit') . '</h3>';
	echo '<form method="post" action="' . esc_url($save_cost_url) . '">';
	wp_nonce_field('cw_profit_save_server_cost', 'cw_profit_save_server_cost_nonce');
	echo '<input type="hidden" name="cloudways_server_id" value="' . esc_attr($server_id) . '"/>';
	echo '<input type="number" step="0.01" min="0" name="monthly_cost" value="' . esc_attr((string) $server['monthly_cost']) . '" class="small-text" />';
	echo ' <input type="submit" class="button button-primary" value="' . esc_attr__('Save cost', 'cw-profit') . '"/>';
	echo '</form>';

	echo '<h3>' . esc_html__('Apps on this server', 'cw-profit') . '</h3>';
	if (empty($apps)) {
		echo '<p>' . esc_html__('No apps found for this server yet.', 'cw-profit') . '</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__('App', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Monthly price', 'cw-profit') . '</th>';
	echo '<th>' . esc_html__('Attention', 'cw-profit') . '</th>';
	echo '</tr></thead><tbody>';

	foreach ($apps as $app) {
		$app_id = (string) $app['cloudways_app_id'];
		$app_label = (string) ($app['app_name'] ?: $app_id);
		echo '<tr>';
		echo '<td><strong>' . esc_html($app_label) . '</strong><br/><code>' . esc_html($app_id) . '</code></td>';
		echo '<td>';
		echo '<form method="post" action="' . esc_url($save_app_url) . '">';
		wp_nonce_field('cw_profit_save_app', 'cw_profit_save_app_nonce');
		echo '<input type="hidden" name="cloudways_app_id" value="' . esc_attr($app_id) . '"/>';
		echo '<input type="number" step="0.01" min="0" name="monthly_price" value="' . esc_attr((string) $app['monthly_price']) . '" class="small-text" />';
		echo ' <input type="submit" class="button" value="' . esc_attr__('Save', 'cw-profit') . '"/>';
		echo '</form>';
		echo '</td>';
		$effective_needs_attention = (isset($server['monthly_client_price']) && !is_null($server['monthly_client_price'])) ? 0 : (int) $app['needs_attention'];
		echo '<td>' . ($effective_needs_attention ? '<strong>' . esc_html__('Yes', 'cw-profit') . '</strong>' : esc_html__('No', 'cw-profit')) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}

function cw_profit_handle_save_server_cost(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}
	check_admin_referer('cw_profit_save_server_cost', 'cw_profit_save_server_cost_nonce');

	$server_id = isset($_POST['cloudways_server_id']) ? sanitize_text_field(wp_unslash($_POST['cloudways_server_id'])) : '';
	$monthly_cost = isset($_POST['monthly_cost']) ? (float) wp_unslash($_POST['monthly_cost']) : null;
	$monthly_client_price = isset($_POST['monthly_client_price']) ? (float) wp_unslash($_POST['monthly_client_price']) : null;
	if ($server_id === '') {
		wp_die(__('Missing server id.', 'cw-profit'));
	}

	global $wpdb;
	$servers_table = cw_profit_table_servers();

	$update = array(
		'updated_at' => gmdate('Y-m-d H:i:s'),
	);
	if (!is_null($monthly_cost)) {
		$update['monthly_cost'] = max(0.0, $monthly_cost);
	}
	if (!is_null($monthly_client_price)) {
		$update['monthly_client_price'] = max(0.0, $monthly_client_price);
	}

	$wpdb->update(
		$servers_table,
		$update,
		array('cloudways_server_id' => $server_id)
	);

	// If server uses client total price, apps should not be flagged for attention.
	if (array_key_exists('monthly_client_price', $_POST)) {
		$apps_table = cw_profit_table_apps();
		$server_pk = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$servers_table} WHERE cloudways_server_id = %s", $server_id));
		$wpdb->update(
			$apps_table,
			array(
				'needs_attention' => 0,
				'updated_at' => gmdate('Y-m-d H:i:s'),
			),
			$server_pk > 0 ? array('server_id' => $server_pk) : array('cloudways_server_id' => $server_id)
		);
	}

	$redirect = add_query_arg(
		array(
			'page' => 'cw-profit-server',
			'server_id' => $server_id,
		),
		admin_url('admin.php')
	);
	wp_safe_redirect($redirect);
	exit;
}

function cw_profit_handle_save_app(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}
	check_admin_referer('cw_profit_save_app', 'cw_profit_save_app_nonce');

	$app_id = isset($_POST['cloudways_app_id']) ? sanitize_text_field(wp_unslash($_POST['cloudways_app_id'])) : '';
	if ($app_id === '') {
		wp_die(__('Missing app id.', 'cw-profit'));
	}

	$has_price_field = array_key_exists('monthly_price', $_POST);
	$raw_price = $has_price_field ? (string) wp_unslash($_POST['monthly_price']) : '';
	$raw_price = trim($raw_price);

	global $wpdb;
	$apps_table = cw_profit_table_apps();

	// Treat blank as missing; treat 0.00 as a valid saved value (free).
	$monthly_price = null;
	$needs_attention = 0;
	if (!$has_price_field || $raw_price === '') {
		$monthly_price = null;
		$needs_attention = 1;
	} else {
		$monthly_price = max(0.0, (float) $raw_price);
		$needs_attention = 0;
	}

	$wpdb->update(
		$apps_table,
		array(
			'monthly_price' => $monthly_price,
			'needs_attention' => $needs_attention,
			'updated_at' => gmdate('Y-m-d H:i:s'),
		),
		array('cloudways_app_id' => $app_id)
	);

	// Redirect back to referrer if present.
	$redirect = wp_get_referer();
	if (!$redirect) {
		$redirect = add_query_arg(array('page' => 'cw-profit-apps'), admin_url('admin.php'));
	}
	wp_safe_redirect($redirect);
	exit;
}

function cw_profit_render_settings_page(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}

	if (isset($_POST['cw_profit_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cw_profit_settings_nonce'])), 'cw_profit_save_settings')) {
		$email = isset($_POST['cloudways_email']) ? sanitize_email(wp_unslash($_POST['cloudways_email'])) : '';
		$api_key = isset($_POST['cloudways_api_key']) ? sanitize_text_field(wp_unslash($_POST['cloudways_api_key'])) : '';
		$currency = isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : 'USD';
		$enable_email = isset($_POST['enable_daily_email']) ? 1 : 0;
		$email_to = isset($_POST['daily_email_to']) ? sanitize_email(wp_unslash($_POST['daily_email_to'])) : '';

		update_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_email', $email, false);
		update_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_api_key', $api_key, false);
		update_option(CW_PROFIT_OPTION_PREFIX . 'currency', $currency ?: 'USD', false);
		update_option(CW_PROFIT_OPTION_PREFIX . 'enable_daily_email', $enable_email, false);
		update_option(CW_PROFIT_OPTION_PREFIX . 'daily_email_to', $email_to, false);

		// Clear cached token if creds changed.
		delete_transient(CW_PROFIT_OPTION_PREFIX . 'access_token');
		delete_transient(CW_PROFIT_OPTION_PREFIX . 'access_token_expires_at');

		echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'cw-profit') . '</p></div>';
	}

	$email = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_email', '');
	$api_key = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_api_key', '');
	$currency = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'currency', 'USD');
	$enable_email = (int) get_option(CW_PROFIT_OPTION_PREFIX . 'enable_daily_email', 0);
	$email_to = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'daily_email_to', '');

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Settings', 'cw-profit') . '</h1>';
	cw_profit_render_attention_box();
	echo '<form method="post">';
	wp_nonce_field('cw_profit_save_settings', 'cw_profit_settings_nonce');

	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="cloudways_email">' . esc_html__('Cloudways Email', 'cw-profit') . '</label></th>';
	echo '<td><input name="cloudways_email" id="cloudways_email" type="email" class="regular-text" value="' . esc_attr($email) . '" /></td></tr>';

	echo '<tr><th scope="row"><label for="cloudways_api_key">' . esc_html__('Cloudways API Key', 'cw-profit') . '</label></th>';
	echo '<td><input name="cloudways_api_key" id="cloudways_api_key" type="password" class="regular-text" value="' . esc_attr($api_key) . '" autocomplete="new-password" /></td></tr>';

	echo '<tr><th scope="row"><label for="currency">' . esc_html__('Currency', 'cw-profit') . '</label></th>';
	echo '<td><input name="currency" id="currency" type="text" class="small-text" value="' . esc_attr($currency) . '" /></td></tr>';

	echo '<tr><th scope="row">' . esc_html__('Daily email digest', 'cw-profit') . '</th><td>';
	echo '<label><input type="checkbox" name="enable_daily_email" value="1" ' . checked(1, $enable_email, false) . ' /> ' . esc_html__('Send daily missing-data/new-apps email', 'cw-profit') . '</label><br/>';
	echo '<input name="daily_email_to" type="email" class="regular-text" value="' . esc_attr($email_to) . '" placeholder="' . esc_attr__('you@company.com', 'cw-profit') . '" />';
	echo '</td></tr>';

	echo '</table>';

	submit_button(__('Save Settings', 'cw-profit'));
	echo '</form>';
	echo '</div>';
}

function cw_profit_handle_run_sync(): void {
	if (!current_user_can('manage_options')) {
		wp_die(__('Insufficient permissions.', 'cw-profit'));
	}
	check_admin_referer('cw_profit_run_sync');

	$result = cw_profit_run_sync();
	$redirect = add_query_arg(
		array(
			'page' => 'cw-profit-dashboard',
			'cw_profit_sync' => $result['result'] ?? 'unknown',
		),
		admin_url('admin.php')
	);
	wp_safe_redirect($redirect);
	exit;
}

