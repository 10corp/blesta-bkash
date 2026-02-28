# bKash Payment Gateway for Blesta

Accept payments through **bKash** using this official integration for [Blesta](https://blesta.com/).  
Supports both **Sandbox** and **Live** environments.

## Install the Gateway

1. Upload the `bkash/` folder to your Blesta installation under:

    ```
    /components/gateways/nonmerchant/bkash/
    ```

2. In your Blesta admin area:

    - Go to **[Settings] > [Company] > [Payment Gateways] > [Available]**.
    - Find **bKash** in the list and click **Install**.

3. Configure the gateway by entering your **API credentials** and selecting either **Sandbox** or **Live** mode.

## Requirements

- Blesta 5.0.0+  
- PHP 7.2+  
- A valid **bKash** Merchant Account

Don't have an account? [Sign up here](https://www.bkash.com/business/online-business).

## Gateway Features

- Accepts payments in **Bangladeshi Taka (BDT)**.
- Supports **Sandbox** and **Live** modes for testing and production.
- Seamless checkout flow for customers.
- Transaction logging for easy debugging and reporting.

## Configuration Options

When setting up the gateway, you will be asked for:

- **API Key**
- **API Secret**
- **App ID**
- **App Secret**
- **Environment** (Sandbox or Live)

You can obtain these credentials from your bKash Merchant Dashboard after signing up.

## About

- **Author:** Mahmudul Hasan  
- **Website:** [10corp.com](https://www.10corp.com)  
- **Version:** 1.0.0

---
