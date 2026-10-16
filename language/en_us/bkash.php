<?php
// ──────────────────────────────────────────────────────────────────────────────
// Language definitions for the bKash gateway (English — US)
//
// Key naming convention:   Bkash.<context>.<detail>
// Error key convention:    Bkash.!error.<field>.<rule>
//
// To add a new language:
//   1. Copy this file to language/<locale>/bkash.php (e.g., language/bn_bd/bkash.php)
//   2. Translate all values — do NOT change the $lang keys
//   3. The gateway auto-loads the file matching the admin/client locale
// ──────────────────────────────────────────────────────────────────────────────

// Gateway name and description (shown in Blesta admin gateway list)
$lang['Bkash.name'] = 'bKash';
$lang['Bkash.description'] = 'Pay BDT invoices using bKash Tokenized Checkout.';

// ── Validation error messages ────────────────────────────────────────────────
// Shown when admin submits invalid gateway settings
$lang['Bkash.!error.app_key.empty'] = 'Enter the bKash App Key.';
$lang['Bkash.!error.app_secret.empty'] = 'Enter the bKash App Secret.';
$lang['Bkash.!error.username.empty'] = 'Enter the bKash API username.';
$lang['Bkash.!error.password.empty'] = 'Enter the bKash API password.';
$lang['Bkash.!error.sandbox.valid'] = 'The sandbox setting must be "true" or "false".';

// ── Admin settings form labels ───────────────────────────────────────────────
$lang['Bkash.app_key'] = 'App Key';
$lang['Bkash.app_secret'] = 'App Secret';
$lang['Bkash.username'] = 'API Username';
$lang['Bkash.password'] = 'API Password';
$lang['Bkash.sandbox'] = 'Sandbox Mode (Test)';

// ── Admin settings form helper text ──────────────────────────────────────────
$lang['Bkash.settings.mode_note'] = 'Uncheck to process live payments. Live mode requires production API credentials and a publicly reachable HTTPS callback URL.';
$lang['Bkash.settings.credentials_note'] = 'App Secret and Password are encrypted in storage. Password fields are always blank — re-enter all credentials when saving.';

// ── Admin settings section headers ───────────────────────────────────────────
$lang['Bkash.section.api_credentials'] = 'API Credentials';
$lang['Bkash.section.mode'] = 'Gateway Mode';

// ── Client-facing payment page ───────────────────────────────────────────────
$lang['Bkash.buildprocess.submit'] = 'Continue to bKash';

// ── Client-facing error messages ─────────────────────────────────────────────
$lang['Bkash.error.gateway'] = 'We could not start or verify the bKash payment. Please try again or contact support.';
$lang['Bkash.error.currency'] = 'bKash payments are available only for BDT invoices.';
$lang['Bkash.error.invalid_amount'] = 'The invoice payment amount is invalid.';
$lang['Bkash.error.cancelled'] = 'Payment was cancelled. No charges were made to your account. Your invoice remains unpaid — please try again or choose another payment method.';
