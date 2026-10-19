<?php
/**
 * Language Definitions for the bKash Gateway (English — US).
 *
 * --------------------------------------------------------------------------
 * BLESTA I18N ARCHITECTURE GUIDE FOR DEVELOPERS:
 * --------------------------------------------------------------------------
 * In Blesta, all user-facing strings are stored in language definition files.
 *
 * Key Naming Conventions:
 *  - General / Interface Strings:
 *      $lang['Bkash.<context>.<detail>']
 *      Retrieved in code via: Language::_('Bkash.<context>.<detail>', true)
 *      Rendered in views via: $this->_('Bkash.<context>.<detail>')
 *
 *  - Validation Error Strings:
 *      $lang['Bkash.!error.<field>.<rule>']
 *      The exclamation mark (!) is Blesta's standard prefix for Input component validation errors.
 *
 * How to add translations for another language (e.g., Bengali - Bangladesh):
 *  1. Create the directory: `language/bn_bd/`
 *  2. Copy this file to: `language/bn_bd/bkash.php`
 *  3. Translate the string values on the right-hand side. DO NOT change the array keys on the left.
 *  4. Blesta will automatically load `language/<user_locale>/bkash.php` based on user preference.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.bkash
 */

// ── Gateway Name & Description (Admin Gateway List) ──────────────────────────
$lang['Bkash.name'] = 'bKash';
$lang['Bkash.description'] = 'Pay BDT invoices using bKash Tokenized Checkout.';

// ── Validation Error Messages (Input Form Validation) ────────────────────────
// Displayed when an administrator submits invalid settings in Edit Gateway.
$lang['Bkash.!error.app_key.empty'] = 'Enter the bKash App Key.';
$lang['Bkash.!error.app_secret.empty'] = 'Enter the bKash App Secret.';
$lang['Bkash.!error.username.empty'] = 'Enter the bKash API username.';
$lang['Bkash.!error.password.empty'] = 'Enter the bKash API password.';
$lang['Bkash.!error.sandbox.valid'] = 'Enable exactly one gateway mode: Sandbox or Live.';

// ── Admin Settings Form Field Labels ─────────────────────────────────────────
$lang['Bkash.app_key'] = 'App Key';
$lang['Bkash.app_secret'] = 'App Secret';
$lang['Bkash.username'] = 'API Username';
$lang['Bkash.password'] = 'API Password';
$lang['Bkash.sandbox'] = 'Enable Sandbox (Test Mode)';
$lang['Bkash.live'] = 'Enable Live Payments';

// ── Admin Settings Form Helper & Warning Notes ───────────────────────────────
$lang['Bkash.settings.mode_note'] = 'Only one mode can be enabled at a time. Live payments require Live API credentials and a public HTTPS callback URL.';
$lang['Bkash.settings.credentials_note'] = 'App Secret and Password are encrypted in storage. Password fields are blank; enter all credentials again when saving.';

// ── Admin Settings Card Section Headers ──────────────────────────────────────
$lang['Bkash.section.api_credentials'] = 'API Credentials';
$lang['Bkash.section.mode'] = 'Gateway Mode';

// ── Client-Facing Checkout Button ────────────────────────────────────────────
$lang['Bkash.buildprocess.submit'] = 'Continue to bKash';

// ── Client-Facing Error Messages ─────────────────────────────────────────────
$lang['Bkash.error.gateway'] = 'We could not start or verify the bKash payment. Please try again or contact support.';
$lang['Bkash.error.currency'] = 'bKash payments are available only for BDT invoices.';
$lang['Bkash.error.invalid_amount'] = 'The invoice payment amount is invalid.';
$lang['Bkash.error.cancelled'] = 'Payment was cancelled. No charges were made to your account. Your invoice remains unpaid—please try again or choose another payment method.';

