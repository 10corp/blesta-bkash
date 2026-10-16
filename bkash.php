<?php
/**
 * bKash Tokenized Checkout — Blesta Non-Merchant Gateway
 *
 * Implements the bKash Tokenized Checkout (Without Agreement) flow for Blesta 5.x/6.x.
 * Uses Checkout URL mode (mode=0011) to redirect customers to bKash's hosted payment page.
 *
 * Payment Flow:
 *   1. buildProcess() — Creates a payment attempt in the DB, calls bKash /create API,
 *      returns HTML with a "Continue to bKash" button linking to bkashURL.
 *   2. Customer completes (or cancels) payment on bKash's page.
 *   3. bKash redirects back to the callback URL with ?status=success|cancel|failure&paymentID=...
 *   4. validate() — Blesta routes the callback here. Executes the payment via bKash /execute API,
 *      verifies amount/currency/trxID, and returns a transaction response to Blesta.
 *   5. success() — Blesta calls this for the client-facing return page. Only reads the
 *      server-side attempt state; never approves a payment itself.
 *
 * State Machine (bkash_payment_attempts.state):
 *   created → redirected → executing → verified → completed
 *                                    ↘ failed
 *   Any non-completed state may transition to "failed" on error.
 *
 * Security:
 *   - HMAC-SHA256 callback signatures prevent URL tampering
 *   - Atomic DB claims (claimAttempt) prevent double-execution race conditions
 *   - Provider trxID uniqueness check blocks replay attacks
 *   - Checkout URL host validation blocks phishing redirects
 *   - Live mode enforces public HTTPS callback URLs
 *   - Sensitive fields (app_secret, password) are encrypted at rest by Blesta
 *   - API logs redact secrets before writing
 *
 * @package    blesta
 * @subpackage blesta.components.gateways.nonmerchant.bkash
 * @see        https://developer.bka.sh/docs/tokenized-without-agreement
 * @see        https://docs.blesta.com/developers/gateways/non-merchant-gateways
 */
class Bkash extends NonmerchantGateway
{
    // ──────────────────────────────────────────────────────────────────────────
    // Constants — bKash API endpoints and internal configuration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var string bKash Tokenized Checkout sandbox API base URL.
     *             Used when sandbox mode is enabled in gateway settings.
     */
    private const SANDBOX_BASE_URL =
        'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string bKash Tokenized Checkout live/production API base URL.
     *             Used when sandbox mode is disabled (live payments).
     */
    private const LIVE_BASE_URL =
        'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string MySQL table name for tracking payment attempts.
     *             Created automatically on install/upgrade.
     */
    private const ATTEMPT_TABLE = 'bkash_payment_attempts';

    /**
     * @var int How long (seconds) a payment attempt remains valid before expiring.
     *          After this, callbacks for the attempt are rejected. Default: 30 minutes.
     */
    private const ATTEMPT_TTL_SECONDS = 1800;

    /**
     * @var int Maximum lifetime (seconds) for a cached bKash access token.
     *          The actual TTL is min(this, token's expires_in - 60s buffer).
     */
    private const TOKEN_TTL_SECONDS = 3000;

    /**
     * @var int Minimum delay (seconds) before allowing a retry on a stuck "executing"
     *          or recently "verified" attempt. Prevents rapid duplicate execution.
     */
    private const CALLBACK_RETRY_DELAY_SECONDS = 300;

    // ──────────────────────────────────────────────────────────────────────────
    // Instance properties
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @var array Gateway settings (app_key, app_secret, username, password, sandbox).
     *            Populated by Blesta via setMeta() before any payment operation.
     */
    private $meta = [];

