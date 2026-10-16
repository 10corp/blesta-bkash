# bKash Tokenized Checkout — Blesta Non-Merchant Gateway

> **Accept BDT invoice payments using bKash Tokenized Checkout inside Blesta billing.**
> Compatible with **Blesta 5.x / 6.x** · Supports **bKash Tokenized API v1.2.0-beta**

---

## Table of Contents

- [bKash Tokenized Checkout — Blesta Non-Merchant Gateway](#bkash-tokenized-checkout--blesta-non-merchant-gateway)
  - [Table of Contents](#table-of-contents)
  - [Overview](#overview)
  - [Features](#features)
  - [Requirements](#requirements)
  - [File Structure](#file-structure)
  - [Installation](#installation)
    - [Method 1: Manual Installation (Recommended)](#method-1-manual-installation-recommended)
    - [Method 2: Composer Installation](#method-2-composer-installation)
  - [Configuration](#configuration)
  - [bKash Sandbox Testing Guide \& Credentials](#bkash-sandbox-testing-guide--credentials)
    - [Official Sandbox API Credentials](#official-sandbox-api-credentials)
    - [Regular Active Customer Test Wallets](#regular-active-customer-test-wallets)
    - [End-to-End Sandbox Testing Walkthrough](#end-to-end-sandbox-testing-walkthrough)
  - [Payment \& Cancellation Lifecycle](#payment--cancellation-lifecycle)
    - [Cancellation Flow Comparison (Paysera Pattern)](#cancellation-flow-comparison-paysera-pattern)
  - [Database Schema](#database-schema)
    - [State Machine Reference](#state-machine-reference)
  - [Code Architecture \& Key Methods](#code-architecture--key-methods)
    - [Public Blesta Contract Methods](#public-blesta-contract-methods)
    - [Private Internal Engine Methods](#private-internal-engine-methods)
  - [Security Architecture](#security-architecture)
  - [Step-by-Step Developer Guide](#step-by-step-developer-guide)
    - [How to Modify Settings or Add a New Field](#how-to-modify-settings-or-add-a-new-field)
    - [How to Add a New Language Translation](#how-to-add-a-new-language-translation)
  - [Error Reference](#error-reference)
  - [Troubleshooting Guide](#troubleshooting-guide)
  - [bKash API Quick Reference](#bkash-api-quick-reference)

---

## Overview

This module integrates **bKash Tokenized Checkout** into Blesta as a **Non-Merchant Gateway**. Customers pay BDT invoices without entering mobile banking PINs or credentials on your server — they are redirected to bKash's official hosted payment portal, authorize the transaction with an OTP and wallet PIN, and are securely returned to Blesta.

The gateway implements the **Tokenized Checkout (Without Agreement)** flow (`mode=0011`): a direct one-time payment that does not require customer subscription agreements or stored wallet tokens.

---

## Features

| Feature | Description |
|---|---|
| **Payment Flow** | Hosted bKash Checkout URL (`mode=0011`) with instant return |
| **Blesta 6 Theme Support** | Dual light/dark SVG logos matching official Blesta 6 theme standards (Paysera pattern) |
| **Currency** | BDT only, with sub-unit (paisa) integer calculations to prevent float rounding errors |
| **Sandbox / Test Mode** | Seamless toggle in admin settings with built-in official test credentials |
| **Token Caching** | High-performance access token caching with automated TTL refresh |
| **Race Condition Protection** | Atomic DB state transitions (`claimAttempt()`) preventing double-execution |
| **Replay & Tampering Guard** | HMAC-SHA256 callback signatures and unique provider `trxID` validation |
| **Cancellation Handling** | Clean return handling with user-friendly notices when a customer cancels |
| **Encrypted Storage** | Sensitive fields (`app_secret`, `password`) encrypted at rest by Blesta |
| **Audit Trail** | Dedicated `bkash_payment_attempts` table preserved even across uninstalls |

---

## Requirements

| Requirement | Minimum Version | Notes |
|---|---|---|
| **Blesta** | 5.0+ / 6.0+ | Fully compatible with Blesta 6.x theme engine |
| **PHP** | 7.4, 8.0, 8.1, 8.2, 8.3 | Uses typed methods and modern secure crypto |
| **PHP Extensions** | `curl`, `json`, `openssl` | Standard on modern PHP hosting |
| **SSL / TLS** | HTTPS required for Live | bKash requires public HTTPS URLs for callbacks |
| **bKash Account** | Merchant Account | Tokenized Checkout API credentials from bKash |

---

## File Structure

```
bkash/
├── bkash.php                     # Main gateway class — implements NonmerchantGateway
├── composer.json                 # Composer package definition (blesta-gateway-nonmerchant)
├── config.json                   # Gateway metadata, supported currencies, and logo paths
├── LICENSE                       # MIT License
├── README.md                     # Comprehensive developer & operator documentation
├── language/
│   └── en_us/
│       └── bkash.php             # English language definitions and error strings
└── views/
    └── default/
        ├── images/
        │   ├── logo.svg          # Vector logo optimized for Light theme (dark wordmark)
        │   └── logo-dark.svg     # Vector logo optimized for Dark theme (white wordmark)
        ├── process.pdt           # Client invoice payment button view
        └── settings.pdt          # Admin gateway configuration view
```

---

## Installation

### Method 1: Manual Installation (Recommended)

1. Upload or copy the `bkash/` directory into your Blesta installation:
   ```bash
   /path/to/blesta/components/gateways/nonmerchant/bkash/
   ```
2. Ensure directory and file permissions allow the web server to read the files (e.g. `chmod -R 755 bkash`).
3. Log in to your Blesta Admin area.
4. Navigate to **Settings → Payment Gateways**.
5. Click on the **Available** tab, locate **bKash**, and click **Install**.
6. Enter your credentials, configure your gateway mode, and save.

### Method 2: Composer Installation

```bash
composer require custom/blesta-bkash
```

---

## Configuration

In Blesta Admin, navigate to **Settings → Payment Gateways → Installed → bKash → Manage**.

| Setting Field | Purpose | Environment / Source |
|---|---|---|
| **App Key** | Public merchant identifier for bKash API calls | bKash Merchant Portal |
| **App Secret** | Secret key used for signing and grant token requests | bKash Merchant Portal (encrypted at rest by Blesta) |
| **API Username** | Username associated with the API checkout credentials | bKash Merchant Portal |
| **API Password** | Password associated with the API checkout credentials | bKash Merchant Portal (encrypted at rest by Blesta) |
| **Sandbox Mode (Test)** | When checked, connects to sandbox API. When unchecked, connects to live | Toggle switch in gateway settings |

> [!IMPORTANT]
> Because `app_secret` and `password` are encrypted by Blesta upon saving, they are **never redisplayed** in plaintext. When updating settings in the admin area, you must re-enter your secret and password.

---

## bKash Sandbox Testing Guide & Credentials

The bKash sandbox simulates live payments without debiting real funds. Use the official test credentials and customer test wallets below:

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
3. Click **Pay with bKash** and click **Continue to bKash**.
4. You will be redirected to `sandbox.payment.bkash.com`.
5. Enter one of the test wallet numbers (e.g. `01770618567` or `01929918378`).
6. Enter OTP: `123456`.
7. Enter PIN: `12121`.
8. Once completed, bKash calls the Blesta callback URL, validates the signature, calls `/execute`, records the transaction, and redirects the client back to the paid invoice.

---

## Payment & Cancellation Lifecycle

The diagram below illustrates the exact request lifecycle from invoice generation to transaction finalization:

```
[Customer on Invoice Page]
          │
          ▼
   buildProcess()
          │ ── 1. Create DB record in bkash_payment_attempts (state: "created")
          │ ── 2. Request bKash access token via /token/grant (cached in Blesta cache)
          │ ── 3. Call bKash /create (mode=0011, callbackURL with HMAC signature)
          │ ── 4. Save bkash paymentID and update state to "redirected"
          ▼
[Render process.pdt with "Continue to bKash" button linking to bkashURL]
          │
          ▼ Customer clicks button
[bKash Hosted Portal: sandbox.payment.bkash.com or payment.bkash.com]
          │
     ┌────┴─────────────────────────────┐
     ▼                                  ▼
[Customer Completes Payment]    [Customer Clicks "Close" / "Cancel"]
     │                                  │
     ▼                                  ▼
Redirect to Callback URL        Redirect to Callback URL
(?status=success&paymentID=...) (?status=cancel&paymentID=...)
     │                                  │
     ▼                                  ▼
 validate()                         validate()
     │                                  │ ── Marks attempt "failed"
     │ ── Verify HMAC signature         │ ── Sets Bkash.error.cancelled
     │ ── Atomically claim attempt      │ ── Returns null
     │ ── Call bKash /execute           ▼
     │ ── Verify amount & currency  [Client Returns to Invoice Page]
     │ ── Save trxID & mark verified    │
     │ ── Return 'approved' array       ▼
     ▼                              success()
[Blesta Records Payment]                │ ── Displays cancellation message
     │                                  │ ── Leaves invoice open/unpaid
     ▼                                  ▼
 success()                          [Invoice Remains Unpaid & Clean]
     │ ── Confirm Blesta applied trans
     │ ── Mark attempt "completed"
     ▼
[Paid Invoice Confirmation]
```

### Cancellation Flow Comparison (Paysera Pattern)

In standard Blesta gateways like `gateway-paysera` and `gateway-sslcommerz`:
- When a user cancels on the third-party gateway, the gateway redirects back to Blesta with a cancellation flag.
- Blesta's `validate()` marks the local transaction record as failed or declined without throwing unhandled exceptions.
- The `success()` method intercepts the return, checks that the transaction is not approved, and registers a friendly message via `$this->Input->setErrors(['general' => ['invalid' => Language::_('Bkash.error.cancelled', true)]])`.
- The customer is neatly displayed an alert explaining that **no funds were deducted and their invoice remains open**, allowing them to immediately retry without broken screens.

---

## Database Schema

The module creates and maintains a dedicated table (`bkash_payment_attempts`) to ensure strict transactional integrity:

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

### State Machine Reference

| State | Description | Next Permitted States |
|---|---|---|
| `created` | Initial attempt row created in Blesta before calling bKash `/create` | `redirected`, `failed` |
| `redirected` | bKash `paymentID` received and customer given checkout URL | `executing`, `failed` |
| `executing` | Callback received; atomically claimed by server process to call `/execute` | `verified`, `redirected` (on retry), `failed` |
| `verified` | bKash `/execute` confirmed `Completed` and verified against expected amount | `completed`, `failed` |
| `completed` | Blesta recorded the transaction and applied it to invoices (Final Success) | *Terminal* |
| `failed` | Customer cancelled, token expired, or signature/verification failed | *Terminal* |

---

## Code Architecture & Key Methods

All core gateway logic resides in [`bkash.php`](file:///home/tuhin/Documents/www/bkash/bkash.php).

### Public Blesta Contract Methods

- `__construct()`: Loads configuration JSON, Input component, and localized language strings.
- `install()` / `upgrade($current_version)`: Runs idempotent table creation.
- `uninstall($gateway_id, $last_instance)`: Retains transaction logs for financial audits.
- `setCurrency($currency)`: Ensures all payment calls evaluate against BDT.
- `setMeta(array $meta)`: Receives credentials and mode settings from Blesta.
- `encryptableFields()`: Declares `['app_secret', 'password']` for AES-256 database encryption.
- `getCurrencies()`: Declares `['BDT']` as the sole supported currency.
- `getSettings(array $meta)`: Renders [`views/default/settings.pdt`](file:///home/tuhin/Documents/www/bkash/views/default/settings.pdt).
- `editSettings(array $meta)`: Validates form input and defaults missing checkbox data to `'false'`.
- `buildProcess($contact_info, $amount, $invoice_amounts, $options)`: Prepares the payment attempt, executes `/create`, and displays the checkout button.
- `validate(array $get, array $post)`: Server callback handler; verifies HMAC signature, claims attempt, and triggers `/execute`.
- `success(array $get, array $post)`: Client return handler; verifies state and informs the client of success or cancellation.

### Private Internal Engine Methods

- `getAccessToken()`: Caches bKash bearer tokens in Blesta's cache system with safe expiry buffers.
- `request($endpoint, $payload, $token, $extra_headers)`: Handles TLS 1.2+ HTTPS cURL requests with automated credential redaction in gateway logs.
- `claimAttempt($attempt_id)`: Atomic SQL UPDATE query acting as a mutex against callback concurrency.
- `toPaisa($amount)` / `formatPaisa($amount)`: Exact integer sub-unit conversion avoiding floating-point imprecision.
- `isAllowedCheckoutUrl($url)`: Whitelist validation preventing phishing redirects.

---

## Security Architecture

1. **HMAC-SHA256 Signed Callbacks**:
   Callback URLs include an encrypted hash of the `attempt_key` keyed by `app_secret`. Attackers cannot forge or spoof callback query strings.
2. **Concurrency Mutex (`claimAttempt`)**:
   Atomic SQL condition `WHERE state IN ('redirected', 'verified')` prevents double-credit race conditions when callbacks fire concurrently.
3. **Replay Attack Mitigation**:
   Unique constraint on `bkash_trx_id` combined with `providerTransactionExists()` guarantees a single bKash transaction ID cannot be reused across invoices.
4. **Credential Redaction in Logs**:
   The `request()` engine strips `app_secret` and `password` replacing them with `[redacted]` before recording logs in Blesta's Gateway Log table.
5. **HTTPS & Domain Enforcement**:
   Live mode verifies that the Blesta callback URL is public HTTPS and verifies that checkout URLs belong strictly to `*.bkash.com`.

---

## Step-by-Step Developer Guide

### How to Modify Settings or Add a New Field

If you need to add an additional setting (e.g. `merchant_brand_name`):

1. **Add Form Input in [`views/default/settings.pdt`](file:///home/tuhin/Documents/www/bkash/views/default/settings.pdt)**:
   ```php
   $this->Form->label($this->_('Bkash.brand_name', true), 'brand_name');
   $this->Form->fieldText('brand_name', ($meta['brand_name'] ?? null), ['id' => 'brand_name', 'class' => 'form-control']);
   ```
2. **Add Validation Rule in [`bkash.php`](file:///home/tuhin/Documents/www/bkash/bkash.php) (`editSettings`)**:
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
3. **Add Language Strings in [`language/en_us/bkash.php`](file:///home/tuhin/Documents/www/bkash/language/en_us/bkash.php)**:
   ```php
   $lang['Bkash.brand_name'] = 'Brand Display Name';
   $lang['Bkash.!error.brand_name.empty'] = 'Please enter a brand display name.';
   ```

### How to Add a New Language Translation

1. Create a new folder matching the ISO locale code:
   ```bash
   mkdir -p language/bn_bd/
   ```
2. Copy [`language/en_us/bkash.php`](file:///home/tuhin/Documents/www/bkash/language/en_us/bkash.php) to `language/bn_bd/bkash.php`.
3. Translate the strings on the right side of `$lang[...] = '...'`.
4. Blesta will automatically load this translation when an admin or client selects that language.

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
| `Bkash.error.cancelled` | Payment was cancelled. No charges were made... | Customer clicked Cancel or closed payment modal |
| `Bkash.error.gateway` | We could not start or verify the bKash payment. | Network failure, invalid credentials, or token error |

---

## Troubleshooting Guide

| Issue | Typical Cause | Resolution |
|---|---|---|
| **Payment button shows error instead of redirecting** | Invalid API credentials or sandbox mismatch | Check **Tools → Logs → Gateway Log** in Blesta. Verify credentials against the bKash sandbox table. |
| **"Live bKash payments require a public HTTPS callback URL"** | Blesta is configured with `http://` or `localhost` while Sandbox mode is disabled | Switch to **Sandbox Mode** for local testing, or install an SSL certificate on production. |
| **bKash logo does not appear in Blesta admin** | Browser cache or incorrect logo specification in `config.json` | Ensure `config.json` references `logo.light` and `logo.dark`. Clear Blesta cache under **Settings → System → General**. |
| **Invoice remains unpaid after bKash transaction** | Server firewall blocking outbound requests to bKash `/execute` | Check gateway logs for `/execute` HTTP response. Verify port 443 outbound traffic is open. |
| **Cancellation leaves client on empty screen** | Callback URL route mishandling | Verify that `validate()` properly traps `status=cancel` and lets `success()` handle UI rendering. |

---

## bKash API Quick Reference

| Endpoint | Method | Headers | Description |
|---|---|---|---|
| `/token/grant` | `POST` | `username`, `password` | Obtains Bearer `id_token` (valid for 1 hour) |
| `/create` | `POST` | `Authorization`, `X-APP-Key` | Initializes transaction; returns `paymentID` & `bkashURL` |
| `/execute` | `POST` | `Authorization`, `X-APP-Key` | Finalizes transaction after customer OTP & PIN approval |
| `/payment/status` | `POST` | `Authorization`, `X-APP-Key` | Fallback query to verify payment state if `/execute` times out |

---

**Maintained for Blesta 5.x / 6.x** · Tested with **bKash Tokenized Checkout API v1.2.0-beta**.
