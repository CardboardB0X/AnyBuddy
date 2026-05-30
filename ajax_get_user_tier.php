<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Get User Tier
 *  File   : ajax_get_user_tier.php
 *  Method : GET
 *  Returns: JSON
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db_connect.php';

try {
    $pdo = ab_pdo();
    
    $userId = ab_require_auth();
    
    // Check user exists
    $userCheck = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `user_id` = ?");
    $userCheck->execute([$userId]);
    if ((int)$userCheck->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }
    
    // Count completed bookings (status_id = 4 is Completed)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM `tbl_bookings` 
        WHERE `client_id` = ? AND `status_id` = 4
    ");
    $stmt->execute([$userId]);
    $completedCount = (int)$stmt->fetchColumn();
    
    // Find matching tier
    $tierStmt = $pdo->prepare("
        SELECT * FROM `tbl_user_tiers` 
        WHERE `min_bookings` <= ? 
        ORDER BY `min_bookings` DESC 
        LIMIT 1
    ");
    $tierStmt->execute([$completedCount]);
    $tier = $tierStmt->fetch();
    
    if (!$tier) {
        // Default fallback (Bronze)
        $tier = [
            'tier_name' => 'Bronze',
            'min_bookings' => 0,
            'platform_fee_percent' => 5.00,
            'discount_percent' => 0.00
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'user_id' => $userId,
        'completed_bookings' => $completedCount,
        'tier_name' => $tier['tier_name'],
        'platform_fee_percent' => (float)$tier['platform_fee_percent'],
        'discount_percent' => (float)$tier['discount_percent']
    ]);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error while fetching user tier.',
        'detail' => $e->getMessage()
    ]);
    exit;
}
