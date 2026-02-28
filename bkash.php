<?php
/**
 * bKash Tokenized Checkout Gateway for Blesta
 *
 * Integrates bKash Tokenized Checkout v1.2.0-beta.
 * Supports one-time payments and Standing Instructions (recurring).
 *
 * Follows Blesta Non-Merchant Gateway standards:
 * @link https://docs.blesta.com/developers/gateways/non-merchant-gateways
 *
 * Integrates bKash Tokenized Checkout API:
 * @link https://developer.bka.sh/reference/createpaymentusingpost
 *
 * References official Blesta gateways for best practices:
 * @link https://github.com/blesta/gateway-duitku
 * @link https://github.com/blesta/gateway-paysera
 *
 * @version 1.0.7
 * @author  Mahmudul Hasan Tuhin
 */
class Bkash extends NonMerchantGateway
{
	/**
	 * @var array Gateway meta data (credentials)
	 */
	private $meta;

	/**
	 * @var array Tracks processed transaction IDs for replay protection
	 */
	private static $processedTrxIds = [];

	/**
	 * Initializes the gateway and loads required resources.
	 */
	public function __construct()
	{
		Loader::loadComponents($this, ['Input']);

		Language::loadLang(
			'bkash',
			null,
			dirname(__FILE__) . DS . 'language' . DS
		);
	}

	/**
	 * Returns the name of the gateway.
	 *
	 * @return string
	 */
	public function getName()
	{
		return Language::_('Bkash.name', true);
	}

	/**
	 * Returns gateway version.
	 *
	 * @return string
	 */
	public function getVersion()
	{
		return '1.0.7';
	}

	/**
	 * Returns gateway author information.
	 *
	 * @return array
	 */
	public function getAuthors()
	{
		return [
			['name' => 'Mahmudul Hasan Tuhin', 'url' => 'https://github.com/dwindaadis/blesta']
		];
	}

	/**
	 * Returns currencies supported by this gateway.
	 *
	 * @return array
	 */
	public function getCurrencies()
	{
		return ['BDT'];
	}

	/**
	 * Sets the currency to use for payment processing.
	 *
	 * @param string $currency The ISO 4217 currency code
	 */
	public function setCurrency($currency)
	{
		$this->currency = $currency;
	}

	/**
	 * Returns the path to the gateway logo.
	 *
	 * Fix 9: Correct logo path for Blesta gateway UI.
	 *
	 * @return string
	 */
	public function getLogo()
	{
		return 'views' . DS . 'default' . DS . 'images' . DS . 'logo.png';
	}

	/**
	 * Sets gateway meta data (credentials) from the database.
	 *
	 * @param array $meta
	 */
	public function setMeta(array $meta = null)
	{
		$this->meta = $meta;
	}

