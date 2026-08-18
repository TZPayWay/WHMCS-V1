# TZPayWay WHMCS Payment Gateway Module (WHMCS-V1)

Official drop-in payment gateway module for **WHMCS** (Web Host Manager Complete Solution). Accept **bKash, Nagad, Rocket, Upay, Bangladeshi Bank Transfers, and Crypto** on your WHMCS billing platform with instant automated invoice crediting and service provisioning.

---

## 🚀 Features

- **Multi-Method Acceptance**: Accept bKash (Personal & Merchant), Nagad, Rocket, Upay, Bank Transfer, and Crypto seamlessly.
- **Automated Invoice Crediting**: Invoices are marked as **Paid** automatically upon payment confirmation via Instant Payment Notifications (IPN).
- **Automated Service Provisioning**: Triggers WHMCS automatic hosting account creation, domain registrations, and renewal hooks upon payment.
- **HMAC-SHA256 & Double-Check Verification**: Secures every IPN webhook with cryptographic HMAC signatures and fallback server-to-server verification.
- **Duplicate Prevention**: Built-in transaction ID check (`checkCbTransID`) guarantees payments are never credited twice.
- **Comprehensive Gateway Logging**: All transaction lifecycle events and debugging logs are saved directly in WHMCS (`Billing -> Gateway Log`).
- **One-Click Checkout / Auto-Redirect**: Supports both direct Pay button with responsive styling and automatic instant redirect.
- **Broad Compatibility**: Fully tested with WHMCS 7.x, 8.x and PHP 7.4, 8.0, 8.1, 8.2, 8.3, and 8.4.

---

## 📦 Requirements

- **WHMCS**: Version 7.10+ or 8.x+
- **PHP**: 7.4 – 8.4 with `curl`, `json`, `openssl`, and `hash` extensions enabled
- **TZPayWay Merchant Account**: Active Merchant Account with API Key and Secret Key from [https://tzpayway.com](https://tzpayway.com)

---

## 🛠️ Installation Guide

### Step 1: Upload Module Files
Download or extract the `WHMCS-V1` repository and upload the contents of the `modules` directory to your WHMCS root directory:

```text
whmcs_root/
  └── modules/
      └── gateways/
          ├── tzpayway.php
          └── callback/
              └── tzpayway.php
```

### Step 2: Activate Gateway in WHMCS Admin
1. Log in to your WHMCS Admin Area.
2. Navigate to:
   - **WHMCS 8.x**: `Configuration (wrench icon)` &rarr; `System Settings` &rarr; `Payment Gateways`
   - **WHMCS 7.x**: `Setup` &rarr; `Payments` &rarr; `Payment Gateways`
3. Click the **All Payment Gateways** tab.
4. Locate **TZPayWay (bKash, Nagad, Rocket, Upay, Cards)** and click **Activate**.

### Step 3: Configure Module Credentials
Enter your TZPayWay credentials in the module settings:

| Field Name | Description | Example / Recommended Value |
|---|---|---|
| **Show on Order Form** | Enable to show TZPayWay on client checkout | Checked (Yes) |
| **Visible Name** | Name displayed to clients on invoices | `TZPayWay (bKash / Nagad / Rocket / Cards)` |
| **API Key (X-API-KEY)** | Your TZPayWay Merchant API Key | `pk_live_...` or test key |
| **Secret Key** | Your TZPayWay Webhook Secret Key | `sk_live_...` or test secret |
| **API Base URL** | API Endpoint | `https://tzpayway.com` |
| **Auto Redirect** | Automatically redirect clients to checkout | Optional (Yes/No) |

Click **Save Changes**.

### Step 4: Webhook / IPN URL
Your WHMCS IPN callback URL is automatically constructed:
```text
https://your-domain.com/modules/gateways/callback/tzpayway.php
```
*(No manual webhook URL registration is required; the module passes this callback URL dynamically with each payment session).*

---

## 🔍 Testing & Verification

1. Create a test invoice in WHMCS or place an order via the client area.
2. Select **TZPayWay** as the payment method.
3. Click the **Pay via TZPayWay** button.
4. Complete the payment on the TZPayWay hosted checkout.
5. You will be redirected back to the invoice page with `paymentsuccess=true`, and the invoice will be marked as **Paid**.
6. Check **Billing &rarr; Gateway Log** in the WHMCS Admin to inspect transaction payloads.

---

## 🔒 Security Architecture

1. **HMAC-SHA256 Webhook Signatures**: Incoming webhooks from TZPayWay are verified using `hash_hmac('sha256', payload, secretKey)`.
2. **Server-to-Server Fallback Verification**: If a webhook signature header is stripped by an aggressive reverse proxy, the module falls back to `GET /api/v1/payment/status/{trx_id}` using your API key.
3. **Idempotency Guarantee**: `checkCbTransID()` blocks replay attacks and duplicate webhook notifications.

---

## 💬 Support

- **Documentation**: [https://tzpayway.com/api-docs/modules](https://tzpayway.com/api-docs/modules)
- **Merchant Support**: [support@tzpayway.com](mailto:support@tzpayway.com)
- **GitHub Issues**: [https://github.com/TZPayWay/WHMCS-V1](https://github.com/TZPayWay/WHMCS-V1)

---

## 📄 License
MIT License. Free to use and customize for your WHMCS hosting business.
