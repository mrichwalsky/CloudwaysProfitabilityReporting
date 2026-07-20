# Cloudways Profitability Reporting (WordPress plugin)

WordPress admin plugin that syncs your Cloudways servers/apps and helps you track **server cost vs app pricing** to understand profitability (revenue, profit, margin) per server.

## Features
- Sync Cloudways servers + apps into WordPress tables
- Track server monthly cost and optional client price
- Track app monthly price and cost-share allocation settings
- Dashboard + charts for revenue/cost/profit and margin
- “Needs attention” highlights missing pricing/cost data

## Requirements
- WordPress 6.x (should work on 5.9+, but 6.x recommended)
- PHP 8.0+ recommended
- A Cloudways account with API access

## Install (local / manual)
1. Copy this folder into `wp-content/plugins/` as:
   - `cloudways-profitability-tracker/`
2. Activate **Cloudways Profitability Tracker**
3. In WP Admin, go to **Cloudways Profitability → Settings**
4. Enter your **Cloudways email** and **API key**
5. Go to the Dashboard and click **Run sync now**

## Notes
- **Currency display**: set a 3-letter currency code (default `USD`) in Settings. USD is displayed with `$` for cleaner visuals.
- **Data storage**: the plugin creates its own tables (see `includes/db.php`).

## CSV exports

The **Servers** admin page provides separate server and site CSV downloads. Monetary values are
plain decimal numbers without currency symbols or thousands separators; the currency is included
in its own column.

- The server export contains the effective server revenue and identifies whether it comes from a
  server-level price or the sum of site prices.
- The site export contains `site_revenue` when revenue is tracked per site.
- When a server-level revenue override is used, `site_revenue` is blank because that total is not
  allocated to individual sites. In those rows, `revenue_tracking_level` is `server_level` and
  `server_revenue` contains the server total for context. The repeated server total must not be
  summed across site rows.

## Development
- Main plugin file: `cloudways-profitability-tracker.php`
- Key modules:
  - `includes/sync.php` – Cloudways API sync + persistence
  - `includes/reports.php` – profitability calculations + formatting
  - `includes/admin-menu.php` – admin UI + charts

## Security
This plugin stores Cloudways API credentials in WordPress options. Treat the site as sensitive:
- Use least-privilege WP admin access
- Prefer staging/testing sites for development
- Rotate API keys if you suspect exposure

## License
Add a license file if you plan to distribute publicly.
