# bKash Tokenized Checkout — Blesta Non-Merchant Gateway

> **Accept BDT invoice payments using bKash Tokenized Checkout inside Blesta billing.**
> Compatible with **Blesta 5.x / 6.x** · Supports **bKash Tokenized API v1.2.0-beta**

---

## Table of Contents

- [Overview](#overview)
  - [Merchant vs. Non-Merchant Gateway Architecture](#merchant-vs-non-merchant-gateway-architecture)
  - [Tokenized Checkout Flow (`mode=0011`)](#tokenized-checkout-flow-mode0011)
- [Features](#features)
- [Requirements](#requirements)
- [File Structure](#file-structure)
- [Installation](#installation)
  - [Method 1: Manual Installation (Recommended)](#method-1-manual-installation-recommended)
  - [Method 2: Composer Installation](#method-2-composer-installation)
- [Configuration](#configuration)
- [bKash Sandbox Testing Guide & Credentials](#bkash-sandbox-testing-guide--credentials)
  - [Official Sandbox API Credentials](#official-sandbox-api-credentials)
  - [Regular Active Customer Test Wallets](#regular-active-customer-test-wallets)
  - [End-to-End Sandbox Testing Walkthrough](#end-to-end-sandbox-testing-walkthrough)
- [Architectural Lifecycles](#architectural-lifecycles)
  - [High-Level Payment & Cancellation Flow](#high-level-payment--cancellation-flow)
  - [1. Payment Initiation Lifecycle (`buildProcess()`) — 9 Steps](#1-payment-initiation-lifecycle-buildprocess--9-steps)
  - [2. Callback Validation & Execution Lifecycle (`validate()`) — 10 Steps](#2-callback-validation--execution-lifecycle-validate--10-steps)
  - [3. Client Return Security (`success()`)](#3-client-return-security-success)
  - [Cancellation Flow Comparison (Paysera Pattern)](#cancellation-flow-comparison-paysera-pattern)
- [Database Schema & Audit Preservation](#database-schema--audit-preservation)
  - [Column & Index Reference](#column--index-reference)
  - [State Machine Reference](#state-machine-reference)
  - [Audit Preservation on Uninstall](#audit-preservation-on-uninstall)
- [Code Architecture, Constants & Key Methods](#code-architecture-constants--key-methods)
  - [Gateway Constants](#gateway-constants)
  - [Public Blesta Contract Methods](#public-blesta-contract-methods)
  - [Private Internal Engine Methods](#private-internal-engine-methods)
  - [View Templates (`.pdt`)](#view-templates-pdt)
- [Security Architecture](#security-architecture)
- [Step-by-Step Developer Guide](#step-by-step-developer-guide)
  - [How to Modify Settings or Add a New Field](#how-to-modify-settings-or-add-a-new-field)
  - [How to Add a New Language Translation (e.g., `bn_bd`)](#how-to-add-a-new-language-translation-eg-bn_bd)
- [Error Reference](#error-reference)
- [Troubleshooting Guide](#troubleshooting-guide)
- [bKash API Quick Reference](#bkash-api-quick-reference)

---

## Overview

This module integrates **bKash Tokenized Checkout** into Blesta as a **Non-Merchant Gateway**. Customers pay BDT invoices without entering mobile banking PINs or OTP credentials on your server — they are redirected to bKash's official hosted payment portal, authorize the transaction with an OTP and wallet PIN, and are securely returned to Blesta.

### Merchant vs. Non-Merchant Gateway Architecture

In Blesta's payment architecture, gateways are divided into two paradigms:
- **Merchant Gateways** capture payment credentials directly on the billing server, placing the merchant infrastructure directly within PCI-DSS and financial compliance scope.
- **Non-Merchant Gateways (`NonmerchantGateway`)** offload all sensitive authentication (wallet numbers, OTPs, and 5-digit PIN inputs) to the payment provider's hosted environment. By extending `NonmerchantGateway`, this module removes sensitive credential handling from the Blesta server while maintaining strict server-to-server payment verification.

### Tokenized Checkout Flow (`mode=0011`)

The gateway implements the **Tokenized Checkout (Without Agreement)** flow (`mode=0011`): a direct, one-time tokenized checkout experience that does not require pre-existing customer subscription agreements or stored wallet tokens.

---

## Features

| Feature | Description |
|---|---|
| **Payment Flow** | Hosted bKash Checkout URL (`mode=0011`, `intent=sale`) with instant return |
| **Blesta 6 Theme Support** | Dual light/dark SVG logos matching official Blesta 6 theme standards (Paysera pattern) |
| **Sub-unit Integer Math** | BDT-only processing using integer paisa calculations to eliminate IEEE 754 floating-point precision bugs |
| **Sandbox / Test Mode** | Seamless toggle in admin settings with built-in support for official bKash test credentials |
| **Token Caching** | High-performance OAuth Bearer token caching via Blesta's `CacheFactory` with a 3000s safety buffer |
| **Race Condition Protection** | Atomic DB row-level state locking (`claimAttempt()`) preventing double-execution between concurrent webhooks and browser redirects |
| **Replay & Tampering Guard** | Timing-safe (`hash_equals`) HMAC-SHA256 callback signatures and unique provider `trxID` anti-replay validation |
| **SSRF & Open Redirect Guard** | Strict hostname whitelisting (`isAllowedCheckoutUrl()`) before redirecting clients to `bkashURL` |
| **Network Drop Resilience** | Automatic fallback from `/execute` to `/payment/status` to prevent charging customers without recording payments |
| **Cancellation Handling** | Clean return handling with user-friendly notices when a customer cancels (`?status=cancel` or `?status=failure`) |
| **Encrypted Storage & Log Scrubbing** | Sensitive fields (`app_secret`, `password`) encrypted at rest by Blesta and scrubbed (`[redacted]`) from gateway logs |
| **Audit Trail Persistence** | Dedicated `bkash_payment_attempts` table preserved even across module uninstalls for financial compliance |

---

## Requirements

| Requirement | Minimum Version | Notes |
|---|---|---|
| **Blesta** | 5.0+ / 6.0+ | Fully compatible with Blesta 5.x and 6.x theme engines |
| **PHP** | 7.4, 8.0, 8.1, 8.2, 8.3 | Uses strict comparisons and modern cryptographic primitives |
| **PHP Extensions** | `curl`, `json`, `openssl` | Standard on modern PHP hosting environments |
| **SSL / TLS** | HTTPS required for Live | bKash requires public HTTPS URLs for production callbacks |
| **bKash Account** | Merchant Account | Tokenized Checkout API (`v1.2.0-beta`) credentials from bKash |

---

## File Structure

```text
bkash/
├── bkash.php                     # Main gateway class — implements NonmerchantGateway
├── composer.json                 # Composer package definition (blesta-gateway-nonmerchant)
├── config.json                   # Gateway metadata, supported currencies, and logo paths
├── LICENSE                       # MIT License
├── README.md                     # Comprehensive developer & operator documentation
├── language/
│   └── en_us/
│       └── bkash.php             # English language definitions, error strings, & i18n guide
└── views/
    └── default/
        ├── images/
        │   ├── logo.svg          # Vector logo optimized for Light theme (dark wordmark)
        │   └── logo-dark.svg     # Vector logo optimized for Dark theme (white wordmark)
        ├── process.pdt           # Client invoice payment button & error state view
        └── settings.pdt          # Admin gateway configuration view with CSRF & secret masking
```

---

## Installation

### Method 1: Manual Installation (Recommended)

1. Upload or copy the `bkash/` directory into your Blesta installation:
   ```bash
   /path/to/blesta/components/gateways/nonmerchant/bkash/
   ```
2. Ensure directory and file permissions allow the web server to read the files (e.g., `chmod -R 755 bkash`).
3. Log in to your Blesta Admin area.
4. Navigate to **Settings → Payment Gateways**.
5. Click on the **Available** tab, locate **bKash**, and click **Install**.
6. Enter your credentials, configure your gateway mode, and click **Update Settings**.

### Method 2: Composer Installation

```bash
composer require custom/blesta-bkash
```

---

## Configuration

In Blesta Admin, navigate to **Settings → Payment Gateways → Installed → bKash → Manage**.

| Setting Field | Purpose | Environment / Source |
|---|---|---|
| **App Key** | Public merchant identifier (`X-APP-Key`) for bKash API calls | bKash Merchant Portal |
| **App Secret** | Secret key used for HMAC callback signing and `/token/grant` requests | bKash Merchant Portal (encrypted at rest by Blesta) |
| **API Username** | Username sent in HTTP headers during token acquisition | bKash Merchant Portal |
| **API Password** | Password sent in HTTP headers during token acquisition | bKash Merchant Portal (encrypted at rest by Blesta) |
| **Sandbox Mode (Test)** | When checked, connects to the sandbox API. When unchecked, connects to live production | Toggle switch in gateway settings |

> [!IMPORTANT]
> Because `app_secret` and `password` are declared in `encryptableFields()` and encrypted at rest by Blesta upon saving, they are **never redisplayed** in plaintext inside `views/default/settings.pdt`. When updating settings in the admin area, re-enter your secret and password if modifying credentials.

---

## bKash Sandbox Testing Guide & Credentials

The bKash sandbox simulates live payments without debiting real funds. Use the official test credentials and customer test wallets below.

### Official Sandbox API Credentials

| Parameter | Sandbox Value |
|---|---|
| **Base URL** | `https://tokenized.sandbox.bka.sh/v1.2.0-beta` |
| **API Checkout Endpoint** | `https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout` |
| **API Documentation** | [bKash Checkout URL Overview](https://developer.bka.sh/docs/checkout-url-process-overview) |
| **API Username (`username`)** | `01770618567` |
| **API Password (`password`)** | `D7DaC<*E*eG` |
| **App Key (`app_key`)** | `0vWQuCRGiUX7EPVjQDr0EUAYtc` |
| **App Secret (`app_secret`)** | `jcUNPBgbcqEDedNKdvE4G1cAK7D3hCjmJccNPZZBq96QIxxwAMEx` |

### Regular Active Customer Test Wallets

Use any of the following simulated customer wallet numbers during checkout:

- `01929918378`
- `01770618575`
- `01619777283`
- `01877722345`

| Verification Step | Test Value |
|---|---|
| **OTP (One-Time Password)** | `123456` |
| **bKash Wallet PIN** | `12121` |

### End-to-End Sandbox Testing Walkthrough

1. Set the gateway to **Sandbox Mode** in Blesta Admin with the credentials above.
2. In the Client Portal, create or open an invoice in **BDT** currency.
3. Select **bKash** and click **Pay with bKash** / **Continue to bKash**.
4. You will be redirected to `sandbox.payment.bkash.com`.
5. Enter one of the test wallet numbers (e.g., `01929918378`).
6. Enter OTP: `123456`.
7. Enter PIN: `12121`.
8. Once completed, bKash invokes the Blesta callback URL, `validate()` verifies the HMAC signature, executes `/execute`, records the transaction, and `success()` renders the paid invoice confirmation to the client.

---

## Architectural Lifecycles

### High-Level Payment & Cancellation Flow

```text
[Customer on Invoice Page]
          │
          ▼
   buildProcess() (9-Step Initiation)
          │ ── 1-2. Enforce BDT currency & convert amount to integer paisa
          │ ── 3-4. Provision row in bkash_payment_attempts (state: "created") & build HMAC callbackURL
          │ ── 5-6. Fetch cached OAuth token (/token/grant) & call /create (mode=0011, intent=sale)
          │ ── 7-8. Verify bkashURL hostname (SSRF guard) & transition state: "created" -> "redirected"
          ▼
[Render views/default/process.pdt with "Continue to bKash" button]
          │
          ▼ Customer clicks button
[bKash Hosted Portal: sandbox.payment.bkash.com or payment.bkash.com]
          │
     ┌────┴──────────────────────────────────┐
     ▼                                       ▼
[Customer Completes Payment]         [Customer Clicks "Close" / "Cancel"]
     │                                       │
     ▼                                       ▼
Redirect to Callback URL             Redirect to Callback URL
(?status=success&paymentID=...)      (?status=cancel&paymentID=...)
     │                                       │
     ▼                                       ▼
 validate() (10-Step Verification)       validate() (Early Cancellation Exit)
     │ ── 1-2. Verify HMAC (hash_equals)     │ ── Verify HMAC & ownership
     │         & multi-tenant ownership      │ ── Detect ?status=cancel / failure
     │ ── 3.   Idempotency check             │ ── Mark attempt "failed"
     │ ── 5.   Atomic lock (claimAttempt)    │ ── Set Bkash.error.cancelled
     │ ── 6-7. Call /execute (with           │ ── Return null
     │         /payment/status fallback)     ▼
     │ ── 8-9. Verify statusCode === '0000', [Client Returns to Invoice Page]
     │         exact paisa, & unique trxID   │
     │ ── 10.  Transition state -> "verified"▼
     ▼                                   success()
[Blesta Credits Invoice]                     │ ── Read-only state check
     │                                       │ ── Displays cancellation notice
     ▼                                       ▼
 success() (Read-Only Return)            [Invoice Remains Unpaid & Clean]
     │ ── Verify server-side "verified" state
     │ ── Transition state: "verified" -> "completed"
     ▼
[Paid Invoice Confirmation]
```

### 1. Payment Initiation Lifecycle (`buildProcess()`) — 9 Steps

When a customer initiates checkout on an invoice, `buildProcess()` executes 9 sequential steps:

1. **Currency Guard**: Enforces `BDT`-only processing, rejecting unsupported currencies before making network calls.
2. **Sub-unit Integer Math**: Converts decimal currency amounts into integer paisa ($1\text{ BDT} = 100\text{ paisa}$) via `toPaisa()` and `formatPaisa()`, avoiding IEEE 754 floating-point rounding discrepancies.
3. **Attempt Record Creation**: Provisions a local audit row in `bkash_payment_attempts` in the `created` state with a cryptographically random 64-character `attempt_key`, a 30-minute expiration window (`ATTEMPT_TTL_SECONDS = 1800`), and a unique `merchant_invoice_number`.
4. **HMAC Callback Construction**: Generates a tamper-proof callback URL signed with HMAC-SHA256 (`attempt_key` keyed by `app_secret`) instructing bKash where to return the customer.
5. **Token Acquisition**: Retrieves a cached OAuth Bearer token via `getAccessToken()` or requests a fresh token from `/token/grant`.
6. **API `/create` Call**: Sends the payment creation payload (`mode: '0011'`, `intent: 'sale'`, `payerReference`, `amount`, `currency: 'BDT'`, `merchantInvoiceNumber`, `callbackURL`).
7. **SSRF & Hostname Validation**: Validates the returned `bkashURL` via `isAllowedCheckoutUrl()` to guarantee the hostname strictly matches official bKash domains before redirecting the user.
8. **State Transition**: Updates the local attempt state from $\text{created} \to \text{redirected}$ and stores the provider's `paymentID`.
9. **UI Rendering**: Renders `views/default/process.pdt` to present the official bKash checkout button (or an inline error notice if initialization failed).

### 2. Callback Validation & Execution Lifecycle (`validate()`) — 10 Steps

When bKash redirects the user or sends a callback to Blesta, `validate()` executes a 10-step defense-in-depth verification pipeline:

1. **Step 1 — HMAC Verification**: Uses timing-safe comparison (`hash_equals()`) to validate the callback signature against the `attempt_key`, preventing URL forgery and timing side-channel attacks.
2. **Step 2 — Multi-Tenant Ownership**: Verifies that the payment attempt belongs to the active `company_id` and `gateway_id`, and checks that the session has not expired beyond `ATTEMPT_TTL_SECONDS`.
3. **Step 3 — Idempotency**: Checks if the attempt is already `verified` or `completed` to prevent double-crediting invoices when callbacks or browser refreshes re-deliver.
4. **Step 4 — Cancellation Detection**: Identifies `?status=cancel` or `?status=failure` query parameters early, transitions the attempt to `failed`, and registers user-facing cancellation notices (`Bkash.error.cancelled`).
5. **Step 5 — Atomic Claiming**: Acquires an atomic row-level database lock via `claimAttempt()` (`WHERE state IN ('redirected', 'verified')` transitioning to `executing`). Also supports lock recovery if a previous execution hung beyond `CALLBACK_RETRY_DELAY_SECONDS` (300s).
6. **Step 6 — API Execution (`/execute`)**: Calls bKash's `/execute` endpoint to finalize and capture the authorized transaction.
7. **Step 7 — Status Fallback (`/payment/status`)**: If `/execute` encounters a network timeout or ambiguous response, queries `/payment/status` as a fallback so a customer is never charged without Blesta recording the transaction.
8. **Step 8 — Deep Verification**: Verifies `statusCode === '0000'`, `transactionStatus === 'Completed'`, `currency === 'BDT'`, and confirms that the captured amount matches the expected integer paisa amount.
9. **Step 9 — Anti-Replay Defense**: Calls `providerTransactionExists()` and relies on the unique `uq_bkash_trx_id` index to verify that the returned `trxID` has never been used on any other payment attempt.
10. **Step 10 — Finalization**: Marks the attempt `verified`, records `bkash_trx_id` and `verified_at`, and returns the structured `approved` transaction array to credit Blesta invoices.

### 3. Client Return Security (`success()`)

`success()` is designed as a **read-only defense-in-depth handler**:
- It **never** independently calls `/execute` or captures funds, protecting the system against client-side browser redirect manipulation.
- It inspects the local attempt row to verify that server-side `validate()` has already transitioned the payment to `verified`.
- Once confirmed, it transitions the attempt state from $\text{verified} \to \text{completed}$ and renders the final invoice confirmation screen.

### Cancellation Flow Comparison (Paysera Pattern)

Following the official Blesta `gateway-paysera` and `gateway-sslcommerz` patterns:
- When a customer clicks "Close" or "Cancel" on the bKash screen, bKash redirects back with `?status=cancel`.
- `validate()` marks the local attempt as `failed` without throwing unhandled exceptions and registers `Bkash.error.cancelled`.
- `success()` intercepts the unapproved state and displays a clean alert explaining that **no funds were deducted and their invoice remains open**, allowing the customer to retry immediately.

---

## Database Schema & Audit Preservation

The module creates and maintains `bkash_payment_attempts` via `createAttemptTable()` to track the full state machine of every checkout session:

```sql
CREATE TABLE IF NOT EXISTS `bkash_payment_attempts` (
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`              INT UNSIGNED NOT NULL,
    `gateway_id`              INT UNSIGNED NOT NULL,
    `client_id`               INT UNSIGNED NOT NULL,
    `attempt_key`             CHAR(64) NOT NULL,
    `bkash_payment_id`        VARCHAR(100) NULL,
    `bkash_trx_id`            VARCHAR(100) NULL,
    `merchant_invoice_number` VARCHAR(100) NOT NULL,
    `amount`                  DECIMAL(14,2) NOT NULL,
    `currency`                CHAR(3) NOT NULL,
    `invoice_amounts`         TEXT NOT NULL,
    `state`                   VARCHAR(16) NOT NULL DEFAULT 'created',
    `failure_reason`          VARCHAR(100) NULL,
    `verified_at`             DATETIME NULL,
    `completed_at`            DATETIME NULL,
    `expires_at`              DATETIME NOT NULL,
    `created_at`              DATETIME NOT NULL,
    `updated_at`              DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bkash_attempt_key` (`attempt_key`),
    UNIQUE KEY `uq_bkash_payment_id` (`bkash_payment_id`),
    UNIQUE KEY `uq_bkash_trx_id` (`bkash_trx_id`),
    KEY `idx_bkash_attempt_state_expiry` (`state`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Column & Index Reference

| Column / Index | Purpose |
|---|---|
| `company_id` / `gateway_id` / `client_id` | Multi-tenant isolation ensuring callbacks cannot cross company or client boundaries |
| `attempt_key` (`uq_bkash_attempt_key`) | High-entropy 64-character token identifying the checkout attempt in callback URLs |
| `bkash_payment_id` (`uq_bkash_payment_id`) | Unique bKash session identifier (`paymentID`) returned by `/create` |
| `bkash_trx_id` (`uq_bkash_trx_id`) | Unique final ledger transaction ID (`trxID`) returned by `/execute`; enforces anti-replay at the DB level |
| `invoice_amounts` | Serialized map of Blesta invoice IDs and their respective allocated payment amounts |
| `idx_bkash_attempt_state_expiry` | Composite index optimizing state lookups and TTL expiration checks |

### State Machine Reference

| State | Description | Next Permitted States |
|---|---|---|
| `created` | Initial attempt row provisioned in Blesta before calling bKash `/create` | `redirected`, `failed` |
| `redirected` | bKash `paymentID` received and customer presented with validated `bkashURL` | `executing`, `failed` |
| `executing` | Callback received; row atomically locked by server process to invoke `/execute` | `verified`, `redirected` (on lock retry), `failed` |
| `verified` | bKash `/execute` (or `/payment/status`) confirmed `Completed` and passed deep verification | `completed`, `failed` |
| `completed` | Client return confirmed via `success()` after Blesta credited the invoice (Final Success) | *Terminal* |
| `failed` | Customer cancelled, attempt expired, or signature/amount verification failed | *Terminal* |

### Audit Preservation on Uninstall

When `uninstall($gateway_id, $last_instance)` is triggered in Blesta, the module **intentionally retains** the `bkash_payment_attempts` table. Preserving historical payment attempt records, `trxID` references, and failure logs is critical for financial dispute resolution, chargeback auditing, and tax compliance.

---

## Code Architecture, Constants & Key Methods

All core gateway logic resides in `bkash.php`.

### Gateway Constants

| Constant | Value | Purpose |
|---|---|---|
| `ATTEMPT_TTL_SECONDS` | `1800` (30 mins) | Maximum time window a checkout attempt remains valid after creation |
| `TOKEN_TTL_SECONDS` | `3000` (50 mins) | Cache lifetime for bKash OAuth tokens (10-minute safety buffer before bKash's 3600s expiry) |
| `CALLBACK_RETRY_DELAY_SECONDS` | `300` (5 mins) | Lock-recovery timeout allowing a stuck `executing` state to be safely retried |

### Public Blesta Contract Methods

- `__construct()`: Loads `config.json`, initializes the `Input` component, and loads localized language strings.
- `install()` / `upgrade($current_version)`: Executes idempotent `createAttemptTable()` schema initialization.
- `uninstall($gateway_id, $last_instance)`: Cleans up module state while preserving the `bkash_payment_attempts` audit table.
- `setCurrency($currency)`: Configures the active transaction currency (enforced to `BDT`).
- `setMeta(array $meta)`: Injects admin-configured gateway credentials and sandbox settings.
- `encryptableFields()`: Declares `['app_secret', 'password']` for automatic AES-256 encryption at rest by Blesta.
- `getCurrencies()`: Declares `['BDT']` as the sole supported currency.
- `getSettings(array $meta)`: Renders the admin configuration view (`views/default/settings.pdt`).
- `editSettings(array $meta)`: Validates admin form inputs and normalizes unchecked checkbox states to `'false'`.
- `buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)`: Runs the 9-step payment initiation lifecycle and renders `views/default/process.pdt`.
- `validate(array $get, array $post)`: Runs the 10-step server-side callback verification and payment capture pipeline.
- `success(array $get, array $post)`: Runs the read-only client return check and transitions verified attempts to `completed`.

### Private Internal Engine Methods

- `getAccessToken()`: Manages OAuth Bearer token retrieval and caching via Blesta's `CacheFactory`.
- `request($endpoint, array $payload, $token = null, array $extra_headers = [])`: Executes TLS 1.2+ cURL requests to bKash and scrubs sensitive fields (`[redacted]`) before writing to Blesta's Gateway Log.
- `claimAttempt($attempt_id)`: Atomic SQL `UPDATE` mutex preventing concurrent callback race conditions.
- `toPaisa($amount)` / `formatPaisa($amount)`: Integer sub-unit math helpers preventing floating-point rounding bugs.
- `isAllowedCheckoutUrl($url)`: SSRF and open-redirect validator ensuring checkout URLs belong exclusively to authorized bKash hostnames.
- `providerTransactionExists($trx_id, $exclude_attempt_id)`: Anti-replay query verifying `trxID` uniqueness across all attempts.

### View Templates (`.pdt`)

- **`views/default/settings.pdt`**: Uses Blesta view helpers (`$this->Html->safe()`, `$this->Form`) to render the admin settings panel with CSRF protection and masked secret inputs.
- **`views/default/process.pdt`**: Handles two distinct UI states during checkout:
  1. **Error State**: Renders a clean alert message if initialization (`buildProcess()`) encountered a configuration or network error.
  2. **Checkout Redirect State**: Renders the branded bKash payment button linking to the validated `bkashURL`.

---

## Security Architecture

| Security Mechanism | Method / Location | Protection Provided |
|---|---|---|
| **PCI-DSS Scope Offloading** | `NonmerchantGateway` | Customers enter wallet numbers, OTPs, and PINs solely on bKash's hosted domain. |
| **Integer Paisa Arithmetic** | `toPaisa()` / `buildProcess()` | Eliminates IEEE 754 floating-point discrepancies during currency comparisons. |
| **SSRF & Open Redirect Guard** | `isAllowedCheckoutUrl()` | Blocks redirection to untrusted domains if an API response is ever tampered with. |
| **Timing-Safe HMAC Verification** | `validate()` (`hash_equals`) | Prevents callback URL forgery and timing side-channel attacks. |
| **Atomic Concurrency Mutex** | `claimAttempt()` | Prevents double-execution and double-crediting when webhooks and redirects race. |
| **Execute + Status Fallback** | `validate()` | Queries `/payment/status` if `/execute` drops, preventing unrecorded charges. |
| **Anti-Replay `trxID` Guard** | `providerTransactionExists()` | Guarantees a single bKash `trxID` can never be replayed to credit another invoice. |
| **Log Credential Scrubbing** | `request()` | Masks `app_secret`, `password`, and tokens with `[redacted]` before logging. |

---

## Step-by-Step Developer Guide

### How to Modify Settings or Add a New Field

If you need to add a custom configuration field (e.g., `merchant_brand_name`):

1. **Add Form Input in `views/default/settings.pdt`**:
   ```php
   <div class="mb-3">
       <?php
       $this->Form->label($this->_('Bkash.brand_name', true), 'brand_name', ['class' => 'form-label']);
       $this->Form->fieldText('brand_name', ($meta['brand_name'] ?? null), ['id' => 'brand_name', 'class' => 'form-control']);
       ?>
   </div>
   ```
2. **Add Validation Rule in `bkash.php` (`editSettings()`)**:
   ```php
   $rules['brand_name'] = [
       'valid' => [
           'if_set' => true,
           'rule' => 'isEmpty',
           'negate' => true,
           'message' => Language::_('Bkash.!error.brand_name.empty', true)
       ]
   ];
   ```
3. **Add Language Strings in `language/en_us/bkash.php`**:
   ```php
   $lang['Bkash.brand_name'] = 'Brand Display Name';
   $lang['Bkash.!error.brand_name.empty'] = 'Please enter a brand display name.';
   ```

### How to Add a New Language Translation (e.g., `bn_bd`)

Detailed internationalization instructions are also included directly inside `language/en_us/bkash.php`:

1. Create a new directory under `language/` matching Blesta's ISO locale code (for example, Bengali - Bangladesh):
   ```bash
   mkdir -p language/bn_bd/
   ```
2. Copy `language/en_us/bkash.php` into the new directory:
   ```bash
   cp language/en_us/bkash.php language/bn_bd/bkash.php
   ```
3. Translate the string values on the right-hand side of each `$lang[...] = '...';` assignment while keeping the array keys strictly unchanged.
4. Blesta will automatically load `language/bn_bd/bkash.php` whenever an administrator or client selects Bengali (`bn_bd`).

---

## Error Reference

| Error Key | Message Displayed | Cause / Solution |
|---|---|---|
| `Bkash.!error.app_key.empty` | Enter the bKash App Key. | App Key missing in admin settings |
| `Bkash.!error.app_secret.empty` | Enter the bKash App Secret. | App Secret missing in admin settings |
| `Bkash.!error.username.empty` | Enter the bKash API username. | API username missing in admin settings |
| `Bkash.!error.password.empty` | Enter the bKash API password. | API password missing in admin settings |
| `Bkash.!error.sandbox.valid` | The sandbox setting must be "true" or "false". | Checkbox corrupted or invalid parameter |
| `Bkash.error.currency` | bKash payments are available only for BDT invoices. | Invoice is in USD/EUR; bKash requires BDT |
| `Bkash.error.invalid_amount` | The invoice payment amount is invalid. | Non-numeric or non-positive payment amount |
| `Bkash.error.cancelled` | Payment was cancelled. No charges were made... | Customer clicked Cancel or closed the bKash portal |
| `Bkash.error.gateway` | We could not start or verify the bKash payment. | Network failure, invalid credentials, or token error |

---

## Troubleshooting Guide

| Issue | Typical Cause | Resolution |
|---|---|---|
| **Payment button shows error instead of redirecting** | Invalid API credentials or sandbox mismatch | Check **Tools → Logs → Gateway Log** in Blesta. Verify credentials against the bKash sandbox table. |
| **"Live bKash payments require a public HTTPS callback URL"** | Blesta is configured with `http://` or `localhost` while Sandbox mode is disabled | Enable **Sandbox Mode** for local testing, or configure a valid SSL/TLS certificate on your production domain. |
| **bKash logo does not appear in Blesta admin** | Browser cache or outdated Blesta cache | Ensure `config.json` references `logo.light` and `logo.dark`. Clear Blesta cache under **Settings → System → General**. |
| **Invoice remains unpaid after bKash transaction** | Server firewall blocking outbound requests to bKash `/execute` or `/payment/status` | Check **Tools → Logs → Gateway Log** for the `/execute` HTTP response. Verify outbound TCP port 443 is open. |
| **Stuck attempt in `executing` state** | PHP worker crashed mid-request during callback | Wait 5 minutes (`CALLBACK_RETRY_DELAY_SECONDS = 300`) for automatic lock recovery, or inspect the attempt row in `bkash_payment_attempts`. |

---

## bKash API Quick Reference

| Endpoint | Method | Headers | Description |
|---|---|---|---|
| `/token/grant` | `POST` | `username`, `password` | Obtains Bearer `id_token` (cached for `TOKEN_TTL_SECONDS = 3000`s) |
| `/create` | `POST` | `Authorization`, `X-APP-Key` | Initializes transaction (`mode=0011`); returns `paymentID` & `bkashURL` |
| `/execute` | `POST` | `Authorization`, `X-APP-Key` | Finalizes transaction after customer OTP & PIN approval; returns `trxID` |
| `/payment/status` | `POST` | `Authorization`, `X-APP-Key` | Fallback query to verify payment state if `/execute` times out |

---

**Maintained for Blesta 5.x / 6.x** · Tested with **bKash Tokenized Checkout API v1.2.0-beta**.