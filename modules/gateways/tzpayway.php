<?php
/**
 * TZPayWay WHMCS Payment Gateway Module
 *
 * Official payment gateway module for WHMCS to accept bKash, Nagad, Rocket, Upay,
 * Bangladeshi Bank Transfers, and Crypto payments with instant automated invoice crediting.
 *
 * @package    WHMCS
 * @author     TZPayWay Team <support@tzpayway.com>
 * @copyright  Copyright (c) TZPayWay (https://tzpayway.com)
 * @license    MIT License
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module metadata
 *
 * @return array
 */
function tzpayway_MetaData()
{
    return [
        'DisplayName' => 'TZPayWay Payment Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedMethod' => false,
    ];
}

/**
 * Define gateway configuration options
 *
 * @return array
 */
function tzpayway_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'TZPayWay (bKash, Nagad, Rocket, Upay, Cards)',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key (X-API-KEY)',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your merchant API key from TZPayWay dashboard (e.g. pk_live_...)',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your secret key for HMAC-SHA256 signature verification.',
        ],
        'apiUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://tzpayway.com',
            'Description' => 'API endpoint (Default: https://tzpayway.com)',
        ],
        'autoRedirect' => [
            'FriendlyName' => 'Auto Redirect to Checkout',
            'Type' => 'yesno',
            'Default' => 'no',
            'Description' => 'Check to automatically redirect client to TZPayWay checkout without showing Pay button.',
        ],
    ];
}

/**
 * Payment link generation
 *
 * Builds the checkout request and generates the payment button / redirect form.
 *
 * @param array $params Gateway and invoice parameters
 * @return string HTML button or redirect form
 */
function tzpayway_link($params)
{
    // Gateway Configuration Parameters
    $apiKey = trim($params['apiKey'] ?? '');
    $secretKey = trim($params['secretKey'] ?? '');
    $apiUrl = rtrim($params['apiUrl'] ?? 'https://tzpayway.com', '/');
    $autoRedirect = !empty($params['autoRedirect']);

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params['description'] ?? ('Invoice #' . $invoiceId);
    $amount = (float) $params['amount'];
    $currencyCode = strtoupper($params['currency'] ?? 'BDT');

    // Client Parameters
    $clientDetails = $params['clientdetails'] ?? [];
    $firstName = $clientDetails['firstname'] ?? '';
    $lastName = $clientDetails['lastname'] ?? '';
    $customerName = trim($firstName . ' ' . $lastName) ?: 'Valued Customer';
    $customerEmail = $clientDetails['email'] ?? 'customer@example.com';
    $customerPhone = $clientDetails['phonenumber'] ?? '';

    // System URLs
    $systemUrl = rtrim($params['systemurl'], '/');
    $successUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentsuccess=true';
    $cancelUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId . '&paymentfailed=true';
    $webhookUrl = $systemUrl . '/modules/gateways/callback/tzpayway.php';

    if (empty($apiKey)) {
        return '<div class="alert alert-danger" style="margin: 10px 0; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px;">' .
            '<strong>Payment Gateway Error:</strong> TZPayWay API Key is not configured. Please contact the administrator.</div>';
    }

    // Build Request Payload for TZPayWay API V1
    $payload = [
        'amount' => round($amount, 2),
        'currency' => $currencyCode,
        'cus_name' => $customerName,
        'cus_email' => $customerEmail,
        'cus_number' => $customerPhone,
        'user_data' => [
            'invoice_id' => $invoiceId,
            'client_id' => $clientDetails['userid'] ?? ($clientDetails['id'] ?? null),
            'track' => 'WHMCS-' . $invoiceId . '-' . time(),
            'description' => $description,
            'system_url' => $systemUrl,
        ],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'webhook_url' => $webhookUrl,
    ];

    // Call TZPayWay API V1 /payment/create
    $endpoint = $apiUrl . '/api/v1/payment/create';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return '<div class="alert alert-danger" style="margin: 10px 0; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px;">' .
            '<strong>Connection Error:</strong> Unable to connect to TZPayWay (' . htmlspecialchars($curlError) . ')</div>';
    }

    $result = json_decode($response, true);

    if ($httpCode === 200 && !empty($result['success']) && !empty($result['checkout_url'])) {
        $checkoutUrl = $result['checkout_url'];

        if ($autoRedirect) {
            return '<form method="GET" action="' . htmlspecialchars($checkoutUrl) . '" id="tzpaywayAutoForm">
                <button type="submit" class="btn btn-success btn-lg" style="background-color: #4f46e5; border-color: #4338ca; color: #fff; padding: 10px 24px; font-size: 15px; border-radius: 8px; cursor: pointer;">
                    Redirecting to TZPayWay Checkout...
                </button>
            </form>
            <script type="text/javascript">
                setTimeout(function() {
                    document.getElementById("tzpaywayAutoForm").submit();
                }, 800);
            </script>';
        }

        return '<div style="margin: 15px 0;">
            <a href="' . htmlspecialchars($checkoutUrl) . '" class="btn btn-primary btn-lg" style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg, #4f46e5, #6366f1); border: none; color: #fff; padding: 12px 28px; font-size: 15px; font-weight: 500; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2); cursor: pointer;">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <span>Pay ' . htmlspecialchars($currencyCode . ' ' . number_format($amount, 2)) . ' via TZPayWay</span>
            </a>
            <div style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                Supports bKash, Nagad, Rocket, Upay, Bank Transfer &amp; Crypto
            </div>
        </div>';
    }

    $errorMsg = $result['error'] ?? ($result['message'] ?? 'Failed to initiate payment session with TZPayWay.');
    if (is_array($errorMsg)) {
        $errorMsg = implode(', ', array_map(function($k, $v) {
            return is_array($v) ? implode(', ', $v) : "$k: $v";
        }, array_keys($errorMsg), $errorMsg));
    }

    return '<div class="alert alert-danger" style="margin: 10px 0; padding: 12px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px;">' .
        '<strong>Payment Gateway Error:</strong> ' . htmlspecialchars((string) $errorMsg) . '</div>';
}
