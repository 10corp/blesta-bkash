<?php
/**
 * bKash Payment Operations
 *
 * Handles payment creation, execution, and recurring charges.
 * Depends on BkashAuth for token and HTTP transport.
 *
 * @version 1.0.7
 */
class BkashPayment
{
	/**
	 * @var BkashAuth
	 */
	private $auth;

	/**
	 * @var string Full versioned base URL from BkashAuth::getBaseUrl()
	 */
	private $baseUrl;

	/**
	 * @param BkashAuth $auth
	 */
	public function __construct(BkashAuth $auth)
	{
		$this->auth = $auth;
		$this->baseUrl = $auth->getBaseUrl();
	}

	/**
	 * Builds Authorization headers for bKash API requests.
	 *
	 * @param string $token id_token from BkashAuth
	 * @return array
	 */
	private function buildHeaders($token)
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
		$amount,
		$invoiceNumber,
		$callbackUrl,
		$agreementId = null,
		$payerReference = null
	) {
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] createPayment: token_missing');
			return [
				'statusCode'    => 'TOKEN_ERROR',
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
	public function executePayment($paymentId)
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] executePayment: token_missing');
			return [
				'statusCode'    => 'TOKEN_ERROR',
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
	 * Queries the status of a payment by paymentID.
	 *
	 * Used as a fallback when execute fails, and in success() for verification.
	 *
	 * @param string $paymentId
	 * @return array
	 */
	public function queryPayment($paymentId)
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] queryPayment: token_missing');
			return [
				'statusCode'    => 'TOKEN_ERROR',
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
	 * Charges a client using an existing Standing Instruction agreement.
	 *
	 * @param string $agreementId
	 * @param float $amount
	 * @param string $invoiceNumber
	 * @param string|null $callbackUrl
	 * @return array
	 */
	public function recurringCharge($agreementId, $amount, $invoiceNumber, $callbackUrl = null)
	{
		$token = $this->auth->getToken();

		if (empty($token)) {
			error_log('[bKash] recurringCharge: token_missing');
			return [
				'statusCode'    => 'TOKEN_ERROR',
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