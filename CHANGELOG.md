# Changelog

All notable changes to the **bKash Tokenized Checkout Non-Merchant Gateway for Blesta** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.3.1] - 2026-10-31

### Added
- **Automated Client Refund Receipt Email**: Integrated automated, mobile-responsive HTML and plain-text refund confirmation emails dispatched directly to the customer upon successful bKash refund execution.
- **Auditable Mail Logging**: Configured refund notifications to automatically log to Blesta's `log_emails` table tied to the client account ID.
- **Dispute-Proof Refund Receipt Details**: Email receipt prominently displays the bKash `refundTrxID`, original `trxID`, amount, invoice reference, processing date, and official verification instructions (SMS 16247 & bKash App Inbox).
- **Admin Settings Notification Toggle**: Added a `Send Refund Email to Client` switch under **Customer Notifications** in gateway settings (`settings.pdt`).

---

## [1.3.0] - 2026-10-31

### Changed
- **PHP 8.4+ Exclusive Platform Requirement**: Raised minimum PHP requirement strictly to PHP 8.4+ (`PHP_VERSION_ID >= 80400`) in `Bkash::__construct()` and `composer.json` (`"php": ">=8.4"`). Environments running PHP < 8.4 are blocked with an explicit runtime exception.
- **Blesta 6.0+ Exclusivity**: Enforced Blesta 6.0+ runtime requirement, explicitly dropping legacy Blesta 5.x compatibility.
- **Modern PHP 8.4 Typed Properties**: Adopted PHP 8.4 typed properties (`private array $meta`, `protected string $currency`) for enhanced type safety and performance.

---

## [1.2.0] - 2026-10-31

### Added
- **Direct Refund API Support (`Bkash::refund`)**: Implemented native integration with the bKash Tokenized Checkout `/payment/refund` endpoint to process full and partial refunds directly from the Blesta admin panel.
- **Smart `paymentID` Fallback Resolution**: Added automated database resolution from `bkash_payment_attempts` when Blesta core passes an empty reference ID or duplicates the `trxID` during refund requests.
- **Gateway Log Refund Tracking**: Added `refundTrxID` capture and response status code recording in Blesta's Gateway Logs.
- **Client Payment Confirmation Banner**: Injected a prominent, styled Bootstrap 4 / FontAwesome success message on `client/pay/received/` displaying exact paid BDT amount, bKash `trxID`, and confirmation receipt notice.
- **Multi-Tenant State Machine Guard**: Hardened `markAttemptFailed()` at the database query level with `WHERE state IN ('created', 'redirected', 'executing')`, guaranteeing that `verified` and `completed` records can never be downgraded to `failed`.

### Changed
- **Anti-Tampering Sequence**: Reordered `validate()` and `success()` state checks so already-verified attempts immediately render approved confirmations without inspecting unsigned query parameters (`?status=cancel`).
- **Atomic Mutex Optimization**: Streamlined `claimAttempt()` to `WHERE state = 'redirected'` to guarantee that captured transactions cannot re-enter execution state.
- **Transaction ID Mapping**: Mapped bKash `trxID` to Blesta `transaction_id` (searchable in admin) and bKash `paymentID` to `reference_id`.
- **License & Authors**: Standardized license to `MIT` across `composer.json` and `LICENSE`, and set author attribution to `10Corp Web Services`.
- **Documentation**: Replaced generic "webhook" references with precise "server-to-server callback verification" and added complete refund workflow guide.

---

## [1.1.0] - 2026-10-30

### Added
- **Blesta 6.0 Paradigm / Bootstrap 5 Admin View**: Fully converted `views/default/settings.pdt` to Bootstrap 5 card utilities, form-control, form-label, and Bootstrap Icons (`bi-wallet2`, `bi-key`, `bi-gear`).
- **Native Dark Mode Support**: Implemented CSS theme awareness supporting both Light and Dark modes.
- **Integer Paisa Financial Arithmetic**: Implemented sub-unit integer paisa math (`toPaisa`, `formatPaisa`) to eliminate IEEE 754 floating-point precision issues.
- **SSRF & Open-Redirect Whitelisting**: Added domain whitelisting (`sandbox.payment.bkash.com`, `payment.bkash.com`, `tokenized.sandbox.bka.sh`, `tokenized.pay.bka.sh`) on checkout redirect URLs.
- **Sensitive Credential Scrubbing**: Added `[redacted]` masking in gateway logs for `app_secret` and `password`.

### Changed
- Maintained client checkout view (`views/default/process.pdt`) on Bootstrap 4 + FontAwesome per Blesta 6.0 Dev Kit standards.
- Enforced strict checkbox state normalization to `'false'` in `editSettings()`.

---

## [1.0.0] - 2026-10-19

### Added
- Initial release of the bKash Tokenized Checkout Non-Merchant Gateway for Blesta.
- Support for bKash Tokenized API `v1.2.0-beta` ("Without Agreement", `mode=0011`, `intent=sale`).
- Token caching using Blesta's `CacheFactory` with 3000s TTL.
- Automatic creation and lifecycle tracking with `bkash_payment_attempts` database table.
- HMAC-SHA256 tamper-proof callback URL generation and timing-safe verification.
- Automatic inquiry fallback from `/execute` to `/payment/status`.
