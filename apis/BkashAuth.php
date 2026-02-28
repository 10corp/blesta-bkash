<?php
/**
 * bKash Authentication and HTTP Transport
 *
 * Manages token lifecycle with session-based caching.
 * Handles all HTTP communication with bKash API.
 *
 * @version 1.0.7
 */
class BkashAuth
{
	/**
	 * @var string
	 */
	private $appKey;

	/**
	 * @var string
	 */
	private $appSecret;

	/**
	 * @var string
	 */
	private $username;

	/**
	 * @var string
	 */
	private $password;

	/**
	 * @var string Full versioned base URL
	 */
	private $baseUrl;

	/**
	 * @param string $appKey
	 * @param string $appSecret
	 * @param string $username
	 * @param string $password
	 * @param bool $sandbox
	 */
	public function __construct($appKey, $appSecret, $username, $password, $sandbox = true)
	{
		$this->appKey = (string)$appKey;
		$this->appSecret = (string)$appSecret;
		$this->username = (string)$username;
		$this->password = (string)$password;
		$this->baseUrl = $sandbox
			? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
			: 'https://tokenized.pay.bka.sh/v1.2.0-beta';
	}

	/**
	 * Returns the app key.
	 *
	 * @return string
	 */
	public function getAppKey()
	{
		return $this->appKey;
	}

	/**
	 * Returns the full versioned base URL.
	 *
	 * Bug 6 fix: Returns complete URL with version included.
	 *
	 * @return string
	 */
	public function getBaseUrl()
	{
		return $this->baseUrl;
	}

	/**
	 * Returns the session cache key for token storage.
	 *
	 * @return string
	 */
	private function sessionKey()
	{
		return 'bkash_token_' . md5($this->appKey . $this->username);
	}

	/**
	 * Returns cached token if valid, otherwise fetches new token.
	 *
	 * Fix 3: Token stored in $_SESSION instead of file cache.
	 *
	 * @return string|null
	 */
	public function getToken()
	{
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}

		$cacheKey = $this->sessionKey();
		$cached = $_SESSION[$cacheKey] ?? null;

		if (!empty($cached['id_token']) && time() < (int)($cached['expires_at'] ?? 0)) {
			return $cached['id_token'];
		}

		return $this->refreshToken();
	}

	/**
	 * Fetches fresh token from bKash grant API.
	 *
	 * Fix 3: Token stored in $_SESSION.
	 * Fix 4: Safe logging — no credentials in error_log.
	 *
	 * @return string|null
	 */
	private function refreshToken()
	{
		$response = $this->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/token/grant',
			[
				'app_key' => $this->appKey,
				'app_secret' => $this->appSecret
			],
			[
				'Content-Type: application/json',
				'Accept: application/json',
				'username: ' . $this->username,
				'password: ' . $this->password
			]
		);

		if (!empty($response['id_token'])) {
			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}

			$cacheKey = $this->sessionKey();
			$_SESSION[$cacheKey] = [
				'id_token'      => $response['id_token'],
				'refresh_token' => $response['refresh_token'] ?? '',
				'expires_at'    => time() + (int)($response['expires_in'] ?? 3600) - 60,
			];

			return $response['id_token'];
		}

		// Fix 4: Safe logging — no credentials exposed
		$safeLog = [
			'statusCode'    => $response['statusCode']    ?? 'UNKNOWN',
			'statusMessage' => $response['statusMessage'] ?? 'No message',
		];
		error_log('[bKash] Token refresh failed: ' . json_encode($safeLog));
		return null;
	}

	/**
	 * Executes a POST request with JSON body.
	 *
	 * @param string $url
	 * @param array $body
	 * @param array $headers
	 * @return array
	 */
	public function curlPostJson($url, array $body, array $headers = [])
	{
		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($body),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true
		]);

		$raw = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($raw === false || !empty($curlError)) {
			error_log('[bKash] cURL error: ' . $curlError);
			return ['error' => 'curl_error', 'message' => $curlError];
		}

		$decoded = json_decode($raw, true);

		if (!is_array($decoded)) {
			error_log('[bKash] JSON decode error. HTTP ' . $httpCode . ': ' . substr($raw, 0, 200));
			return [
				'error' => 'json_decode_error',
				'http_code' => $httpCode,
				'raw' => substr($raw, 0, 200)
			];
		}

		return $decoded;
	}
}