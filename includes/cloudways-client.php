<?php

if (!defined('ABSPATH')) {
	exit;
}

class CW_Profit_Cloudways_Client {
	private string $base_url;

	public function __construct(?string $base_url = null) {
		$this->base_url = $base_url ?: 'https://api.cloudways.com/api/v2';
	}

	public function get_access_token(): string {
		$cached = get_transient(CW_PROFIT_OPTION_PREFIX . 'access_token');
		$expires_at = (int) get_transient(CW_PROFIT_OPTION_PREFIX . 'access_token_expires_at');
		if (is_string($cached) && $cached !== '' && ($expires_at - time()) > 60) {
			return $cached;
		}

		$email = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_email', '');
		$api_key = (string) get_option(CW_PROFIT_OPTION_PREFIX . 'cloudways_api_key', '');
		if ($email === '' || $api_key === '') {
			throw new RuntimeException('Missing Cloudways credentials. Set Cloudways Email and API Key in plugin settings.');
		}

		$response = wp_remote_post(
			$this->base_url . '/oauth/access_token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body' => array(
					'email' => $email,
					'api_key' => $api_key,
					'grant_type' => 'password',
				),
			)
		);

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException('Cloudways auth failed (' . $code . '): ' . substr((string) $body, 0, 400));
		}

		$data = json_decode((string) $body, true);
		if (!is_array($data) || empty($data['access_token'])) {
			throw new RuntimeException('Cloudways auth returned unexpected response.');
		}

		$token = (string) $data['access_token'];
		$expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
		$expires_at = time() + max(60, $expires_in);

		set_transient(CW_PROFIT_OPTION_PREFIX . 'access_token', $token, $expires_in);
		set_transient(CW_PROFIT_OPTION_PREFIX . 'access_token_expires_at', (string) $expires_at, $expires_in);

		return $token;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function request(string $method, string $path, array $query = []): array {
		$token = $this->get_access_token();

		$url = $this->base_url . $path;
		if (!empty($query)) {
			$url = add_query_arg($query, $url);
		}

		$args = array(
			'method' => $method,
			'timeout' => 30,
			'headers' => array(
				'Accept' => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
		);

		$response = wp_remote_request($url, $args);
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException('Cloudways request failed (' . $code . '): ' . substr($body, 0, 400));
		}

		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new RuntimeException('Cloudways response was not valid JSON.');
		}
		return $data;
	}

	/**
	 * TODO: Confirm exact endpoint/shape in YAML during implementation.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_servers(): array {
		$data = $this->request('GET', '/server');
		if (isset($data['servers']) && is_array($data['servers'])) {
			return $data['servers'];
		}
		// Fallback: some APIs return list directly.
		if (isset($data[0]) && is_array($data[0])) {
			return $data;
		}
		return [];
	}

	/**
	 * Fallback for environments where server list does not embed `apps`.
	 *
	 * Note: In the YAML example response for `GET /server`, each server object already includes an `apps` array.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_server_apps(string $server_id): array {
		$data = $this->request('GET', '/server/' . rawurlencode($server_id));
		if (isset($data['apps']) && is_array($data['apps'])) {
			return $data['apps'];
		}
		if (isset($data['applications']) && is_array($data['applications'])) {
			return $data['applications'];
		}
		return array();
	}
}

