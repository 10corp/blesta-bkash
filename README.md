# bKash Payment Gateway for Blesta v1.0.7

## Overview
bKash Tokenized Checkout integration for Blesta billing system.
Supports one-time payments and recurring via Standing Instructions.

## Requirements
- Blesta 5.x+
- PHP 7.4+
- bKash merchant account with Tokenized Checkout API access

## Installation
1. Upload `bkash/` folder to `/components/gateways/nonmerchant/`
2. Blesta Admin → Settings → Payment Gateways → Available → Install bKash
3. Enter your bKash App Key, App Secret, Username, Password
4. Enable Sandbox Mode for testing

## Bug Fixes in v1.0.7
- Bug 1: client_id from $contact_info (not $options)
- Bug 2: Proper error messages shown to users
- Bug 3: reference_id tracking for refunds
- Bug 4: config.json uses actual strings
- Bug 5: structure.pdt created (was missing)
- Bug 6: URL architecture fixed in BkashAuth
- Bug 7: payerReference passed for recurring
- Bug 8: No nested forms in process.pdt
- Bug 9: Tab indentation throughout

## API Reference
- bKash Tokenized Checkout: https://developer.bka.sh
- Blesta Non-Merchant Gateway: https://docs.blesta.com/developers/gateways/non-merchant-gateways

## Author
Mahmudul Hasan Tuhin
