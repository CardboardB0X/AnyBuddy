<?php
/**
 * Verification Script for Vouchers, Tiers, and Platform Fees
 */

declare(strict_types=1);

require_once __DIR__ . '/../db_connect.php';

function test_assert(string $name, bool $expr, string $detail = ''): void {
    if ($expr) {
        echo "✅ [PASS] {$name}" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        echo "❌ [FAIL] {$name}" . ($detail ? " ($detail)" : "") . "\n";
    }
}

try {
    $pdo = ab_pdo();
    
    // Reset database to ensure clean test state
    echo "Initializing database to clean state...\n";
    ab_init_database($pdo, false);
    echo "Database initialized!\n\n";

    // Test 1: User 13 Tier Fetching (John Doe - new client with 0 bookings)
    echo "--- Test 1: Fetch John Doe's Loyalty Tier ---\n";
    $ch = curl_init('http://localhost/AnyBuddy/ajax_get_user_tier.php?user_id=13');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Status is success", ($res['status'] ?? '') === 'success');
    test_assert("John Doe is Bronze Tier", ($res['tier_name'] ?? '') === 'Bronze');
    test_assert("Platform fee percent is 5.0", (float)($res['platform_fee_percent'] ?? 0.0) === 5.0);
    test_assert("Booking discount percent is 0.0", (float)($res['discount_percent'] ?? 0.0) === 0.0);
    
    // Test 2: Voucher Validation for WELCOME50
    echo "\n--- Test 2: Validate WELCOME50 Voucher (Valid Spend) ---\n";
    $ch = curl_init('http://localhost/AnyBuddy/ajax_validate_voucher.php?code=WELCOME50&base_price=150.00');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Status is success", ($res['status'] ?? '') === 'success');
    test_assert("Voucher code matches WELCOME50", ($res['code'] ?? '') === 'WELCOME50');
    test_assert("Discount amount is ₱50.00", (float)($res['discount_amount'] ?? 0.0) === 50.0);

    echo "\n--- Test 2b: Validate WELCOME50 Voucher (Below Min Spend) ---\n";
    $ch = curl_init('http://localhost/AnyBuddy/ajax_validate_voucher.php?code=WELCOME50&base_price=80.00');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Status is error", ($res['status'] ?? '') === 'error');
    test_assert("Contains correct error message", str_contains($res['message'] ?? '', 'minimum spend'));

    // Test 3: Booking creation with Bronze Tier (5% Platform Fee, 0% discount)
    echo "\n--- Test 3: Create Booking (John Doe, Bronze, No Voucher) ---\n";
    $payload = [
        'user_id' => 13,
        'buddy_profile_id' => 1, // Angelo Maduro (hourly rate: ₱400.00)
        'booking_date' => date('Y-m-d', strtotime('+2 days')),
        'start_time' => '10:00',
        'hours_duration' => 2.0, // Subtotal: ₱800.00
        'message' => 'Need protection details.',
        'payment_method' => 'Card',
        'card_number' => '1234123412341234',
        'expiration' => '12/28',
        'cvv' => '123',
        'voucher_code' => ''
    ];
    
    $ch = curl_init('http://localhost/AnyBuddy/ajax_bookings.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Booking request status is success", ($res['status'] ?? '') === 'success');
    
    // Fetch user bookings and verify pricing breakdown
    $ch = curl_init('http://localhost/AnyBuddy/ajax_bookings.php?user_id=13&role=client');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resList = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    $booking = $resList['bookings'][0] ?? null;
    test_assert("Booking exists in client list", $booking !== null);
    if ($booking) {
        test_assert("Base price is ₱800.00", (float)$booking['base_price'] === 800.0);
        test_assert("Discount amount is ₱0.00", (float)$booking['discount_amount'] === 0.0);
        test_assert("Platform fee is ₱40.00 (5%)", (float)$booking['platform_fee'] === 40.0);
        test_assert("Total price is ₱840.00", (float)$booking['total_price'] === 840.0);
    }
    
    // Test 4: Booking creation with Bronze Tier + WELCOME50 Voucher
    echo "\n--- Test 4: Create Booking (John Doe, Bronze, WELCOME50) ---\n";
    $payload = [
        'user_id' => 13,
        'buddy_profile_id' => 2, // Emmanuel Creo (hourly rate: ₱350.00)
        'booking_date' => date('Y-m-d', strtotime('+3 days')),
        'start_time' => '14:00',
        'hours_duration' => 2.0, // Subtotal: ₱700.00
        'message' => 'Help with cabinet.',
        'payment_method' => 'Card',
        'card_number' => '1234123412341234',
        'expiration' => '12/28',
        'cvv' => '123',
        'voucher_code' => 'WELCOME50' // Should deduct ₱50.00, subtotal becomes ₱650.00, platform fee (5%) = ₱32.50, total = ₱682.50
    ];
    
    $ch = curl_init('http://localhost/AnyBuddy/ajax_bookings.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Booking request with voucher is success", ($res['status'] ?? '') === 'success');
    
    // Fetch user bookings and verify pricing breakdown
    $ch = curl_init('http://localhost/AnyBuddy/ajax_bookings.php?user_id=13&role=client');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resList = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    // Find the latest booking (should be first)
    $booking = null;
    foreach ($resList['bookings'] as $b) {
        if ($b['buddy_name'] === 'Emmanuel Creo') {
            $booking = $b;
            break;
        }
    }
    test_assert("Emmanuel Creo booking exists in list", $booking !== null);
    if ($booking) {
        test_assert("Base price is ₱700.00", (float)$booking['base_price'] === 700.0);
        test_assert("Discount amount is ₱50.00", (float)$booking['discount_amount'] === 50.0);
        test_assert("Platform fee is ₱32.50 (5% of ₱650)", (float)$booking['platform_fee'] === 32.5);
        test_assert("Total price is ₱682.50", (float)$booking['total_price'] === 682.5);
    }
    
    // Test 5: Verify Tier progression benefits
    echo "\n--- Test 5: Tier progression ---\n";
    // We will manually complete 3 bookings for John Doe in database to advance him to Silver Tier
    echo "Simulating 3 completed bookings for John Doe...\n";
    
    // Insert 3 mock completed bookings
    $pdo->exec("
        INSERT INTO `tbl_bookings` 
            (`client_id`, `buddy_profile_id`, `booking_date`, `start_time`, `hours_duration`, `base_price`, `discount_amount`, `platform_fee`, `total_price`, `status_id`, `payment_method`)
        VALUES 
            (13, 1, '2026-05-10', '10:00', 1.0, 400.00, 0.0, 20.00, 420.00, 4, 'Card'),
            (13, 1, '2026-05-11', '10:00', 1.0, 400.00, 0.0, 20.00, 420.00, 4, 'Card'),
            (13, 1, '2026-05-12', '10:00', 1.0, 400.00, 0.0, 20.00, 420.00, 4, 'Card')
    ");
    
    // Fetch updated tier
    $ch = curl_init('http://localhost/AnyBuddy/ajax_get_user_tier.php?user_id=13');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    test_assert("Completed bookings count is at least 3", ($res['completed_bookings'] ?? 0) >= 3);
    test_assert("John Doe advanced to Silver Tier", ($res['tier_name'] ?? '') === 'Silver');
    test_assert("Silver fee percent is 4.0%", (float)($res['platform_fee_percent'] ?? 0.0) === 4.0);
    test_assert("Silver discount percent is 2.0%", (float)($res['discount_percent'] ?? 0.0) === 2.0);

} catch (Exception $e) {
    echo "Exception during tests: " . $e->getMessage() . "\n";
}