    // ══════════════════════════════════════════════════════════════════════════
    // BLESTA LIFECYCLE METHODS
    // These are called by Blesta's gateway framework at specific lifecycle events.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Initialize the gateway.
     *
     * Loads the JSON config (version, name, currencies, logo paths),
     * the Input component for form validation, and the language file.
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('bkash', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Called when the gateway is first installed.
     * Creates the bkash_payment_attempts table if it doesn't exist.
     */
    public function install()
    {
        $this->createAttemptTable();
    }

    /**
     * Called when upgrading from an older version.
     * Re-runs CREATE TABLE IF NOT EXISTS to add any new columns/indexes.
     *
     * @param string $current_version The currently installed version (e.g., "1.0.0")
     */
    public function upgrade($current_version)
    {
        $this->createAttemptTable();
    }

    /**
     * Called when the gateway is uninstalled.
     * Intentionally does NOT drop the bkash_payment_attempts table —
     * the data serves as a permanent audit trail for completed payments.
     *
     * @param int  $gateway_id    The ID of the gateway being uninstalled
     * @param bool $last_instance Whether this is the last instance of this gateway type
     */
    public function uninstall($gateway_id, $last_instance)
    {
        // Retain payment attempts as transaction audit records.
    }

    /**
     * Sets the ISO 4217 currency code for this payment session.
     * Called by Blesta before buildProcess(). Only "BDT" is supported.
     *
     * @param string $currency The ISO 4217 currency code (e.g., "BDT")
     */
    public function setCurrency($currency)
    {
        $this->currency = strtoupper((string) $currency);
    }

    /**
     * Stores the gateway configuration (API credentials + mode).
     * Called by Blesta before any payment operation.
     *
     * @param array|null $meta Associative array with keys:
     *                         - app_key    (string) bKash App Key
     *                         - app_secret (string) bKash App Secret (encrypted at rest)
     *                         - username   (string) bKash API username
     *                         - password   (string) bKash API password (encrypted at rest)
     *                         - sandbox    (string) "true" or "false"
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta ?? [];
    }

    /**
     * Returns the list of meta field names that Blesta should encrypt in the database.
     * These fields contain sensitive credentials that must not be stored in plaintext.
     *
     * @return array Field names to encrypt: ['app_secret', 'password']
     */
    public function encryptableFields()
    {
        return ['app_secret', 'password'];
    }

    /**
     * Returns the list of supported currencies.
     * bKash only operates in Bangladeshi Taka (BDT).
     *
     * @return array ['BDT']
     */
    public function getCurrencies()
    {
        return ['BDT'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ADMIN SETTINGS (Blesta Admin → Settings → Payment Gateways → Manage)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Renders the admin settings form for configuring API credentials and mode.
     *
     * @param array|null $meta Current saved settings, or null on first install
     * @return string HTML markup for the settings form (rendered from settings.pdt)
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
     * Validates and processes the admin settings form submission.
     *
     * Validates that all four credential fields are non-empty and that the
     * sandbox field is either "true" or "false". If the sandbox checkbox is
     * unchecked, the browser won't submit it at all, so we default to "false".
     *
     * @param array $meta The submitted form data
     * @return array The validated meta data (passed back to the form on error,
     *               or saved to the database on success)
     */
    public function editSettings(array $meta)
    {
        // Build validation rules for each credential field
        $rules = [];
        foreach (['app_key', 'app_secret', 'username', 'password'] as $field) {
            $rules[$field] = [
                'valid' => [
                    'rule' => function ($value) use ($field) {
                        return is_string($value) && trim($value) !== '';
                    },
                    'message' => Language::_('Bkash.!error.' . $field . '.empty', true)
                ]
            ];
        }

        // Sandbox mode validation — must be exactly "true" or "false"
        $rules['sandbox'] = [
            'valid' => [
                'rule' => ['in_array', ['true', 'false']],
                'message' => Language::_('Bkash.!error.sandbox.valid', true)
            ]
        ];

        // IMPORTANT: When an HTML checkbox is unchecked, the browser does NOT submit
        // the field at all. We must default to "false" so validation passes and the
        // gateway correctly switches to live mode when the checkbox is unchecked.
        // This matches the pattern used by Blesta's official gateways (Paysera, SSLCommerz).
        if (!isset($meta['sandbox'])) {
            $meta['sandbox'] = 'false';
        }

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PAYMENT FLOW — CLIENT-FACING
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Builds the payment form shown on the client's invoice page.
     *
     * This method:
     *   1. Validates the currency is BDT and the amount/invoice allocations are sane.
     *   2. Creates a payment attempt row in the bkash_payment_attempts table.
     *   3. Obtains a bKash access token (cached or freshly granted).
     *   4. Calls the bKash /create API to get a paymentID and bkashURL.
     *   5. Returns HTML with a branded "Continue to bKash" button.
     *
     * On any failure, returns an error message HTML instead.
     *
     * @param array      $contact_info    Client contact info (must include 'client_id')
     * @param float      $amount          Total payment amount in BDT
     * @param array|null $invoice_amounts Array of invoices: [['id' => int, 'amount' => float], ...]
     * @param array|null $options         Options including 'description', 'return_url', 'recur'
     * @return string HTML markup — either the payment button or an error alert
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // ── Step 1: Validate currency ────────────────────────────────────────
        if ($this->currency !== 'BDT') {
            return $this->errorPage('Bkash.error.currency');
        }

        // ── Step 2: Validate and normalize the payment amount ────────────────
        $amount_paisa = $this->toPaisa($amount);
        $allocations = $this->normalizeInvoiceAmounts($invoice_amounts ?? []);
        if ($amount_paisa === null || $amount_paisa <= 0 || empty($allocations)) {
            return $this->errorPage('Bkash.error.invalid_amount');
        }

        // Verify that individual invoice allocations sum to the total amount
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

        // ── Step 3: Create a payment attempt record ──────────────────────────
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

        // ── Step 4: Build the HMAC-signed callback URL ───────────────────────
        try {
            $callback_url = $this->buildCallbackUrl($attempt, $options);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'callback_url_unavailable');
            $this->log('payment-attempt', 'Could not construct bKash callback URL.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 5: Obtain a bKash API access token ──────────────────────────
        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt['id'], 'token_unavailable');
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 6: Call bKash /create to initiate the payment ───────────────
        // mode=0011 → Checkout URL without agreement (no stored wallet token)
        $response = $this->request('/create', [
            'mode' => '0011',
            'payerReference' => 'C' . $attempt['client_id'],
            'callbackURL' => $callback_url,
            'amount' => $attempt['amount'],
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $attempt['merchant_invoice_number']
        ], $token);

        // Validate the /create response: must have statusCode=0000, a paymentID,
        // and a bkashURL pointing to an allowed bKash domain
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

        // ── Step 7: Bind the bKash paymentID and transition state ─────────────
        // State: created → redirected
        try {
            $this->bindPaymentId($attempt['id'], (string) $response->paymentID);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'payment_id_storage_failed');
            $this->log('payment-attempt', 'Could not save bKash payment ID.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        // ── Step 8: Render the checkout button ───────────────────────────────
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
     * Validates the incoming callback from bKash after the customer completes/cancels payment.
     *
     * This is the server-to-server callback handler. Blesta routes the bKash redirect
     * (GET request with ?attempt=...&sig=...&status=...&paymentID=...) to this method.
     *
     * Flow:
     *   1. Verify HMAC signature to ensure the callback URL hasn't been tampered with.
     *   2. Load and validate the payment attempt from the database.
     *   3. Handle already-completed or already-verified attempts (idempotency).
     *   4. If status != "success", mark as cancelled and show error to customer.
     *   5. Atomically claim the attempt (state → "executing") to prevent races.
     *   6. Call bKash /execute API. If that fails, fall back to /payment/status.
     *   7. Verify the response: amount, currency, transactionStatus, trxID.
     *   8. Mark the attempt as "verified" and return transaction data to Blesta.
     *
     * @param array $get  GET parameters from the bKash redirect
     * @param array $post POST parameters (unused — bKash uses GET callbacks)
     * @return array|null Transaction data array on success, null on failure
     *                    (errors set via $this->Input->setErrors())
     */
    public function validate(array $get, array $post)
    {
        return $this->processReturn($get, $post);
    }

    /**
     * Handles the client's return to Blesta after payment.
     *
     * This method is invoked when the client's browser returns to Blesta
     * (the return_url). Delegates to processReturn() so that cancellation
     * errors display on the invoice page, and payments execute securely.
     *
     * @param array $get  GET parameters from the return URL
     * @param array $post POST parameters (unused)
     * @return array|null Transaction data array on success, null on failure
     */
    public function success(array $get, array $post)
    {
        return $this->processReturn($get, $post);
    }

    /**
     * Unified handler for processing bKash callbacks and browser returns.
     *
     * Because bKash Tokenized Checkout uses a single callbackURL for success,
     * failure, and cancellation, this method handles all return paths uniformly:
     *   1. Validates the HMAC-SHA256 signature to prevent URL tampering.
     *   2. Verifies attempt ownership and tenant multi-tenancy boundaries.
     *   3. Detects customer cancellation or non-success status, marks attempt failed,
     *      and sets user-friendly cancellation notice for Blesta's invoice page.
     *   4. Verifies attempt expiration and exact bKash paymentID match.
     *   5. Handles idempotency (already completed or verified payments).
     *   6. Atomically claims the attempt to prevent double-execution race conditions.
     *   7. Executes the payment via bKash /execute (and /payment/status fallback).
     *   8. Verifies payment amount, currency, and provider transaction uniqueness.
     *   9. Marks attempt verified and returns approved transaction data to Blesta.
     *
     * @param array $get  GET parameters from the request
     * @param array $post POST parameters
     * @return array|null Transaction data array on success, null on failure
     */
    private function processReturn(array $get, array $post)
    {
        $attempt_key = (string) ($get['attempt'] ?? '');
        $signature = (string) ($get['sig'] ?? '');
        $payment_id = (string) ($get['paymentID'] ?? '');
        $status = strtolower(trim((string) ($get['status'] ?? '')));

        // ── Step 1: Verify HMAC signature ────────────────────────────────────
        if (!$this->validCallbackSignature($attempt_key, $signature)) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 2: Load and validate the payment attempt ────────────────────
        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt
            || (int) $attempt->company_id !== (int) Configure::get('Blesta.company_id')
            || (int) $attempt->gateway_id !== (int) $this->gateway_id
        ) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 3: Handle customer cancellation or non-success status ───────
        // bKash sends ?status=cancel or ?status=failure when the customer cancels/fails.
        // We evaluate this before strict paymentID checks because bKash may omit
        // or send an empty paymentID upon cancellation.
        if ($status !== 'success' || $payment_id === '') {
            $this->markAttemptFailed($attempt->id, 'customer_cancelled_or_failed');
            $this->Input->setErrors([
                'general' => [
                    'invalid' => Language::_('Bkash.error.cancelled', true)
                ]
            ]);
            return null;
        }

        // ── Step 4: Validate expiry and bKash payment ID match ───────────────
        if (strtotime($attempt->expires_at . ' UTC') < time()
            || !hash_equals((string) $attempt->bkash_payment_id, $payment_id)
        ) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 5: Handle idempotent re-delivery ────────────────────────────
        // If already completed, just return success
        if ($attempt->state === 'completed') {
            return $this->transactionResponse(
                $attempt,
                [],
                'approved',
                (string) $attempt->bkash_trx_id
            );
        }
        // If verified, check if Blesta already recorded the transaction
        if ($attempt->state === 'verified') {
            if ($this->getExistingBlestaTransaction($attempt)) {
                $this->markAttemptCompleted($attempt->id);
                $attempt = $this->getAttempt($attempt_key);
                return $this->transactionResponse(
                    $attempt,
                    [],
                    'approved',
                    (string) $attempt->bkash_trx_id
                );
            }
            if (strtotime($attempt->verified_at . ' UTC') > time() - self::CALLBACK_RETRY_DELAY_SECONDS) {
                $this->Input->setErrors($this->getCommonError('general'));
                return null;
            }
        }
        // If stuck in "executing" from a previous attempt, allow retry after delay
        if ($attempt->state === 'executing') {
            if (strtotime($attempt->updated_at . ' UTC') > time() - self::CALLBACK_RETRY_DELAY_SECONDS) {
                $this->Input->setErrors($this->getCommonError('general'));
                return null;
            }
            $this->resetAttemptForRetry($attempt->id);
        }

        // ── Step 6: Atomic claim — prevent double execution ──────────────────
        // Uses WHERE state IN ('redirected','verified') to ensure only one process
        // can transition to "executing" at a time.
        if (!$this->claimAttempt($attempt->id)) {
            // Another process already claimed it — check if it completed
            $attempt = $this->getAttempt($attempt_key);
            if ($attempt
                && in_array($attempt->state, ['verified', 'completed'], true)
                && $this->getExistingBlestaTransaction($attempt)
            ) {
                return $this->transactionResponse(
                    $attempt,
                    [],
                    'approved',
                    (string) $attempt->bkash_trx_id
                );
            }
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 7: Execute the payment via bKash API ────────────────────────
        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt->id, 'token_unavailable');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // Try /execute first; if it doesn't return Completed, check /payment/status
        $response = $this->request('/execute', ['paymentID' => $payment_id], $token);
        if (!$this->isCompletedPayment($response, $attempt)) {
            $response = $this->request('/payment/status', ['paymentID' => $payment_id], $token);
        }

        // Handle network/API failure — reset for retry
        if ($response === null) {
            $this->resetAttemptForRetry($attempt->id);
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 8: Verify the payment is genuinely completed ────────────────
        if (!$this->isCompletedPayment($response, $attempt)) {
            $this->markAttemptFailed($attempt->id, 'verification_failed');
            return $this->transactionResponse($attempt, [], 'declined');
        }

        // ── Step 9: Check for duplicate provider transaction IDs ─────────────
        // Prevents replay attacks where the same bKash trxID is used twice
        if ($this->providerTransactionExists((string) $response->trxID, $attempt->id)) {
            $this->markAttemptFailed($attempt->id, 'duplicate_provider_transaction');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // ── Step 10: Mark verified and return transaction to Blesta ───────────
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

        return $this->transactionResponse(
            $attempt,
            $first_completion ? $this->decodeInvoiceAmounts($attempt->invoice_amounts) : [],
            'approved',
            (string) $response->trxID
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DATABASE — Payment Attempt Management
    // These methods manage the bkash_payment_attempts table which tracks
    // the lifecycle of each payment from creation through completion.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Creates the bkash_payment_attempts table if it doesn't exist.
     *
     * Table columns:
     *  - id                     : Auto-increment primary key
     *  - company_id / gateway_id: Links to Blesta's multi-tenancy
     *  - client_id              : The paying customer
     *  - attempt_key            : 64-char hex (SHA-256 sized), used in callback URLs
     *  - bkash_payment_id       : bKash's paymentID from /create response
     *  - bkash_trx_id           : bKash's trxID from /execute response
     *  - merchant_invoice_number: Our unique invoice reference sent to bKash
     *  - amount / currency      : Payment amount and currency (always BDT)
     *  - invoice_amounts        : JSON array of [{id, amount}, ...] for Blesta invoices
     *  - state                  : State machine state (see class docblock)
     *  - failure_reason         : Human-readable failure reason (max 100 chars)
     *  - verified_at / completed_at / expires_at / created_at / updated_at: Timestamps (UTC)
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
     * Inserts a new payment attempt row in the "created" state.
     *
     * @param array $data Must contain: company_id, gateway_id, client_id, amount,
     *                    invoice_amounts (array), merchant_invoice_number
     * @return array The created attempt: [id, attempt_key, client_id, amount, merchant_invoice_number]
     * @throws RuntimeException If required context fields are invalid
     */
    private function createPaymentAttempt(array $data)
    {
        if ($data['company_id'] <= 0 || $data['gateway_id'] <= 0 || $data['client_id'] <= 0) {
            throw new RuntimeException('The payment context is incomplete.');
        }

        $attempt_key = bin2hex(random_bytes(32)); // 64 hex chars = 256-bit key
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
     * Stores the bKash paymentID and transitions state: created → redirected.
     *
     * @param int    $attempt_id The attempt row ID
     * @param string $payment_id The bKash paymentID from /create
     * @throws RuntimeException If the attempt couldn't be updated (wrong state or missing)
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
     * Fetches a payment attempt by its 64-char hex attempt_key.
     *
     * @param string $attempt_key The 64-character hex attempt key
     * @return object|null The attempt row as an object, or null if not found/invalid
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
     * Atomically claims an attempt for execution: redirected/verified → executing.
     *
     * This is the core concurrency guard. Only one process can successfully claim
     * an attempt because the UPDATE's WHERE clause includes the current state.
     * If two callbacks arrive simultaneously, only one will get affectedRows=1.
     *
     * @param int $attempt_id The attempt row ID
     * @return bool True if this process successfully claimed the attempt
     */
    private function claimAttempt($attempt_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', 'in', ['redirected', 'verified'])
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'executing',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        return $record->affectedRows($statement) === 1;
    }

    /**
     * Marks an attempt as verified after successful bKash /execute.
     * State: executing → verified.
     *
     * @param int    $attempt_id The attempt row ID
     * @param string $payment_id The bKash paymentID (verified against stored value)
     * @param string $trx_id     The bKash trxID from /execute response
     * @return bool True if this was the first verification (affectedRows=1)
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

        return $record->affectedRows($statement) === 1;
    }

    /**
     * Marks an attempt as completed (final success state).
     * State: verified → completed.
     *
     * @param int $attempt_id The attempt row ID
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
     * Marks an attempt as failed with a reason. Can be called from any state
     * except "completed" (completed payments are permanent).
     *
     * @param int    $attempt_id The attempt row ID
     * @param string $reason     Human-readable failure reason (truncated to 100 chars)
     */
    private function markAttemptFailed($attempt_id, $reason)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', '!=', 'completed')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'failed',
                'failure_reason' => substr($reason, 0, 100),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Resets a stuck "executing" attempt back to "redirected" so it can be retried.
     * Used when /execute times out or the process crashes mid-execution.
     *
     * @param int $attempt_id The attempt row ID
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
     * Checks if a bKash trxID has already been used by a different attempt.
     * Prevents replay attacks where the same trxID is submitted for multiple payments.
     *
     * @param string $trx_id     The bKash trxID to check
     * @param int    $attempt_id The current attempt (excluded from the check)
     * @return bool True if the trxID exists on another attempt
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

    // ══════════════════════════════════════════════════════════════════════════
    // BLESTA TRANSACTION LOOKUP
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Looks up an existing Blesta transaction by the bKash paymentID.
     * Used to check if validate() has already recorded a successful transaction.
     *
     * @param object $attempt The attempt row object
     * @return object|null The Blesta transaction object, or null if not found
     */
    private function getExistingBlestaTransaction($attempt)
    {
        Loader::loadModels($this, ['Transactions']);

        return $this->Transactions->getByTransactionId(
            (string) $attempt->bkash_payment_id,
            (int) $attempt->client_id,
            (int) $attempt->gateway_id
        );
    }

    /**
     * Verifies that Blesta has applied the payment to the correct invoices
     * with the correct amounts. Prevents partial or misapplied payments.
     *
     * @param object $attempt     The attempt row (contains invoice_amounts JSON)
     * @param object $transaction The Blesta transaction object
     * @return bool True if all expected invoice applications match
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

    // ══════════════════════════════════════════════════════════════════════════
    // BKASH API — Token Management & HTTP Requests
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Obtains a bKash API access token, using cache when available.
     *
     * The token is cached using Blesta's cache system with a key derived from
     * the environment (sandbox/live) and app_key. The TTL is the lesser of
     * TOKEN_TTL_SECONDS and the token's reported expires_in minus a 60s buffer.
     *
     * @return string|null The id_token for use in Authorization headers, or null on failure
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

        // Request a fresh token from bKash /token/grant
        // The username and password go in custom headers, not the JSON body
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

        // Cache the token with a safe TTL
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
     * Sends an HTTPS POST request to the bKash Tokenized Checkout API.
     *
     * Features:
     *   - Logs both request and response via Blesta's gateway logging
     *   - Redacts sensitive fields (app_secret, password) before logging
     *   - Enforces SSL peer verification (CURLOPT_SSL_VERIFYPEER)
     *   - Restricts to HTTPS protocol only (CURLOPT_PROTOCOLS)
     *   - 10s connect timeout, 30s total timeout
     *
     * @param string $endpoint      API endpoint path (e.g., "/create", "/execute")
     * @param array  $payload       Request body as associative array (JSON-encoded)
     * @param string|null $token    Bearer token for Authorization header
     * @param array  $extra_headers Additional HTTP headers (e.g., username/password for /token/grant)
     * @return object|null Decoded JSON response object, or null on failure
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

        // Log the request (with secrets redacted)
        $log_payload = $payload;
        foreach (['app_secret', 'password'] as $secret_field) {
            if (isset($log_payload[$secret_field])) {
                $log_payload[$secret_field] = '[redacted]';
            }
        }
        $this->log($url, json_encode($log_payload), 'input', true);

        // Execute the HTTPS request
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

        // Parse and log the response (only safe fields — no raw body)
        $response = json_decode($raw);
        $valid_json = json_last_error() === JSON_ERROR_NONE && is_object($response);
        $log_response = [
            'http_status' => $http_code,
            'status_code' => ($response->statusCode ?? null),
            'transaction_status' => ($response->transactionStatus ?? null),
            'payment_id' => ($response->paymentID ?? null),
            'transaction_id' => ($response->trxID ?? null),
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

    // ══════════════════════════════════════════════════════════════════════════
    // RESPONSE VALIDATION & HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Checks if a bKash API response indicates success (statusCode "0000").
     *
     * @param object|null $response The decoded JSON response
     * @return bool True if statusCode is "0000"
     */
    private function isSuccessfulApiResponse($response)
    {
        return is_object($response) && (string) ($response->statusCode ?? '') === '0000';
    }

    /**
     * Validates that a bKash payment response represents a genuinely completed payment.
     *
     * Checks:
     *   - statusCode is "0000"
     *   - transactionStatus is "Completed"
     *   - currency is BDT
     *   - trxID is present
     *   - amount matches the expected amount (compared in paisa to avoid float issues)
     *
     * @param object|null $response The bKash /execute or /payment/status response
     * @param object      $attempt  The payment attempt row
     * @return bool True if the payment is verified as completed with correct amount
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

        // Compare amounts in paisa (integer) to avoid floating-point precision issues
        $actual_paisa = $this->toPaisa($response->amount ?? null);
        $expected_paisa = $this->toPaisa($attempt->amount);

        return $actual_paisa !== null
            && $expected_paisa !== null
            && $actual_paisa === $expected_paisa;
    }

    /**
     * Builds the standard transaction response array that Blesta expects
     * from validate() and success().
     *
     * @param object $attempt  The payment attempt row
     * @param array  $invoices Invoice allocations: [['id' => int, 'amount' => string], ...]
     * @param string $status   Transaction status: 'approved', 'declined', etc.
     * @param string|null $trx_id Optional bKash trxID as the reference_id
     * @return array Blesta transaction response array
     */
    private function transactionResponse($attempt, array $invoices, $status, $trx_id = null)
    {
        return [
            'client_id' => (int) $attempt->client_id,
            'amount' => (string) $attempt->amount,
            'currency' => 'BDT',
            'status' => $status,
            'transaction_id' => (string) $attempt->bkash_payment_id,
            'reference_id' => $trx_id ?: (string) $attempt->bkash_trx_id,
            'invoices' => $invoices
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CALLBACK URL & SECURITY
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Builds the callback URL that bKash will redirect to after payment.
     *
     * The URL includes:
     *   - The Blesta gateway callback base URL + company_id + gateway name
     *   - The attempt_key (for looking up the payment attempt)
     *   - An HMAC-SHA256 signature (prevents URL tampering)
     *
     * In live mode, enforces that the callback URL uses HTTPS and is not
     * a local/private address (bKash requires a publicly reachable URL).
     *
     * @param array $attempt The payment attempt data (must include 'attempt_key')
     * @return string The complete callback URL with signature
     * @throws RuntimeException If the callback URL is not configured or not HTTPS (live mode)
     */
    private function buildCallbackUrl(array $attempt, array $options = null)
    {
        $return_url = !empty($options['return_url']) ? trim((string) $options['return_url']) : '';
        if ($return_url !== '') {
            $base_url = $return_url;
        } else {
            $gw_callback = rtrim((string) Configure::get('Blesta.gw_callback_url'), '/');
            if ($gw_callback === '' || (int) Configure::get('Blesta.company_id') <= 0) {
                throw new RuntimeException('The gateway callback URL is not configured.');
            }
            $base_url = $gw_callback . '/' . (int) Configure::get('Blesta.company_id') . '/bkash/';
        }

        // In live mode, enforce public HTTPS — bKash won't call back to localhost
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

        $signature = hash_hmac(
            'sha256',
            $attempt['attempt_key'],
            hash('sha256', $this->metaValue('app_secret'), true)
        );

        $separator = (strpos($base_url, '?') !== false) ? '&' : '?';

        return $base_url . $separator . http_build_query([
            'attempt' => $attempt['attempt_key'],
            'sig' => $signature
        ]);
    }

    /**
     * Validates an HMAC-SHA256 callback signature to ensure the URL hasn't been tampered with.
     *
     * @param string $attempt_key The 64-char hex attempt key from the URL
     * @param string $signature   The HMAC signature from the URL
     * @return bool True if the signature is valid
     */
    private function validCallbackSignature($attempt_key, $signature)
    {
        // Validate format first to prevent timing attacks on malformed input
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

        // hash_equals() prevents timing-based side-channel attacks
        return hash_equals($expected, $signature);
    }

    /**
     * Validates that a checkout URL returned by bKash points to an allowed domain.
     * Prevents phishing attacks where a compromised API returns a malicious URL.
     *
     * Allowed hosts:
     *   - The API base URL's host (tokenized.sandbox.bka.sh or tokenized.pay.bka.sh)
     *   - sandbox.payment.bkash.com (sandbox) or payment.bkash.com (live)
     *
     * @param string|null $url The bkashURL from the /create response
     * @return bool True if the URL is HTTPS and points to an allowed bKash domain
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

    // ══════════════════════════════════════════════════════════════════════════
    // UTILITY — Configuration & Data Formatting
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns the bKash API base URL based on the current mode (sandbox vs live).
     *
     * @return string The full API base URL
     */
    private function baseUrl()
    {
        return $this->isSandbox() ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * Checks if the gateway is in sandbox (test) mode.
     *
     * @return bool True if sandbox mode is enabled
     */
    private function isSandbox()
    {
        return $this->metaValue('sandbox') === 'true';
    }

    /**
     * Safely retrieves a scalar value from the gateway meta/settings.
     *
     * @param string $key The meta field name (e.g., 'app_key', 'sandbox')
     * @return string The value as a string, or empty string if not set/not scalar
     */
    private function metaValue($key)
    {
        return isset($this->meta[$key]) && is_scalar($this->meta[$key])
            ? (string) $this->meta[$key]
            : '';
    }

    /**
     * Normalizes invoice amounts from Blesta's various formats into a consistent
     * array of [{id: int, amount: string}, ...].
     *
     * Blesta may pass invoice_amounts as:
     *   - Array of arrays: [['id' => 1, 'amount' => '10.00'], ...]
     *   - Associative: [1 => '10.00', 2 => '5.00', ...]
     *
     * @param array $invoice_amounts Raw invoice amounts from Blesta
     * @return array Normalized array, or empty array on validation failure
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
     * Decodes the JSON-encoded invoice_amounts column from the database.
     *
     * @param string $invoice_amounts JSON string from the invoice_amounts column
     * @return array Decoded array of [{id, amount}, ...], or empty array on failure
     */
    private function decodeInvoiceAmounts($invoice_amounts)
    {
        $decoded = json_decode((string) $invoice_amounts, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts a BDT amount to paisa (integer) for precise arithmetic.
     *
     * Examples:
     *   "100.50" → 10050
     *   "1"      → 100
     *   "0.01"   → 1
     *
     * Returns null for invalid amounts (negative, too many decimals, non-numeric).
     * Uses string manipulation instead of float arithmetic to avoid precision issues.
     *
     * @param mixed $amount The amount as a string or number
     * @return int|null The amount in paisa, or null if invalid
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
     * Formats a paisa amount back to a BDT string with exactly 2 decimal places.
     *
     * Examples:
     *   10050 → "100.50"
     *   100   → "1.00"
     *   1     → "0.01"
     *
     * @param int $amount The amount in paisa
     * @return string The formatted BDT amount (e.g., "100.50")
     */
    private function formatPaisa($amount)
    {
        $amount = (int) $amount;

        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Renders the process.pdt template with an error message.
     * Used by buildProcess() to show a branded error alert to the customer.
     *
     * @param string $language_key The language key for the error message (e.g., 'Bkash.error.gateway')
     * @return string HTML markup with the error alert
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
