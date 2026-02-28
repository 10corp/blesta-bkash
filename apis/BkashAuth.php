<?php
declare(strict_types=1);

/**
 * bKash Authentication and HTTP Transport
 *
 * Manages token lifecycle with session-based caching.
 * Handles all HTTP communication with bKash API.
 *
 * @version 1.2.0
 */
class BkashAuth
{
	private readonly string $baseUrl;

	public function __construct(
		private readonly string $appKey,
		private readonly string $appSecret,
		private readonly string $username,
		private readonly string $password,
		bool $sandbox = true,
	) {
		$this->baseUrl = $sandbox
			? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
			: 'https://tokenized.pay.bka.sh/v1.2.0-beta';
	}

	/**
	 * Returns the app key.
	 *
	 * @return string
	 */
	public function getAppKey(): string
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
	public function getBaseUrl(): string
	{
		return $this->baseUrl;
	}

	/**
	 * Returns the session cache key for this merchant's token.
	 *
	 * @return string
	 */
	private function sessionCacheKey(): string
	{
		return 'bkash_token_' . md5($this->appKey . $this->username);
	}

	/**
	 * Ensures PHP session is started for token caching.
	 */
	private function ensureSession(): void
	{
		if (session_status() === PHP_SESSION_NONE) {
			@session_start();
		}
	}

	/**
	 * Returns cached token if valid, otherwise fetches new token.
	 *
	 * @return string|null
	 */
	public function getToken(): ?string
	{
		$this->ensureSession();
		$cacheKey = $this->sessionCacheKey();

		if (
			!empty($_SESSION[$cacheKey]['id_token'])
			&& time() < (int)($_SESSION[$cacheKey]['expires_at'] ?? 0)
		) {
			return $_SESSION[$cacheKey]['id_token'];
		}

		return $this->refreshToken();
	}

	/**
	 * Fetches fresh token from bKash grant API.
	 *
	 * @return string|null
	 */
	private function refreshToken(): ?string
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
			$this->ensureSession();
			$cacheKey = $this->sessionCacheKey();

			$_SESSION[$cacheKey] = [
				'id_token' => $response['id_token'],
				'refresh_token' => $response['refresh_token'] ?? '',
				'expires_at' => time() + (int)($response['expires_in'] ?? 3600) - 60,
			];

			return $response['id_token'];
		}

		// Fix 4: Mask sensitive data in error logs
		$safeLog = [
			'statusCode' => $response['statusCode'] ?? 'UNKNOWN',
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
	public function curlPostJson(string $url, array $body, array $headers = []): array
	{
		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($body),
			CURLOPT_HTTPHEADER => $headers,
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