	/**
	 * Returns the HTML form fields for admin settings page.
	 *
	 * @param array $meta Existing saved meta values
	 * @return string Rendered view
	 */
	public function getSettings(array $meta = null)
	{
		$this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__)) . DS);
		Loader::loadHelpers($this, ['Html', 'Form']);

		$this->view->set('meta', $meta);

		return $this->view->fetch();
	}

	/**
	 * Validates and saves admin settings.
	 *
	 * @param array $meta Submitted form values
	 * @return array Validated meta array
	 */
	public function editSettings(array $meta)
	{
		$rules = [
			'app_key' => [
				'empty' => [
					'rule' => 'isEmpty',
					'negate' => true,
					'message' => Language::_('Bkash.!error.app_key.empty', true)
				]
			],
			'app_secret' => [
				'empty' => [
					'rule' => 'isEmpty',
					'negate' => true,
					'message' => Language::_('Bkash.!error.app_secret.empty', true)
				]
			],
			'username' => [
				'empty' => [
					'rule' => 'isEmpty',
					'negate' => true,
					'message' => Language::_('Bkash.!error.username.empty', true)
				]
			],
			'password' => [
				'empty' => [
					'rule' => 'isEmpty',
					'negate' => true,
					'message' => Language::_('Bkash.!error.password.empty', true)
				]
			]
		];

		$this->Input->setRules($rules);
		$this->Input->validates($meta);

		return $meta;
	}

	/**
	 * Returns a list of allowed payment gateways key/value pairs.
	 *
	 * @return array
	 */
	public function encryptableFields()
	{
		return ['app_key', 'app_secret', 'username', 'password'];
	}

	/**
	 * Builds the payment redirect page.
	 *
	 * Bug 1 fix: client_id sourced from $contact_info, not $options.
	 * Bug 7 fix: payerReference passed for Standing Instructions.
	 *
	 * @param array $contact_info  Client contact information
	 * @param float $amount        Invoice total in gateway currency
	 * @param array $invoice_amounts  Invoice amount breakdown
	 * @param array $options       Additional options (return_url, etc.)
	 * @return mixed View string or null on error
	 */
	public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
	{
		// Bug 1 fix: client_id comes from $contact_info, not $options
		$clientId = $contact_info['client_id'] ?? null;

		if (empty($clientId)) {
			$this->Input->setErrors([
				'client_id' => ['required' => Language::_('Bkash.!error.client_id.required', true)]
			]);
			return null;
		}

		$invoiceNumber = uniqid('INV-' . $clientId . '-', true);

		// Fix 7: Store invoice amounts and expected total in session for validate()
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		$_SESSION['bkash_invoices_' . $clientId] = $invoice_amounts ?? [];
		$_SESSION['bkash_expected_amount_' . $clientId] = number_format((float)$amount, 2, '.', '');

		// Fix 7: Build callbackURL with client_id and invoice IDs
		$invoiceIds = [];
		if (!empty($invoice_amounts)) {
			foreach ($invoice_amounts as $inv) {
				if (isset($inv['invoice_id'])) {
					$invoiceIds[] = $inv['invoice_id'];
				}
			}
		}
		$returnUrl = $options['return_url'] ?? '';
		$separator = (strpos($returnUrl, '?') !== false) ? '&' : '?';
		$callbackUrl = $returnUrl . $separator
			. 'client_id=' . urlencode($clientId)
			. '&invoices=' . urlencode(implode(',', $invoiceIds));

		$auth = $this->getAuth();
		$payment = new BkashPayment($auth);

		// Check for existing agreement for recurring
		$agreementId = $this->getClientSetting($clientId, 'agreement_id');

		// Bug 7 fix: pass clientId as payerReference for Standing Instructions
		$response = $payment->createPayment(
			$amount,
			$invoiceNumber,
			$callbackUrl,
			$agreementId ?: null,
			(string)$clientId
		);

		if (empty($response['bkashURL'])) {
			// Bug 2 fix: set proper error message instead of silent return
			$errorMsg = $response['statusMessage'] ?? Language::_('Bkash.!error.payment_failed', true);
			$this->Input->setErrors(['payment' => ['failed' => $errorMsg]]);
			return null;
		}

		$this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__)) . DS);
		Loader::loadHelpers($this, ['Html', 'Form']);

		$this->view->set('bkash_url', $response['bkashURL']);
		$this->view->set('amount', $amount);
		$this->view->set('currency', 'BDT');

		return $this->view->fetch();
	}

	/**
	 * Validates the callback from bKash after payment.
	 *
	 * Fix 2: transactionStatus === 'Completed' verified.
	 * Fix 5: Replay protection via static $processedTrxIds.
	 * Fix 6: parent_transaction_id added to return array.
	 * Fix 7: Invoices retrieved from session.
	 *
	 * @param array $get  GET parameters from callback URL
	 * @param array $post POST parameters from callback
	 * @return array Transaction result
	 */
	public function validate(array $get, array $post)
	{
		$paymentId = $get['paymentID'] ?? null;
		$status = $get['status'] ?? null;
		$clientId = $get['client_id'] ?? null;

		// Log incoming callback data
		$this->log(
			$this->ifSet($_SERVER['REQUEST_URI']),
			serialize($get),
			'input',
			true
		);

		// Edge Case 4: Handle cancel/failure status from bKash redirect
		if ($status !== 'success' || empty($paymentId)) {
			// Provide specific message for user cancellation vs generic failure
			$errorKey = ($status === 'cancel')
				? 'Bkash.!error.payment_cancelled'
				: 'Bkash.!error.payment_failed';
			$this->Input->setErrors([
				'payment' => ['failed' => Language::_($errorKey, true)]
			]);

			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => null,
				'reference_id' => null,
				'parent_transaction_id' => $paymentId,
			];
		}

		$auth = $this->getAuth();
		$payment = new BkashPayment($auth);
		$response = $payment->executePayment($paymentId);

		// Edge Case 3: If execute fails with network/curl error, fall back to queryPayment
		if (!empty($response['error']) && in_array($response['error'], ['curl_error', 'json_decode_error'], true)) {
			error_log('[bKash] executePayment failed with ' . $response['error'] . ', falling back to queryPayment');
			$response = $payment->queryPayment($paymentId);
		}

		// Log bKash API response
		$this->log(
			$this->ifSet($_SERVER['REQUEST_URI']),
			serialize($response),
			'output',
			(($response['statusCode'] ?? '') === '0000')
		);

		// Fix 2: Check both statusCode AND transactionStatus
		$approved = (
			($response['statusCode'] ?? '') === '0000'
			&& ($response['transactionStatus'] ?? '') === 'Completed'
			&& !empty($response['trxID'])
		);

		if (!$approved) {
			$errorMsg = $response['statusMessage'] ?? Language::_('Bkash.!error.invalid_response', true);
			$this->Input->setErrors(['payment' => ['failed' => $errorMsg]]);

			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => $paymentId,
				'reference_id' => null,
				'parent_transaction_id' => $paymentId,
			];
		}

		// Fix 5: Replay protection — reject duplicate trxID
		$trxId = $response['trxID'];
		if (in_array($trxId, self::$processedTrxIds, true)) {
			$this->Input->setErrors([
				'payment' => ['duplicate' => Language::_('Bkash.!error.payment_failed', true)]
			]);

			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => $trxId,
				'reference_id' => $paymentId,
				'parent_transaction_id' => $paymentId,
			];
		}
		self::$processedTrxIds[] = $trxId;

		// Fix 7: Retrieve invoices from session
		$responseClientId = $response['payerReference'] ?? $clientId;
		$invoices = [];
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		$sessionKey = 'bkash_invoices_' . $responseClientId;
		if (!empty($_SESSION[$sessionKey])) {
			$invoices = $_SESSION[$sessionKey];
			unset($_SESSION[$sessionKey]);
		}

		// Edge Case 5: Amount mismatch protection
		$amountSessionKey = 'bkash_expected_amount_' . $responseClientId;
		$expectedAmount = $_SESSION[$amountSessionKey] ?? null;
		unset($_SESSION[$amountSessionKey]);

		if ($expectedAmount !== null) {
			$receivedAmount = number_format((float)($response['amount'] ?? 0), 2, '.', '');
			$diff = abs((float)$expectedAmount - (float)$receivedAmount);

			if ($diff > 1.00) {
				error_log('[bKash] Amount mismatch: expected=' . $expectedAmount . ' received=' . $receivedAmount);
				$this->Input->setErrors([
					'payment' => ['amount' => Language::_('Bkash.!error.amount_mismatch', true)]
				]);

				return [
					'client_id' => $responseClientId,
					'amount' => $receivedAmount,
					'currency' => 'BDT',
					'invoices' => $invoices,
					'status' => 'declined',
					'transaction_id' => $trxId,
					'reference_id' => $paymentId,
					'parent_transaction_id' => $paymentId,
				];
			}
		}

		// Save agreement ID if returned (recurring/Standing Instructions)
		if (!empty($response['agreementID']) && !empty($response['payerReference'])) {
			$this->saveClientSetting($response['payerReference'], 'agreement_id', $response['agreementID']);
			$this->saveClientSetting($response['payerReference'], 'msisdn', $response['customerMsisdn'] ?? '');
		}

		return [
			'client_id' => $responseClientId,
			'amount' => $response['amount'] ?? null,
			'currency' => $response['currency'] ?? 'BDT',
			'invoices' => $invoices,
			'status' => 'approved',
			'transaction_id' => $trxId,
			'reference_id' => $paymentId,
			'parent_transaction_id' => $paymentId,
		];
	}

	/**
	 * Handles the return from bKash after payment completion.
	 *
	 * Fix 5: Verifies payment status via queryPayment and checks for replay attacks.
	 *
	 * @param array $get  GET parameters from return URL
	 * @param array $post POST parameters
	 * @return array Transaction result
	 */
	public function success(array $get, array $post)
	{
		$paymentId = $get['paymentID'] ?? null;
		$clientId = $get['client_id'] ?? null;

		// Log return callback data
		$this->log(
			$this->ifSet($_SERVER['REQUEST_URI']),
			serialize($get),
			'input',
			true
		);

		if (empty($paymentId)) {
			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => null,
				'reference_id' => null,
				'parent_transaction_id' => null,
			];
		}

		// Fix 5: Query payment status to verify
		$auth = $this->getAuth();
		$payment = new BkashPayment($auth);
		$response = $payment->queryPayment($paymentId);

		// Log bKash query response
		$this->log(
			$this->ifSet($_SERVER['REQUEST_URI']),
			serialize($response),
			'output',
			(($response['statusCode'] ?? '') === '0000')
		);

		// Fix 2 & 5: Check both statusCode AND transactionStatus
		$approved = (
			($response['statusCode'] ?? '') === '0000'
			&& ($response['transactionStatus'] ?? '') === 'Completed'
			&& !empty($response['trxID'])
		);

		if (!$approved) {
			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => $paymentId,
				'reference_id' => null,
				'parent_transaction_id' => $paymentId,
			];
		}

		// Fix 5: Replay protection
		$trxId = $response['trxID'];
		if (in_array($trxId, self::$processedTrxIds, true)) {
			return [
				'client_id' => $clientId,
				'amount' => null,
				'currency' => 'BDT',
				'invoices' => [],
				'status' => 'declined',
				'transaction_id' => $trxId,
				'reference_id' => $paymentId,
				'parent_transaction_id' => $paymentId,
			];
		}
		self::$processedTrxIds[] = $trxId;

		$responseClientId = $response['payerReference'] ?? $clientId;

		// Retrieve invoices from session
		$invoices = [];
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}
		$sessionKey = 'bkash_invoices_' . $responseClientId;
		if (!empty($_SESSION[$sessionKey])) {
			$invoices = $_SESSION[$sessionKey];
			unset($_SESSION[$sessionKey]);
		}

		// Edge Case 5: Amount mismatch protection in success()
		$amountSessionKey = 'bkash_expected_amount_' . $responseClientId;
		$expectedAmount = $_SESSION[$amountSessionKey] ?? null;
		unset($_SESSION[$amountSessionKey]);

		if ($expectedAmount !== null) {
			$receivedAmount = number_format((float)($response['amount'] ?? 0), 2, '.', '');
			$diff = abs((float)$expectedAmount - (float)$receivedAmount);

			if ($diff > 1.00) {
				error_log('[bKash] success() Amount mismatch: expected=' . $expectedAmount . ' received=' . $receivedAmount);
				return [
					'client_id' => $responseClientId,
					'amount' => $receivedAmount,
					'currency' => 'BDT',
					'invoices' => $invoices,
					'status' => 'declined',
					'transaction_id' => $trxId,
					'reference_id' => $paymentId,
					'parent_transaction_id' => $paymentId,
				];
			}
		}

		return [
			'client_id' => $responseClientId,
			'amount' => $response['amount'] ?? null,
			'currency' => $response['currency'] ?? 'BDT',
			'invoices' => $invoices,
			'status' => 'approved',
			'transaction_id' => $trxId,
			'reference_id' => $paymentId,
			'parent_transaction_id' => $paymentId,
		];
	}

	/**
	 * Returns a BkashAuth instance built from saved meta credentials.
	 *
	 * @return BkashAuth
	 */
	private function getAuth()
	{
		$apiDir = dirname(__FILE__) . DS . 'apis' . DS;
		Loader::load($apiDir . 'BkashAuth.php');
		Loader::load($apiDir . 'BkashPayment.php');

		$sandbox = ($this->meta['sandbox'] ?? 'true') === 'true';

		return new BkashAuth(
			$this->meta['app_key'] ?? '',
			$this->meta['app_secret'] ?? '',
			$this->meta['username'] ?? '',
			$this->meta['password'] ?? '',
			$sandbox
		);
	}

	/**
	 * Retrieves a stored client setting value.
	 *
	 * @param int $clientId
	 * @param string $key
	 * @return string|null
	 */
	private function getClientSetting($clientId, $key)
	{
		if (!isset($this->Clients)) {
			Loader::loadModels($this, ['Clients']);
		}

		$setting = $this->Clients->getSetting($clientId, 'bkash_' . $key);

		return $setting ? $setting->value : null;
	}

	/**
	 * Saves a client setting value.
	 *
	 * @param int $clientId
	 * @param string $key
	 * @param string $value
	 */
	private function saveClientSetting($clientId, $key, $value)
	{
		if (!isset($this->Clients)) {
			Loader::loadModels($this, ['Clients']);
		}

		$this->Clients->setSetting($clientId, 'bkash_' . $key, $value);
	}
}