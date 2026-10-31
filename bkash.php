<?php
/**
 * bKash Tokenized Checkout Non-Merchant Gateway for Blesta.
 *
 * --------------------------------------------------------------------------
 * ARCHITECTURE & BUSINESS FLOW OVERVIEW FOR DEVELOPERS:
 * --------------------------------------------------------------------------
 * This gateway implements the bKash Tokenized Checkout API (v1.2.0-beta)
 * using the "Without Agreement" mode (mode=0011).
 *
 * In Blesta's architecture, there are two primary gateway types:
 *  1. Merchant Gateways:
 *     Credit card / direct payment forms rendered directly inside Blesta.
 *     The merchant's server handles card numbers (requires high PCI-DSS compliance).
 *  2. Non-Merchant Gateways (This Module):
 *     Redirect/offsite checkout. When paying an invoice, the customer is securely
 *     redirected to bKash's hosted portal to enter their PIN and OTP.
 *     Sensitive financial credentials NEVER touch the Blesta server.
 *
 * Complete Payment Lifecycle:
 *  1. Setup & Config:
 *     Admin configures App Key, App Secret, Username, Password, and Sandbox mode.
 *     Sensitive fields are encrypted at rest using Blesta's built-in AES encryption.
 *  2. Initiation (`buildProcess()`):
 *     Customer selects bKash on invoice payment screen.
 *     Module validates BDT currency, computes exact integer paisa (preventing float bugs),
 *     stores an audit record in `bkash_payment_attempts`, obtains a bearer token from bKash,
 *     calls bKash `/create`, and generates a secure hosted redirect button.
 *  3. Customer Interaction (Hosted bKash Checkout):
 *     Customer enters wallet number, confirms 6-digit SMS OTP, and enters 5-digit PIN.
 *  4. Callback & Server Validation (`validate()`):
 *     bKash redirects customer back to Blesta callback URL with HMAC signature & paymentID.
 *     Module verifies HMAC signature, claims atomic lock, calls bKash `/execute`
 *     (with `/payment/status` fallback), checks for duplicate trxIDs, and credits the invoice.
 *  5. Client Return (`success()`):
 *     Blesta renders payment receipt or error message to the customer.
 *  6. Refund Processing (`refund()`):
 *     Admin can trigger direct API refunds from Blesta via `/payment/refund`.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.bkash
 * @link https://developer.bka.sh/docs/checkout-url-process-overview bKash API Documentation
 */
class Bkash extends NonmerchantGateway
{
    /**
     * @var string Base endpoint URL for the bKash Sandbox (testing/staging) environment.
     */
    private const SANDBOX_BASE_URL =
        'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string Base endpoint URL for the bKash Live (production) environment.
     */
    private const LIVE_BASE_URL =
        'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string Name of the custom MySQL audit table used to track payment attempts and state transitions.
     */
    private const ATTEMPT_TABLE = 'bkash_payment_attempts';

    /**
     * @var int Lifespan of a payment attempt in seconds (1800s = 30 minutes).
     * Matches bKash's hosted checkout session window; stale attempts cannot be claimed.
     */
    private const ATTEMPT_TTL_SECONDS = 1800;

    /**
     * @var int Default token cache duration in seconds (3000s = 50 minutes).
     * bKash id_tokens normally last 3600s; we cache for 3000s to provide a 10-minute safety buffer.
     */
    private const TOKEN_TTL_SECONDS = 3000;

    /**
     * @var int Minimum wait time in seconds (300s = 5 minutes) before unlocking an attempt stuck in "executing".
     * Prevents simultaneous concurrent execution while allowing automated recovery if a server crashes mid-flight.
     */
    private const CALLBACK_RETRY_DELAY_SECONDS = 300;

    /**
     * @var array Gateway configuration metadata (API credentials, sandbox toggle, etc.) loaded from Blesta.
     */
    private array $meta = [];

    /**
     * @var string Active currency code (strictly BDT).
     */
    protected string $currency = 'BDT';

