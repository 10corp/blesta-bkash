<?php
declare(strict_types=1);

/**
 * bKash Payment Operations
 *
 * Handles payment creation, execution, and recurring charges.
 * Depends on BkashAuth for token and HTTP transport.
 *
 * @version 1.2.0
 */
class BkashPayment
{
	private readonly string $baseUrl;

	public function __construct(
		private readonly BkashAuth $auth,
	) {
		$this->baseUrl = $auth->getBaseUrl();
	}

	/**
	 * Builds Authorization headers for bKash API requests.
	 *
	 * @param string $token id_token from BkashAuth
	 * @return array
	 */
	private function buildHeaders(string $token): array
	{
		return [
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $token,
			'X-APP-Key: ' . $this->auth->getAppKey()
		];
	}

	/**
	 * Creates a new tokenized payment session with bKash.
	 *
	 * Bug 7 fix: $payerReference (clientId) passed to enable Standing Instructions.
	 *
	 * @param float $amount
	 * @param string $invoiceNumber
	 * @param string $callbackUrl
	 * @param string|null $agreementId Existing agreement ID for recurring
	 * @param string|null $payerReference Client identifier for Standing Instruction
	 * @return array
	 */
	public function createPayment(
		float $amount,
		string $invoiceNumber,
		string $callbackUrl,
		?string $agreementId = null,
		?string $payerReference = null,
	): array {
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] createPayment: token_missing');
			return [
				'statusCode' => 'TOKEN_ERROR',
				'statusMessage' => 'Failed to obtain bKash authorization token',
			];
		}

		$body = [
			'mode' => '0011',
			'amount' => number_format((float)$amount, 2, '.', ''),
			'currency' => 'BDT',
			'intent' => 'sale',
			'merchantInvoiceNumber' => (string)$invoiceNumber,
			'callbackURL' => (string)$callbackUrl
		];

		if (!empty($payerReference)) {
			$body['payerReference'] = (string)$payerReference;
		}

		if (!empty($agreementId)) {
			$body['agreementID'] = (string)$agreementId;
		}

		$response = $this->auth->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/create',
			$body,
			$this->buildHeaders($token)
		);

		if (!empty($response['error'])) {
			error_log('[bKash] createPayment API error: ' . json_encode($response));
		}

		return $response;
	}

	/**
	 * Executes a payment after customer authorization.
	 *
	 * @param string $paymentId
	 * @return array
	 */
	public function executePayment(string $paymentId): array
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] executePayment: token_missing');
			return [
				'statusCode' => 'TOKEN_ERROR',
				'statusMessage' => 'Failed to obtain bKash authorization token',
			];
		}

		$response = $this->auth->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/execute',
			['paymentID' => (string)$paymentId],
			$this->buildHeaders($token)
		);

		if (!empty($response['error'])) {
			error_log('[bKash] executePayment API error: ' . json_encode($response));
		}

		return $response;
	}

	/**
	 * Queries payment status from bKash for verification.
	 *
	 * @param string $paymentId
	 * @return array
	 */
	public function queryPayment(string $paymentId): array
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] queryPayment: token_missing');
			return [
				'statusCode' => 'TOKEN_ERROR',
				'statusMessage' => 'Failed to obtain bKash authorization token',
			];
		}

		$response = $this->auth->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/payment/status',
			['paymentID' => (string)$paymentId],
			$this->buildHeaders($token)
		);

		if (!empty($response['error'])) {
			error_log('[bKash] queryPayment API error: ' . json_encode($response));
		}

		return $response;
	}

	/**
	 * Refunds a completed transaction.
	 *
	 * @param string $paymentId Original payment ID
	 * @param string $trxId Original transaction ID
	 * @param float $amount Amount to refund
	 * @param string|null $reason Refund reason
	 * @return array
	 */
	public function refundTransaction(string $paymentId, string $trxId, float $amount, ?string $reason = null): array
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] refundTransaction: token_missing');
			return [
				'statusCode' => 'TOKEN_ERROR',
				'statusMessage' => 'Failed to obtain bKash authorization token',
			];
		}

		$body = [
			'paymentID' => (string)$paymentId,
			'trxID' => (string)$trxId,
			'amount' => number_format((float)$amount, 2, '.', ''),
			'sku' => 'refund',
			'reason' => $reason ?? 'Customer requested refund'
		];

		$response = $this->auth->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/payment/refund',
			$body,
			$this->buildHeaders($token)
		);

		if (!empty($response['error'])) {
			error_log('[bKash] refundTransaction API error: ' . json_encode($response));
		}

		return $response;
	}

	/**
	 * Charges a client using an existing Standing Instruction agreement.
	 *
	 * @param string $agreementId
	 * @param float $amount
	 * @param string $invoiceNumber
	 * @param string|null $callbackUrl
	 * @return array
	 */
	public function recurringCharge(string $agreementId, float $amount, string $invoiceNumber, ?string $callbackUrl = null): array
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] recurringCharge: token_missing');
			return [
				'statusCode' => 'TOKEN_ERROR',
				'statusMessage' => 'Failed to obtain bKash authorization token',
			];
		}

		$body = [
			'mode' => '0001',
			'agreementID' => (string)$agreementId,
			'amount' => number_format((float)$amount, 2, '.', ''),
			'currency' => 'BDT',
			'intent' => 'sale',
			'merchantInvoiceNumber' => (string)$invoiceNumber
		];

		if (!empty($callbackUrl)) {
			$body['callbackURL'] = (string)$callbackUrl;
		}

		$createResponse = $this->auth->curlPostJson(
			$this->baseUrl . '/tokenized/checkout/create',
			$body,
			$this->buildHeaders($token)
		);

		if (!empty($createResponse['error'])) {
			error_log('[bKash] recurringCharge create error: ' . json_encode($createResponse));
			return $createResponse;
		}

		if (!empty($createResponse['paymentID'])) {
			return $this->executePayment($createResponse['paymentID']);
		}

		if (($createResponse['statusCode'] ?? '0000') !== '0000') {
			error_log('[bKash] recurringCharge API error: ' . json_encode($createResponse));
		}

		return $createResponse;
	}
}