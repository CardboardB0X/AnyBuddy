<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Validate Voucher
 *  File   : ajax_validate_voucher.php
 *  Method : POST or GET
 *  Returns: JSON
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db_connect.php';

try {
    $pdo = ab_pdo();
    
    // Support both JSON POST payload and URL parameters
    $method = $_SERVER['REQUEST_METHOD'];
    $code = '';
    $basePrice = 0.0;
    
    if ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?? [];
        } else {
            $input = $_POST;
        }
        $code = trim($input['code'] ?? '');
        $basePrice = (float)($input['base_price'] ?? 0.0);
    } else {
        $code = trim($_GET['code'] ?? '');
        $basePrice = (float)($_GET['base_price'] ?? 0.0);
    }
    
    if ($code === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Voucher code is required.']);
        exit;
    }
    
    // Query voucher
    $stmt = $pdo->prepare("
        SELECT * FROM `tbl_vouchers` 
        WHERE `code` = ? AND `is_active` = 1
    ");
    $stmt->execute([$code]);
    $voucher = $stmt->fetch();
    
    if (!$voucher) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or inactive voucher code.']);
        exit;
    }
    
    // Check expiration date
    if ($voucher['expiration_date'] !== null) {
        $expiry = new DateTime($voucher['expiration_date']);
        $today = new DateTime('today');
        if ($expiry < $today) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'This voucher has expired.']);
            exit;
        }
    }
    
    // Check minimum spend
    $minSpend = (float)$voucher['min_spend'];
    if ($basePrice < $minSpend) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error', 
            'message' => sprintf('This voucher requires a minimum spend of ₱%s. Current base price is ₱%s.', number_format($minSpend, 2), number_format($basePrice, 2))
        ]);
        exit;
    }
    
    // Calculate discount amount
    $discountVal = (float)$voucher['discount_value'];
    $discountAmt = 0.00;
    
    if ($voucher['discount_type'] === 'fixed') {
        $discountAmt = $discountVal;
    } elseif ($voucher['discount_type'] === 'percentage') {
        $discountAmt = $basePrice * ($discountVal / 100.0);
    }
    
    // Cap discount at base price
    if ($discountAmt > $basePrice) {
        $discountAmt = $basePrice;
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Voucher applied successfully!',
        'voucher_id' => (int)$voucher['voucher_id'],
        'code' => $voucher['code'],
        'discount_type' => $voucher['discount_type'],
        'discount_value' => $discountVal,
        'discount_amount' => $discountAmt,
        'min_spend' => $minSpend
    ]);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error while validating voucher.',
        'detail' => $e->getMessage()
    ]);
    exit;
}
