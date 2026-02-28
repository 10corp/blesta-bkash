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
- **Website:** [10corp.com](https://www.10corp.com)  

## Welcome New Contributors! 👋

We're excited to have you contribute to the bKash Payment Gateway for Blesta project! Whether you're fixing bugs, adding features, or improving documentation, your contributions are valuable.

### How to Contribute
1. **Fork** the repository on GitHub
2. **Create a branch** for your feature or fix (`git checkout -b feature/your-feature-name`)
3. **Make your changes** and ensure code quality
4. **Test thoroughly** before submitting
5. **Submit a Pull Request** with a clear description of your changes

### Areas We'd Love Help With
- Bug fixes and performance improvements
- Additional payment features
- Enhanced error handling and logging
- Documentation improvements
- Test coverage expansion
- Integration improvements with Blesta

### Getting Started
- Review the [API Reference](#api-reference) section
- Check existing [Bug Fixes](#bug-fixes-in-v107) for context
- Read the [Installation](#installation) guide to set up your development environment

### Questions or Issues?
Feel free to open an issue on GitHub or reach out to the maintainers. We're here to help!

Thank you for contributing! 🙌