    /**
     * Gateway constructor.
     *
     * Initializes the gateway by:
     *  1. Enforcing strict runtime platform requirements: PHP >= 8.4 and Blesta >= 6.0.
     *  2. Loading metadata configuration from config.json (logos, supported currencies, etc.).
     *  3. Loading Blesta's Input component for form validation and error handling.
     *  4. Loading localized text strings from language/en_us/bkash.php based on user locale.
     *
     * @throws RuntimeException If running on PHP < 8.4 or Blesta < 6.0
     */
    public function __construct()
    {
        // ── Step 1: Enforce PHP 8.4+ Runtime Requirement ───────────────────────
        if (PHP_VERSION_ID < 80400) {
            throw new RuntimeException('The bKash gateway strictly requires PHP 8.4 or newer.');
        }

        // ── Step 2: Enforce Blesta 6.0+ Host Application Requirement ───────────
        $blesta_version = defined('BLESTA_VERSION') ? BLESTA_VERSION : null;
        if ($blesta_version === null) {
            Loader::loadModels($this, ['Settings']);
            if (isset($this->Settings)) {
                $blesta_version = $this->Settings->getDbVersion();
            }
        }
        if ($blesta_version && version_compare($blesta_version, '6.0.0', '<')) {
            throw new RuntimeException('The bKash gateway strictly requires Blesta 6.0 or newer.');
        }

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('bkash', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Installs the gateway for the company.
     *
     * Automatically executed by Blesta when the administrator clicks "Install" in Settings > Gateways.
     * Provisions the dedicated audit table `bkash_payment_attempts` if it does not already exist.
     */
    public function install()
    {
        $this->createAttemptTable();
    }

    /**
     * Upgrades the gateway from a previous version.
     *
     * Executed by Blesta when an upgraded version of the module files is uploaded.
     * Ensures any database schema changes are idempotently applied without data loss.
     *
     * @param string $current_version The version currently installed in the database
     */
    public function upgrade($current_version)
    {
        $this->createAttemptTable();
    }

    /**
     * Uninstalls the gateway.
     *
     * Executed when the administrator uninstalls the gateway from Blesta.
     *
     * CRITICAL AUDIT / COMPLIANCE NOTE:
     * We deliberately DO NOT drop the `bkash_payment_attempts` table here.
     * Financial audit trails, bKash transaction IDs (trxID), and customer payment records
     * must be permanently retained for dispute resolution, accounting reconciliations, and tax compliance.
     *
     * @param int $gateway_id The ID of the gateway being uninstalled
     * @param bool $last_instance True if this is the last instance of this gateway across all companies
     */
    public function uninstall($gateway_id, $last_instance)
    {
        // Retain payment attempts as transaction audit records.
    }

    /**
     * Sets the active currency for the current transaction.
     *
     * Normalizes currency string to uppercase (e.g., "bdt" -> "BDT") to ensure reliable checks.
     *
     * @param string $currency The 3-character ISO 4217 currency code
     */
    public function setCurrency($currency)
    {
        $this->currency = strtoupper((string) $currency);
    }

    /**
     * Sets the metadata (configuration settings) for this gateway instance.
     *
     * Blesta decrypts encrypted fields (app_secret, password) and injects the array into the gateway.
     *
     * @param array|null $meta Key-value array of gateway configuration parameters
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta ?? [];
    }

    /**
     * Returns an array of sensitive configuration fields that Blesta must encrypt in the database.
     *
     * SENSITIVITY NOTE:
     * Returning 'app_secret' and 'password' here informs Blesta's core to encrypt their values
     * using AES-256 in the `gateway_meta` database table, preventing plain-text leakage in backups.
     *
     * @return array List of meta field names to encrypt at rest
     */
    public function encryptableFields()
    {
        return ['app_secret', 'password'];
    }

    /**
     * Returns a list of currencies supported by this gateway.
     *
     * bKash is a Bangladesh-exclusive mobile financial service operating strictly in Bangladeshi Taka.
     * Invoices in any other currency (USD, EUR, etc.) will not offer bKash as a payment option.
     *
     * @return array List of ISO 4217 currency codes
     */
    public function getCurrencies()
    {
        return ['BDT'];
    }

    /**
     * Renders the administrative settings view for configuring the gateway.
     *
     * Loads `views/default/settings.pdt`, which provides inputs for:
     *  - App Key
     *  - App Secret (password field)
     *  - API Username
     *  - API Password (password field)
     *  - Sandbox Mode (toggle switch)
     *
     * @param array|null $meta Current settings array from the database
     * @return string Rendered HTML of the admin configuration form
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView(
            'settings',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('meta', $meta ?? []);

        return $this->view->fetch();
    }

    /**
     * Validates gateway configuration submitted by an administrator before saving.
     *
     * Validation Rules:
     *  - All 4 API credentials ('app_key', 'app_secret', 'username', 'password') must be non-empty strings.
     *  - 'sandbox' must strictly be either the string 'true' or 'false'.
     *
     * @param array|null $meta Submitted settings from the HTTP POST form
     * @return array The validated settings array to be saved in Blesta
     */
    public function editSettings(array $meta = null)
    {
        $meta = $meta ?? [];
        $rules = [];

        // Enforce that all API credential fields are present, trimmed, and non-empty.
        foreach (['app_key', 'app_secret', 'username', 'password'] as $field) {
            $rules[$field] = [
                'valid' => [
                    'rule' => function ($value) {
                        return is_string($value) && trim($value) !== '';
                    },
                    'message' => Language::_('Bkash.!error.' . $field . '.empty', true)
                ]
            ];
        }

        // Default sandbox to 'false' if checkbox is unchecked (HTML forms omit unchecked checkboxes from POST data).
        if (!isset($meta['sandbox'])) {
            $meta['sandbox'] = 'false';
        }

        // Enforce strict boolean string for the sandbox flag.
        $rules['sandbox'] = [
            'valid' => [
                'rule' => ['in_array', ['true', 'false']],
                'message' => Language::_('Bkash.!error.sandbox.valid', true)
            ]
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * Constructs the payment process page and initiates the bKash checkout session.
     *
     * In Blesta's non-merchant gateway flow, buildProcess() is invoked when the client
     * reaches the checkout or invoice payment confirmation screen.
     *
     * BUSINESS & PAYMENT LIFECYCLE FLOW:
     *  1. Currency Guard: Ensure currency is BDT (bKash only supports Bangladeshi Taka).
     *  2. Financial Math: Convert payment and invoice allocations to integer sub-units (paisa).
     *     Avoid floating-point arithmetic (e.g. 0.1 + 0.2 != 0.3) which causes accounting bugs.
     *  3. Attempt Creation: Record an audit attempt in `bkash_payment_attempts` with a unique
     *     64-character hex `attempt_key` and unique `merchant_invoice_number` (state: 'created').
     *  4. Callback URL: Generate an HMAC-signed callback URL so bKash knows where to return.
     *  5. Authentication: Obtain a cached or new OAuth bearer token from bKash /token/grant.
     *  6. Create Payment: Call bKash API `/tokenized/checkout/create` (mode: 0011, intent: sale).
     *  7. Security & Binding: Validate bKash response against SSRF/open-redirects, store the
     *     returned `paymentID`, and transition attempt state: 'created' -> 'redirected'.
     *  8. User Interface: Render `views/default/process.pdt` with the official bKash checkout button.
     *
     * @param array $contact_info Contact details for the paying client (includes 'client_id')
     * @param float|string $amount Total amount to be charged for this transaction
     * @param array|null $invoice_amounts List of individual invoice allocations to be paid
     * @param array|null $options Additional payment options (description, return_url, recur, etc.)
     * @return string Rendered HTML markup containing the checkout redirect button or error alert
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // ── Step 1: Currency Guard ────────────────────────────────────────────
        // bKash operates exclusively in Bangladesh and supports BDT only.
        if ($this->currency !== 'BDT') {
            return $this->errorPage('Bkash.error.currency');
        }

        // ── Step 2: Monetary Validation & Sub-unit Integer Math ───────────────
        // Financial best practice: convert all currency values to integer paisa (1 BDT = 100 paisa)
        // to avoid IEEE 754 floating-point rounding discrepancies.
        $amount_paisa = $this->toPaisa($amount);
        $allocations = $this->normalizeInvoiceAmounts($invoice_amounts ?? []);
        if ($amount_paisa === null || $amount_paisa <= 0 || empty($allocations)) {
            return $this->errorPage('Bkash.error.invalid_amount');
        }

        // Verify that the sum of individual invoice allocations matches the total charged amount exactly.
        $allocation_total = 0;
        foreach ($allocations as $allocation) {
            $paisa = $this->toPaisa($allocation['amount']);
            if ($paisa === null || $paisa <= 0) {
                return $this->errorPage('Bkash.error.invalid_amount');
            }
            $allocation_total += $paisa;
        }
        if ($allocation_total !== $amount_paisa) {
            return $this->errorPage('Bkash.error.invalid_amount');
        }

        // ── Step 3: Create Local Payment Attempt Audit Record ──────────────────
        // Store an audit row in `bkash_payment_attempts` with a unique 30-minute TTL.
        // Generates a cryptographically random attempt_key and unique merchant invoice number.
        try {
            $attempt = $this->createPaymentAttempt([
                'company_id' => (int) Configure::get('Blesta.company_id'),
                'gateway_id' => (int) $this->gateway_id,
                'client_id' => (int) ($contact_info['client_id'] ?? 0),
                'amount' => $this->formatPaisa($amount_paisa),
                'invoice_amounts' => $allocations,
                'merchant_invoice_number' => 'BL-' . bin2hex(random_bytes(12))
            ]);
        } catch (Throwable $e) {
            $this->log('payment-attempt', 'Could not create payment attempt.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 4: Construct Tamper-Proof HMAC Callback URL ───────────────────
        // The callback URL instructs bKash where to redirect the user after payment.
        // Includes the attempt_key and an HMAC-SHA256 signature to prevent URL parameter tampering.
        try {
            $callback_url = $this->buildCallbackUrl($attempt);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'callback_url_unavailable');
            $this->log('payment-attempt', 'Could not construct bKash callback URL.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 5: Acquire bKash API OAuth Bearer Token ───────────────────────
        // Retrieves a cached token (valid for 50 minutes) or executes /token/grant with API credentials.
        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt['id'], 'token_unavailable');
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 6: Call bKash /create API Endpoint ───────────────────────────
        // Initiates a checkout session on bKash's payment servers.
        // Payload breakdown:
        //  - 'mode' => '0011': Tokenized checkout without agreement (direct one-time payment).
        //  - 'payerReference' => 'C<id>': Customer reference identifier for bKash merchant reports.
        //  - 'callbackURL': The signed URL where bKash redirects upon completion/cancellation.
        //  - 'amount': Formatted decimal string (e.g. "150.00").
        //  - 'currency' => 'BDT': Bangladeshi Taka.
        //  - 'intent' => 'sale': Immediate capture upon execution (sale transaction).
        //  - 'merchantInvoiceNumber': Unique invoice reference for cross-platform tracing.
        $response = $this->request('/create', [
            'mode' => '0011',
            'payerReference' => 'C' . $attempt['client_id'],
            'callbackURL' => $callback_url,
            'amount' => $attempt['amount'],
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $attempt['merchant_invoice_number']
        ], $token);

        // ── Step 7: Validate API Response & Protect Against SSRF ───────────────
        // Verify that bKash returned statusCode '0000', a non-empty paymentID, and a valid bkashURL.
        // Critical SSRF Defense: Verify the hostname of bkashURL belongs strictly to official bKash domains.
        if (!$this->isSuccessfulApiResponse($response)
            || empty($response->paymentID)
            || !$this->isAllowedCheckoutUrl($response->bkashURL ?? null)
        ) {
            $checkout_url = $response->bkashURL ?? null;
            $checkout_url_parts = is_string($checkout_url) ? parse_url($checkout_url) : false;
            $this->log('payment-attempt', json_encode([
                'status_code' => $response->statusCode ?? null,
                'payment_id_present' => !empty($response->paymentID),
                'checkout_url_allowed' => $this->isAllowedCheckoutUrl($checkout_url),
                'checkout_url_scheme' => $checkout_url_parts['scheme'] ?? null,
                'checkout_url_host' => $checkout_url_parts['host'] ?? null
            ]), 'output', false);
            $this->markAttemptFailed($attempt['id'], 'create_failed');
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 8: Bind bKash paymentID and Transition State ─────────────────
        // Atomically updates state: 'created' -> 'redirected' and records bKash paymentID.
        try {
            $this->bindPaymentId($attempt['id'], (string) $response->paymentID);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'payment_id_storage_failed');
            $this->log('payment-attempt', 'Could not save bKash payment ID.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 9: Render Checkout UI Button ─────────────────────────────────
        // Displays the bKash branded button leading to bKash's hosted payment gateway.
        $this->view = $this->makeView(
            'process',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('checkout_url', $response->bkashURL);

        return $this->view->fetch();
    }

    /**
     * Validates and processes the incoming callback from bKash after payment completion or cancellation.
     *
     * In Blesta's architecture, this method is invoked when an offsite gateway callback / IPN
     * endpoint is requested (e.g. /callback/gw/<company_id>/bkash/?attempt=...&sig=...).
     *
     * BUSINESS LOGIC & EXECUTION LIFECYCLE:
     *  Step 1: HMAC Signature Verification — verifies the URL parameters have not been tampered with.
     *  Step 2: Attempt Retrieval & Ownership Check — loads attempt from DB and validates company/gateway scope.
     *  Step 3: Idempotency & State Machine Guards — prevents overwriting 'verified' or 'completed' attempts
     *          even if the unsigned `status` query parameter is manipulated in the URL.
     *  Step 4: Cancellation Detection — detects if customer cancelled or PIN failed, marking attempt failed
     *          ONLY if the attempt has not yet been verified/completed.
     *  Step 5: Atomic Claim Lock — transitions state from 'redirected' to 'executing' atomically.
     *  Step 6: Token Acquisition — retrieves bKash API bearer token.
     *  Step 7: Payment Execution — calls bKash `/execute` API (with fallback to `/payment/status`).
     *  Step 8: Payment Verification — ensures bKash status is 'Completed' and amount matches exactly.
     *  Step 9: Anti-Replay Guard — ensures the provider transaction ID (trxID) has never been used before.
     *  Step 10: State Finalization — marks attempt 'verified' and returns transaction data array to Blesta.
     *
     * @param array $get GET query parameters received from bKash callback redirect
     * @param array $post POST parameters (unused; bKash callbacks use GET)
     * @return array|null Formatted transaction data array on success, or null on failure/cancellation
     */
    public function validate(array $get, array $post)
    {
        $attempt_key = (string) ($get['attempt'] ?? '');
        $signature = (string) ($get['sig'] ?? '');
        $payment_id = (string) ($get['paymentID'] ?? '');

        // ── Step 1: Verify HMAC Callback Signature ────────────────────────────
        // CRITICAL SECURITY CHECK: Validates that the attempt key and signature parameters
        // were generated by our server and haven't been modified by the customer or an attacker.
        if (!$this->validCallbackSignature($attempt_key, $signature)) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 2: Retrieve Payment Attempt and Validate Context ─────────────
        // Verify multi-tenant ownership (company_id, gateway_id) and paymentID match.
        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt
            || (int) $attempt->company_id !== (int) Configure::get('Blesta.company_id')
            || (int) $attempt->gateway_id !== (int) $this->gateway_id
            || ($payment_id !== '' && !hash_equals((string) $attempt->bkash_payment_id, $payment_id))
        ) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 3: Handle Idempotent Re-delivery & State Transitions ─────────
        // SECURITY FIX (Bug #1): State guards execute BEFORE checking the unsigned `$get['status']` parameter
        // and BEFORE TTL expiration checks on already-verified attempts.
        // If already 'completed', return success immediately (prevents duplicate invoice credits or status tampering).
        if ($attempt->state === 'completed') {
            return $this->transactionResponse($attempt, [], 'approved');
        }

        // If already 'verified', NEVER allow an unsigned `status=cancel` parameter to mark it 'failed'.
        if ($attempt->state === 'verified') {
            if ($this->getExistingBlestaTransaction($attempt)) {
                return $this->transactionResponse($attempt, [], 'approved');
            }
            // If Blesta hasn't recorded the transaction yet, re-return the approved response with invoice allocations
            // so Blesta can finish recording it, without re-executing or failing the attempt.
            return $this->transactionResponse(
                $attempt,
                $this->decodeInvoiceAmounts($attempt->invoice_amounts),
                'approved',
                (string) $attempt->bkash_trx_id
            );
        }

        // Enforce 30-minute TTL expiration strictly for unverified attempts.
        if (strtotime($attempt->expires_at . ' UTC') < time()) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // If stuck in 'executing' past the retry delay (e.g. server timeout), reset for retry.
        if ($attempt->state === 'executing') {
            if (strtotime($attempt->updated_at . ' UTC') > time() - self::CALLBACK_RETRY_DELAY_SECONDS) {
                $this->Input->setErrors($this->getCommonError('general'));
                return null;
            }
            $this->resetAttemptForRetry($attempt->id);
        }

        // ── Step 4: Detect Cancellation / Failure ─────────────────────────────
        // bKash sends ?status=cancel or ?status=failure if customer aborts or enters wrong PIN.
        // Since 'verified' and 'completed' states are already handled above, this only affects unverified attempts.
        if (strtolower((string) ($get['status'] ?? '')) !== 'success' || $payment_id === '') {
            $this->markAttemptFailed($attempt->id, 'customer_cancelled_or_failed');
            $this->Input->setErrors([
                'general' => [
                    'invalid' => Language::_('Bkash.error.cancelled', true)
                ]
            ]);
            return null;
        }

        // ── Step 5: Atomic State Claim (Race Condition Prevention) ────────────
        // Uses an atomic SQL UPDATE with WHERE state = 'redirected' to ensure
        // that only one concurrent thread can transition this payment into 'executing'.
        if (!$this->claimAttempt($attempt->id)) {
            $attempt = $this->getAttempt($attempt_key);
            if ($attempt
                && in_array($attempt->state, ['verified', 'completed'], true)
                && $this->getExistingBlestaTransaction($attempt)
            ) {
                return $this->transactionResponse($attempt, [], 'approved');
            }
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 6: Acquire bKash OAuth Bearer Token ──────────────────────────
        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt->id, 'token_unavailable');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 7: Execute Payment via bKash API ─────────────────────────────
        // Calls /execute with paymentID. If execute does not return 'Completed' (e.g. network timeout),
        // queries /payment/status as an inquiry fallback to prevent double-charging.
        $response = $this->request('/execute', ['paymentID' => $payment_id], $token);
        if (!$this->isCompletedPayment($response, $attempt)) {
            $response = $this->request('/payment/status', ['paymentID' => $payment_id], $token);
        }

        // Handle network/connection failure — reset attempt state so customer/callback can retry.
        if ($response === null) {
            $this->resetAttemptForRetry($attempt->id);
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 8: Deep Verification of Payment Status & Amount ──────────────
        // Enforces that statusCode is '0000', transactionStatus is 'Completed', currency is BDT,
        // and integer paisa matches the expected amount exactly.
        if (!$this->isCompletedPayment($response, $attempt)) {
            $this->markAttemptFailed($attempt->id, 'verification_failed');
            return $this->transactionResponse($attempt, [], 'declined');
        }

        // ── Step 9: Replay Attack Defense (Unique trxID Check) ────────────────
        // Prevents malicious actors from submitting a previously used bKash trxID for a new attempt.
        if ($this->providerTransactionExists((string) $response->trxID, $attempt->id)) {
            $this->markAttemptFailed($attempt->id, 'duplicate_provider_transaction');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 10: Mark Verified & Return Transaction Data to Blesta ─────────
        // Updates state to 'verified', records bKash trxID, and returns transaction response
        // so Blesta automatically marks the associated invoice(s) as paid.
        try {
            $first_completion = $this->markAttemptVerified(
                $attempt->id,
                $payment_id,
                (string) $response->trxID
            );
        } catch (Throwable $e) {
            $this->log('payment-attempt', 'Could not finalize verified bKash payment.', 'output', false);
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }
        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // Set the success message across session and controller for client confirmation display.
        $this->setPaymentSuccessMessage($attempt, (string) $response->trxID);

        return $this->transactionResponse(
            $attempt,
            $first_completion ? $this->decodeInvoiceAmounts($attempt->invoice_amounts) : [],
            'approved',
            (string) $response->trxID
        );
    }

    /**
     * Handles the client's return to Blesta after completing the offsite checkout flow.
     *
     * In Blesta's non-merchant gateway flow, after the callback is processed by validate(),
     * the customer's browser is directed back to Blesta's return URL (ClientPay::received).
     *
     * SECURITY ARCHITECTURE NOTE:
     * This method is strictly read-only regarding payment execution. It reads the server-side
     * attempt record verified by validate(); it NEVER executes or captures payments on its own.
     * Furthermore, if an attempt is already 'verified' or 'completed', it ignores tampered
     * query parameters like `?status=cancel` so the audit trail cannot be corrupted.
     *
     * @param array $get GET query parameters from the return URL
     * @param array $post POST parameters (unused)
     * @return array|null Formatted transaction data array on success, empty array, or null with errors
     */
    public function success(array $get, array $post)
    {
        $attempt_key = (string) ($get['attempt'] ?? '');
        $signature = (string) ($get['sig'] ?? '');
        // Verify HMAC signature to protect return parameters against tampering.
        if (!$this->validCallbackSignature($attempt_key, $signature)) {
            return [];
        }

        // Load attempt and verify multi-tenancy and paymentID match.
        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt
            || (int) $attempt->company_id !== (int) Configure::get('Blesta.company_id')
            || (int) $attempt->gateway_id !== (int) $this->gateway_id
            || (!empty($get['paymentID']) && !hash_equals((string) $attempt->bkash_payment_id, (string) $get['paymentID']))
        ) {
            return [];
        }

        // SECURITY FIX (Bug #1): Check if attempt is already 'verified' or 'completed' FIRST,
        // before inspecting the unsigned `$get['status']` parameter.
        if (in_array($attempt->state, ['verified', 'completed'], true)) {
            // Confirm Blesta has recorded the transaction.
            $transaction = $this->getExistingBlestaTransaction($attempt);
            if (!$transaction || $transaction->status !== 'approved') {
                return [];
            }

            // Informational reconciliation check
            if (!$this->expectedInvoicesApplied($attempt, $transaction)) {
                $this->log('payment-reconcile', 'Notice: Invoice allocation differed from attempt expectations.', 'output', true);
            }

            // Transition state from 'verified' -> 'completed' (terminal success state).
            if ($attempt->state === 'verified') {
                $this->markAttemptCompleted($attempt->id);
                $attempt = $this->getAttempt($attempt_key);
            }

            // Set the success message across session and controller for client confirmation display.
            $this->setPaymentSuccessMessage($attempt, (string) $attempt->bkash_trx_id);

            return $this->transactionResponse(
                $attempt,
                $this->decodeInvoiceAmounts($attempt->invoice_amounts),
                'approved',
                (string) $attempt->bkash_trx_id
            );
        }

        // Only if the attempt is NOT verified/completed, handle cancellation or failure from URL status.
        if (strtolower((string) ($get['status'] ?? '')) !== 'success') {
            $this->markAttemptFailed($attempt->id, 'customer_cancelled_or_failed');
            $this->Input->setErrors([
                'general' => [
                    'invalid' => Language::_('Bkash.error.cancelled', true)
                ]
            ]);
            return null;
        }

        return [];
    }

    /**
     * Refunds a previously completed bKash transaction via the bKash Tokenized Checkout Refund API.
     *
     * Maps Blesta's transaction records:
     *  - $reference_id: The bKash `paymentID` (or fallback lookup from `bkash_payment_attempts`)
     *  - $transaction_id: The bKash `trxID`
     *
     * @param string $reference_id The reference ID for the gateway transaction (bKash paymentID)
     * @param string $transaction_id The transaction ID for the gateway transaction (bKash trxID)
     * @param float $amount The amount to refund
     * @param string|null $notes Notes about the refund (reason)
     * @return array|null Formatted refund transaction array on success, or null on error
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $amount_paisa = $this->toPaisa($amount);
        if ($amount_paisa === null || $amount_paisa <= 0) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        $payment_id = trim((string) $reference_id);
        $trx_id = trim((string) $transaction_id);

        // If reference_id is missing or identical to trx_id, look up bkash_payment_id from the audit table.
        if (($payment_id === '' || $payment_id === $trx_id) && $trx_id !== '') {
            $record = new Record();
            $attempt = $record->select(['bkash_payment_id'])->from(self::ATTEMPT_TABLE)
                ->where('bkash_trx_id', '=', $trx_id)
                ->fetch();
            if ($attempt && !empty($attempt->bkash_payment_id)) {
                $payment_id = (string) $attempt->bkash_payment_id;
            }
        }

        if ($payment_id === '' || $trx_id === '') {
            $this->Input->setErrors($this->getCommonError('unsupported'));
            return null;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        $reason = is_string($notes) && trim($notes) !== ''
            ? substr(trim($notes), 0, 255)
            : 'Customer Refund';

        $response = $this->request('/payment/refund', [
            'paymentID' => $payment_id,
            'trxID' => $trx_id,
            'amount' => $this->formatPaisa($amount_paisa),
            'sku' => 'Blesta-Refund',
            'reason' => $reason
        ], $token);

        if (!$this->isSuccessfulApiResponse($response) || empty($response->refundTrxID)) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        return [
            'status' => 'refunded',
            'transaction_id' => (string) $response->refundTrxID,
            'reference_id' => $payment_id
        ];
    }

    /**
     * Provisions the `bkash_payment_attempts` database table.
     */
    private function createAttemptTable()
    {
        $record = new Record();
        $record->query(
            'CREATE TABLE IF NOT EXISTS `' . self::ATTEMPT_TABLE . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `gateway_id` INT UNSIGNED NOT NULL,
                `client_id` INT UNSIGNED NOT NULL,
                `attempt_key` CHAR(64) NOT NULL,
                `bkash_payment_id` VARCHAR(100) NULL,
                `bkash_trx_id` VARCHAR(100) NULL,
                `merchant_invoice_number` VARCHAR(100) NOT NULL,
                `amount` DECIMAL(14,2) NOT NULL,
                `currency` CHAR(3) NOT NULL,
                `invoice_amounts` TEXT NOT NULL,
                `state` VARCHAR(16) NOT NULL DEFAULT \'created\',
                `failure_reason` VARCHAR(100) NULL,
                `verified_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_bkash_attempt_key` (`attempt_key`),
                UNIQUE KEY `uq_bkash_payment_id` (`bkash_payment_id`),
                UNIQUE KEY `uq_bkash_trx_id` (`bkash_trx_id`),
                KEY `idx_bkash_attempt_state_expiry` (`state`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Records a new payment attempt row in `bkash_payment_attempts`.
     */
    private function createPaymentAttempt(array $data)
    {
        if ($data['company_id'] <= 0 || $data['gateway_id'] <= 0 || $data['client_id'] <= 0) {
            throw new RuntimeException('The payment context is incomplete.');
        }

        $attempt_key = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $record = new Record();
        $record->insert(self::ATTEMPT_TABLE, [
            'company_id' => $data['company_id'],
            'gateway_id' => $data['gateway_id'],
            'client_id' => $data['client_id'],
            'attempt_key' => $attempt_key,
            'merchant_invoice_number' => $data['merchant_invoice_number'],
            'amount' => $data['amount'],
            'currency' => 'BDT',
            'invoice_amounts' => json_encode($data['invoice_amounts'], JSON_THROW_ON_ERROR),
            'state' => 'created',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS),
            'created_at' => $now,
            'updated_at' => $now
        ]);

        return [
            'id' => (int) $record->lastInsertId(),
            'attempt_key' => $attempt_key,
            'client_id' => $data['client_id'],
            'amount' => $data['amount'],
            'merchant_invoice_number' => $data['merchant_invoice_number']
        ];
    }

    /**
     * Binds the bKash paymentID returned from /create and transitions state to 'redirected'.
     */
    private function bindPaymentId($attempt_id, $payment_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'created')
            ->update(self::ATTEMPT_TABLE, [
                'bkash_payment_id' => $payment_id,
                'state' => 'redirected',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        if ($record->affectedRows($statement) !== 1) {
            throw new RuntimeException('The payment attempt could not be updated.');
        }
    }

    /**
     * Retrieves an attempt record from the database by its 64-character attempt key.
     */
    private function getAttempt($attempt_key)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $attempt_key)) {
            return null;
        }
        $record = new Record();

        return $record->select()->from(self::ATTEMPT_TABLE)
            ->where('attempt_key', '=', $attempt_key)
            ->fetch();
    }

    /**
     * Atomically claims a payment attempt for execution, locking out concurrent threads.
     */
    private function claimAttempt($attempt_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'redirected')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'executing',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        return $record->affectedRows($statement) === 1;
    }

    /**
     * Marks an attempt as verified after bKash /execute or /payment/status confirms payment capture.
     */
    private function markAttemptVerified($attempt_id, $payment_id, $trx_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'executing')
            ->where('bkash_payment_id', '=', $payment_id)
            ->update(self::ATTEMPT_TABLE, [
                'bkash_trx_id' => $trx_id,
                'state' => 'verified',
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        if ($record->affectedRows($statement) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Marks an attempt as completed (the final terminal success state).
     */
    private function markAttemptCompleted($attempt_id)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'verified')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'completed',
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Queries Blesta's core Transactions model to check if this payment was already recorded.
     */
    private function getExistingBlestaTransaction($attempt)
    {
        Loader::loadModels($this, ['Transactions']);

        if (!empty($attempt->bkash_trx_id)) {
            $trans = $this->Transactions->getByTransactionId(
                (string) $attempt->bkash_trx_id,
                (int) $attempt->client_id,
                (int) $attempt->gateway_id
            );
            if ($trans) {
                return $trans;
            }
        }

        if (!empty($attempt->bkash_payment_id)) {
            return $this->Transactions->getByTransactionId(
                (string) $attempt->bkash_payment_id,
                (int) $attempt->client_id,
                (int) $attempt->gateway_id
            );
        }

        return null;
    }

    /**
     * Verifies that Blesta applied the transaction to the exact expected invoices and amounts.
     */
    private function expectedInvoicesApplied($attempt, $transaction)
    {
        $expected = [];
        foreach ($this->decodeInvoiceAmounts($attempt->invoice_amounts) as $invoice) {
            $paisa = $this->toPaisa($invoice['amount'] ?? null);
            if ($paisa === null) {
                return false;
            }
            $invoice_id = (int) ($invoice['id'] ?? 0);
            $expected[$invoice_id] = ($expected[$invoice_id] ?? 0) + $paisa;
        }

        $applied = [];
        foreach ($this->Transactions->getApplied($transaction->id) as $row) {
            $paisa = $this->toPaisa($row->applied_amount ?? null);
            if ($paisa === null) {
                return false;
            }
            $invoice_id = (int) $row->invoice_id;
            $applied[$invoice_id] = ($applied[$invoice_id] ?? 0) + $paisa;
        }
        ksort($expected);
        ksort($applied);

        return $expected === $applied;
    }

    /**
     * Marks an attempt as failed with a diagnostic reason code.
     *
     * SECURITY FIX (Bug #1):
     * Strictly refuses to overwrite an attempt that has already reached 'verified' or 'completed' state.
     */
    private function markAttemptFailed($attempt_id, $reason)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', 'in', ['created', 'redirected', 'executing'])
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'failed',
                'failure_reason' => substr($reason, 0, 100),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Resets a payment attempt from 'executing' back to 'redirected' to allow retry.
     */
    private function resetAttemptForRetry($attempt_id)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'executing')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'redirected',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Checks if a bKash trxID has already been recorded in any other attempt.
     */
    private function providerTransactionExists($trx_id, $attempt_id)
    {
        $record = new Record();
        $existing = $record->select(['id'])->from(self::ATTEMPT_TABLE)
            ->where('bkash_trx_id', '=', $trx_id)
            ->where('id', '!=', $attempt_id)
            ->fetch();

        return (bool) $existing;
    }

    /**
     * Obtains an OAuth access/id_token for bKash API requests, utilizing local caching.
     */
    private function getAccessToken()
    {
        $cache = \Blesta\Core\Cache\CacheFactory::get();
        $cache_key = hash('sha256', ($this->isSandbox() ? 'sandbox:' : 'live:') . $this->metaValue('app_key'));
        $cached = $cache->read($cache_key, 'bkash-token');
        if (is_array($cached)
            && !empty($cached['token'])
            && !empty($cached['expires_at'])
            && (int) $cached['expires_at'] > time()
        ) {
            return $cached['token'];
        }

        $response = $this->request(
            '/token/grant',
            [
                'app_key' => $this->metaValue('app_key'),
                'app_secret' => $this->metaValue('app_secret')
            ],
            null,
            [
                'username: ' . $this->metaValue('username'),
                'password: ' . $this->metaValue('password')
            ]
        );
        if (!$this->isSuccessfulApiResponse($response) || empty($response->id_token)) {
            return null;
        }

        $expires_in = (int) ($response->expires_in ?? $response->expiresIn ?? self::TOKEN_TTL_SECONDS);
        $ttl = max(60, min(self::TOKEN_TTL_SECONDS, $expires_in - 60));
        $token = (string) $response->id_token;
        $cache->write($cache_key, [
            'token' => $token,
            'expires_at' => time() + $ttl
        ], $ttl, 'bkash-token');

        return $token;
    }

    /**
     * Executes an HTTPS POST request to the bKash Tokenized Checkout REST API.
     */
    private function request($endpoint, array $payload, $token = null, array $extra_headers = [])
    {
        $url = $this->baseUrl() . $endpoint;
        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $extra_headers
        );
        if ($token !== null) {
            $headers[] = 'Authorization: ' . $token;
            $headers[] = 'X-APP-Key: ' . $this->metaValue('app_key');
        }

        // CRITICAL PRIVACY & SECURITY: Redact credentials prior to writing to the audit log.
        $log_payload = $payload;
        foreach (['app_secret', 'password'] as $secret_field) {
            if (isset($log_payload[$secret_field])) {
                $log_payload[$secret_field] = '[redacted]';
            }
        }
        $this->log($url, json_encode($log_payload), 'input', true);

        $curl = curl_init($url);
        if ($curl === false) {
            $this->log($url, 'Unable to initialize HTTPS request.', 'output', false);
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
        ]);
        $raw = curl_exec($curl);
        $curl_error = curl_error($curl);
        $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false) {
            $this->log($url, 'HTTPS request failed: ' . $curl_error, 'output', false);
            return null;
        }

        $response = json_decode($raw);
        $valid_json = json_last_error() === JSON_ERROR_NONE && is_object($response);
        $log_response = [
            'http_status' => $http_code,
            'status_code' => ($response->statusCode ?? null),
            'transaction_status' => ($response->transactionStatus ?? null),
            'payment_id' => ($response->paymentID ?? null),
            'transaction_id' => ($response->trxID ?? $response->refundTrxID ?? null),
            'currency' => ($response->currency ?? null),
            'amount' => ($response->amount ?? null)
        ];
        $this->log(
            $url,
            json_encode($log_response),
            'output',
            $valid_json && $http_code >= 200 && $http_code < 300
        );

        return $valid_json ? $response : null;
    }

    /**
     * Checks if a bKash API response signifies a successful operation (statusCode 0000).
     */
    private function isSuccessfulApiResponse($response)
    {
        return is_object($response) && (string) ($response->statusCode ?? '') === '0000';
    }

    /**
     * Verifies that a payment execute/status response represents a genuine completed transaction.
     */
    private function isCompletedPayment($response, $attempt)
    {
        if (!$this->isSuccessfulApiResponse($response)
            || (string) ($response->transactionStatus ?? '') !== 'Completed'
            || strtoupper((string) ($response->currency ?? '')) !== 'BDT'
            || empty($response->trxID)
        ) {
            return false;
        }

        $actual_paisa = $this->toPaisa($response->amount ?? null);
        $expected_paisa = $this->toPaisa($attempt->amount);

        return $actual_paisa !== null
            && $expected_paisa !== null
            && $actual_paisa === $expected_paisa;
    }

    /**
     * Sets the localized payment success message across the active session and controller.
     */
    private function setPaymentSuccessMessage($attempt, $trx_id = null)
    {
        $amount_num = number_format((float) $attempt->amount, 2, '.', '');
        $amount_str = $amount_num . ' BDT';

        $resolved_trx = (string) ($trx_id ?: $attempt->bkash_trx_id);

        $title = Language::_('Bkash.success.title', true);
        $message_text = Language::_('Bkash.success.message', true, $amount_str);
        $trx_text = Language::_('Bkash.success.trx_id', true, $resolved_trx);
        $receipt_text = Language::_('Bkash.success.receipt_sent', true);

        $html_message = '<h4 class="alert-heading mb-2"><i class="fas fa-check-circle text-success me-1"></i> '
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>'
            . '<p class="mb-1">' . htmlspecialchars($message_text, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p class="mb-1"><strong>' . htmlspecialchars($trx_text, ENT_QUOTES, 'UTF-8') . '</strong></p>'
            . '<p class="mb-0 small text-muted"><i class="fas fa-envelope me-1"></i> '
            . htmlspecialchars($receipt_text, ENT_QUOTES, 'UTF-8') . '</p>';

        // 1. Write flash message to session (persists across HTTP 302 redirects to client/pay/received/)
        try {
            Loader::loadComponents($this, ['Session']);
            if (isset($this->Session)) {
                $this->Session->write('flash', [
                    'message' => $html_message,
                    'in_current_view' => true
                ]);
            }
        } catch (Throwable $e) {
            // Ignore session write errors in background workers
        }

        // 2. Also populate standard $_SESSION directly if session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'] = [
                'message' => $html_message,
                'in_current_view' => true
            ];
        }

        // 3. Locate active Controller in call stack and set message directly into view context
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (isset($frame['object']) && is_a($frame['object'], 'AppController')) {
                try {
                    $ref = new ReflectionMethod($frame['object'], 'setMessage');
                    $ref->setAccessible(true);
                    $ref->invoke($frame['object'], 'message', $html_message, false, ['preserve_tags' => true]);
                } catch (Throwable $e) {
                    if (method_exists($frame['object'], 'setMessage')) {
                        $frame['object']->setMessage('message', $html_message);
                    }
                }
                break;
            }
        }

        return $html_message;
    }

    /**
     * Formats the standardized transaction array returned to Blesta core.
     */
    private function transactionResponse($attempt, array $invoices, $status, $trx_id = null)
    {
        $resolved_trx = trim((string) ($trx_id ?: $attempt->bkash_trx_id));
        $payment_id = trim((string) $attempt->bkash_payment_id);

        return [
            'client_id' => (int) $attempt->client_id,
            'amount' => (string) $attempt->amount,
            'currency' => 'BDT',
            'status' => $status,
            'transaction_id' => $resolved_trx !== '' ? $resolved_trx : ($payment_id !== '' ? $payment_id : null),
            'reference_id' => $payment_id !== '' ? $payment_id : $resolved_trx,
            'parent_transaction_id' => null,
            'invoices' => $invoices
        ];
    }

    /**
     * Constructs the HMAC-signed callback URL that bKash redirects to after payment.
     */
    private function buildCallbackUrl(array $attempt)
    {
        $base_url = rtrim((string) Configure::get('Blesta.gw_callback_url'), '/');
        if ($base_url === '' || (int) Configure::get('Blesta.company_id') <= 0) {
            throw new RuntimeException('The gateway callback URL is not configured.');
        }

        $callback_parts = parse_url($base_url);
        $callback_host = strtolower((string) ($callback_parts['host'] ?? ''));
        if (!$this->isSandbox()
            && (
                ($callback_parts['scheme'] ?? '') !== 'https'
                || $callback_host === ''
                || in_array($callback_host, ['localhost', '127.0.0.1', '::1'], true)
                || preg_match('/\.(localhost|local|test)$/', $callback_host)
                || filter_var($callback_host, FILTER_VALIDATE_IP)
            )
        ) {
            throw new RuntimeException('Live bKash payments require a public HTTPS callback URL.');
        }

        $callback_url = $base_url . '/' . (int) Configure::get('Blesta.company_id') . '/bkash/';
        $signature = hash_hmac(
            'sha256',
            $attempt['attempt_key'],
            hash('sha256', $this->metaValue('app_secret'), true)
        );

        return $callback_url . '?' . http_build_query([
            'attempt' => $attempt['attempt_key'],
            'sig' => $signature
        ]);
    }

    /**
     * Validates the HMAC signature received in a callback request.
     */
    private function validCallbackSignature($attempt_key, $signature)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $attempt_key)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $attempt_key,
            hash('sha256', $this->metaValue('app_secret'), true)
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Validates that the checkout URL returned by bKash belongs to official bKash hostnames.
     */
    private function isAllowedCheckoutUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        $expected_host = parse_url($this->baseUrl(), PHP_URL_HOST);
        $allowed_hosts = [strtolower((string) $expected_host)];
        if ($this->isSandbox()) {
            $allowed_hosts[] = 'sandbox.payment.bkash.com';
        } else {
            $allowed_hosts[] = 'payment.bkash.com';
        }

        return ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), $allowed_hosts, true);
    }

    /**
     * Returns the base bKash API URL depending on current Sandbox setting.
     */
    private function baseUrl()
    {
        return $this->isSandbox() ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * Determines whether the gateway is running in Sandbox (test) mode.
     */
    private function isSandbox()
    {
        return $this->metaValue('sandbox') === 'true';
    }

    /**
     * Safely reads a string value from the gateway metadata settings.
     */
    private function metaValue($key)
    {
        return isset($this->meta[$key]) && is_scalar($this->meta[$key])
            ? (string) $this->meta[$key]
            : '';
    }

    /**
     * Normalizes an array of invoice allocation amounts into a standardized structure.
     */
    private function normalizeInvoiceAmounts(array $invoice_amounts)
    {
        $normalized = [];
        foreach ($invoice_amounts as $invoice_id => $allocation) {
            if (is_array($allocation)) {
                $id = $allocation['id'] ?? $allocation['invoice_id'] ?? null;
                $amount = $allocation['amount'] ?? null;
            } else {
                $id = $invoice_id;
                $amount = $allocation;
            }
            if (!is_numeric($id) || (int) $id <= 0 || $this->toPaisa($amount) === null) {
                return [];
            }
            $normalized[] = [
                'id' => (int) $id,
                'amount' => $this->formatPaisa($this->toPaisa($amount))
            ];
        }

        return $normalized;
    }

    /**
     * Decodes a JSON-encoded invoice_amounts string from the database.
     */
    private function decodeInvoiceAmounts($invoice_amounts)
    {
        $decoded = json_decode((string) $invoice_amounts, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts a decimal currency string/float into integer paisa (sub-unit cents).
     */
    private function toPaisa($amount)
    {
        if (!is_numeric($amount) || !preg_match('/^\d+(?:\.\d+)?$/', (string) $amount)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        if (strlen($whole) > 12 || trim(substr($fraction, 2), '0') !== '') {
            return null;
        }

        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /**
     * Formats an integer paisa value into a standard 2-decimal currency string.
     */
    private function formatPaisa($amount)
    {
        $amount = (int) $amount;

        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Renders a client-facing error view with a localized error message.
     */
    private function errorPage($language_key)
    {
        $this->view = $this->makeView(
            'process',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('error_message', Language::_($language_key, true));

        return $this->view->fetch();
    }
}
