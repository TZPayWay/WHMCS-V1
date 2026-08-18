<?php
/**
 * TZPayWay WHMCS Payment Gateway IPN / Callback Handler
 *
 * Handles automated Instant Payment Notifications (IPN) from TZPayWay,
 * verifies HMAC signature and transaction status, prevents duplicate transactions,
 * and automatically marks WHMCS invoices as Paid.
 *
 * @package    WHMCS
 * @author     TZPayWay Team <support@tzpayway.com>
 * @copyright  Copyright (c) TZPayWay (https://tzpayway.com)
 * @license    MIT License
 * @version    1.0.0
 */

// Require WHMCS Core Libraries
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect Gateway Module Name
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch Gateway Configuration from WHMCS Database
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Verify module is active in WHMCS
if (empty($gatewayParams['type'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'TZPayWay Payment Gateway Module is not activated in WHMCS.'
    ]);
    exit;
}

// Retrieve raw request body and headers
$rawContent = file_get_contents('php://input');
$payload = json_decode($rawContent, true);

if (!$payload || !is_array($payload)) {
    $payload = $_POST;
}

// Extract Transaction Identifier and Invoice ID
$trxId = $payload['trx_id'] 
    ?? ($payload['data']['trx_id'] 
    ?? ($payload['tz_trx_id'] 
    ?? null));

$invoiceId = $payload['user_data']['invoice_id'] 
    ?? ($payload['invoice_id'] 
    ?? ($payload['data']['user_data']['invoice_id'] 
    ?? ($payload['custom'] 
    ?? null)));

$amount = (float) ($payload['amount'] ?? ($payload['received_amount'] ?? 0));
$fee = (float) ($payload['fee'] ?? 0);
$currency = strtoupper($payload['currency'] ?? 'BDT');
$status = strtolower($payload['status'] ?? ($payload['data']['status'] ?? ''));

// Validate required fields
if (!$invoiceId || !$trxId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing transaction ID (trx_id) or Invoice ID in webhook payload.'
    ]);
    exit;
}

// Ensure Invoice ID is valid in WHMCS
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Gateway Credentials
$apiKey = trim($gatewayParams['apiKey'] ?? '');
$secretKey = trim($gatewayParams['secretKey'] ?? '');
$apiUrl = rtrim($gatewayParams['apiUrl'] ?? 'https://tzpayway.com', '/');

// Signature Verification
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] 
    ?? ($_SERVER['HTTP_X_TZPAYWAY_SIGNATURE'] 
    ?? '');

$isVerified = false;

if (!empty($secretKey)) {
    // 1. Verify HMAC-SHA256 Signature
    if (!empty($signature)) {
        $expectedSig1 = hash_hmac('sha256', $rawContent, $secretKey);
        $expectedSig2 = hash_hmac('sha256', json_encode($payload), $secretKey);
        if (hash_equals($expectedSig1, $signature) || hash_equals($expectedSig2, $signature)) {
            $isVerified = true;
        }
    }

    // 2. Verify encrypted_data payload if provided
    if (!$isVerified && !empty($payload['encrypted_data'])) {
        $rawEnc = base64_decode($payload['encrypted_data']);
        if (strlen($rawEnc) > 16) {
            $iv = substr($rawEnc, 0, 16);
            $cipherText = substr($rawEnc, 16);
            $key = hash('sha256', $secretKey, true);
            $decrypted = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted) {
                $decData = json_decode($decrypted, true);
                if (is_array($decData) && !empty($decData['trx_id'])) {
                    $isVerified = true;
                }
            }
        }
    }
}

// 3. Fallback direct server-to-server API verification using X-API-KEY
if (!$isVerified && !empty($apiKey) && !empty($trxId)) {
    $verifyEndpoint = $apiUrl . '/api/v1/payment/status/' . urlencode($trxId);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verifyEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $verifyResponse = curl_exec($ch);
    curl_close($ch);

    $verifyData = json_decode($verifyResponse, true);
    $remoteStatus = strtolower($verifyData['data']['status'] ?? '');
    if (in_array($remoteStatus, ['completed', 'paid', 'success'])) {
        $isVerified = true;
        $status = $remoteStatus;
    }
}

// Reject unverified callbacks if secretKey is set
if (!$isVerified && !empty($secretKey)) {
    logTransaction($gatewayParams['name'], [
        'error' => 'Webhook signature verification failed',
        'headers' => $_SERVER,
        'payload' => $payload,
    ], 'Unsuccessful');

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Webhook signature verification failed. Please verify your Secret Key configuration.'
    ]);
    exit;
}

// Check Transaction Status
$isPaid = in_array($status, ['completed', 'paid', 'success']);

if ($isPaid) {
    // Prevent duplicate processing in WHMCS
    checkCbTransID($trxId);

    // Apply Payment to WHMCS Invoice
    // addInvoicePayment($invoiceid, $transid, $amount, $fee, $gateway)
    addInvoicePayment(
        $invoiceId,
        $trxId,
        $amount,
        $fee,
        $gatewayModuleName
    );

    // Log successful transaction in WHMCS Gateway Log
    logTransaction($gatewayParams['name'], [
        'invoice_id' => $invoiceId,
        'trx_id' => $trxId,
        'amount' => $amount,
        'currency' => $currency,
        'method' => $payload['method'] ?? 'TZPayWay',
        'cus_number' => $payload['cus_number'] ?? null,
        'cus_name' => $payload['cus_name'] ?? null,
        'status' => $status,
    ], 'Successful');

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Invoice #' . $invoiceId . ' payment of ' . $currency . ' ' . $amount . ' marked as Paid successfully.'
    ]);
    exit;
}

// Log ignored or uncompleted status
logTransaction($gatewayParams['name'], [
    'invoice_id' => $invoiceId,
    'trx_id' => $trxId,
    'status' => $status,
    'payload' => $payload,
], 'Ignored');

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ignored',
    'message' => 'Transaction status is ' . ($status ?: 'pending') . '. No invoice updates made.'
]);
exit;
