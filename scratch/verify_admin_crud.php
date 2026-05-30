<?php
/**
 * ============================================================
 *  AnyBuddy — Admin CRUD Verification Test Suite
 *  File   : verify_admin_crud.php
 *  Usage  : run via php CLI to test all admin CRUD endpoints
 * ============================================================
 */

declare(strict_types=1);

function test_admin_endpoint(string $action, string $method, array $params = [], array $payload = []): array
{
    $url = "http://localhost/AnyBuddy/ajax_admin.php";
    
    // Admin credentials mock (user_id = 14 is Admin AnyBuddy)
    $params['user_id'] = 14;
    $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method === 'POST') {
        $payload['user_id'] = 14;
        $payload['action'] = $action;
        $jsonPayload = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['status' => 'curl_error', 'message' => $err, 'code' => $httpCode];
    }

    $decoded = json_decode($response, true);
    return [
        'http_code' => $httpCode,
        'response'  => $response,
        'decoded'   => $decoded
    ];
}

echo "=== ANYBUDDY ADMIN CRUD INTEGRATION TEST ===\n\n";

// Test 1: Fetch users list
echo "[Test 1] Fetching users list...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_users']);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['users'])) {
    echo "  ✅ Pass! Users count: " . count($res['decoded']['users']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 2: Fetch buddies list
echo "[Test 2] Fetching buddies list...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_buddies']);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['buddies'])) {
    echo "  ✅ Pass! Buddies count: " . count($res['decoded']['buddies']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 3: Fetch bookings list
echo "[Test 3] Fetching bookings list...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_bookings']);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['bookings'])) {
    echo "  ✅ Pass! Bookings count: " . count($res['decoded']['bookings']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 4: Create Voucher (CRUD - C)
$uniqueCode = 'TEST' . rand(100, 999);
echo "[Test 4] Creating voucher '{$uniqueCode}'...\n";
$res = test_admin_endpoint('create_voucher', 'POST', [], [
    'code' => $uniqueCode,
    'discount_type' => 'fixed',
    'discount_value' => 100.00,
    'min_spend' => 200.00,
    'is_active' => 1
]);
$voucherId = (int) ($res['decoded']['voucher_id'] ?? 0);
if (($res['decoded']['status'] ?? '') === 'success' && $voucherId > 0) {
    echo "  ✅ Pass! Voucher created with ID: " . $voucherId . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 5: Update Voucher (CRUD - U)
echo "[Test 5] Updating voucher '{$uniqueCode}' to min spend 250...\n";
$res = test_admin_endpoint('update_voucher', 'POST', [], [
    'voucher_id' => $voucherId,
    'code' => $uniqueCode,
    'discount_type' => 'fixed',
    'discount_value' => 100.00,
    'min_spend' => 250.00,
    'is_active' => 1
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Voucher updated successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 6: Verify Voucher Details (CRUD - R)
echo "[Test 6] Listing vouchers and verifying update...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_vouchers']);
$found = null;
foreach (($res['decoded']['vouchers'] ?? []) as $v) {
    if ((int)$v['voucher_id'] === $voucherId) {
        $found = $v;
        break;
    }
}
if ($found && (float)$found['min_spend'] === 250.00) {
    echo "  ✅ Pass! Found updated voucher '{$uniqueCode}' with correct min spend of 250.00.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 7: Delete Voucher (CRUD - D)
echo "[Test 7] Deleting voucher '{$uniqueCode}'...\n";
$res = test_admin_endpoint('delete_voucher', 'POST', [], [
    'voucher_id' => $voucherId
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Voucher deleted successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 8: Verify Deletion
echo "[Test 8] Listing vouchers again to verify deletion...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_vouchers']);
$deleted = true;
foreach (($res['decoded']['vouchers'] ?? []) as $v) {
    if ((int)$v['voucher_id'] === $voucherId) {
        $deleted = false;
        break;
    }
}
if ($deleted) {
    echo "  ✅ Pass! Voucher '{$uniqueCode}' is no longer in the database.\n";
} else {
    echo "  ❌ Fail! Voucher still exists.\n";
    print_r($res);
}
// Test 9: Create User (CRUD - C)
echo "[Test 9] Creating user 'CRUD Tester'...\n";
$res = test_admin_endpoint('create_user', 'POST', [], [
    'first_name' => 'CRUD',
    'last_name' => 'Tester',
    'email' => 'crudtester@anybuddy.ph',
    'password' => 'password123',
    'role' => 'client',
    'pronouns' => 'They/Them',
    'profile_photo' => 'images/user-light.png'
]);
$newUserId = (int) ($res['decoded']['user_id'] ?? 0);
if (($res['decoded']['status'] ?? '') === 'success' && $newUserId > 0) {
    echo "  ✅ Pass! User created with ID: " . $newUserId . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 10: Update User (CRUD - U)
echo "[Test 10] Updating user 'CRUD Tester' pronouns and role to buddy...\n";
$res = test_admin_endpoint('update_user', 'POST', [], [
    'target_user_id' => $newUserId,
    'first_name' => 'CRUD',
    'last_name' => 'Tester-Modified',
    'email' => 'crudtester@anybuddy.ph',
    'password' => '', // keep password
    'role' => 'client', // keep as client for now, we will let create_buddy change it to buddy
    'pronouns' => 'He/Him',
    'profile_photo' => 'images/user-light.png'
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! User updated successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 11: Create Buddy Profile (CRUD - C)
echo "[Test 11] Creating buddy profile for User ID: {$newUserId}...\n";
$res = test_admin_endpoint('create_buddy', 'POST', [], [
    'target_user_id' => $newUserId,
    'display_name' => 'CRUD Buddy Tester',
    'professional_title' => 'Software Quality Assurance',
    'category' => 'casual',
    'bio' => 'Automated test suite profile.',
    'hourly_rate' => 500.00,
    'location' => 'Cavite',
    'availability' => 'Mon-Fri, 9AM-5PM'
]);
$newBuddyProfileId = (int) ($res['decoded']['profile_id'] ?? 0);
if (($res['decoded']['status'] ?? '') === 'success' && $newBuddyProfileId > 0) {
    echo "  ✅ Pass! Buddy profile created with ID: " . $newBuddyProfileId . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 12: Update Buddy Profile (CRUD - U)
echo "[Test 12] Updating buddy profile rate to 600.00...\n";
$res = test_admin_endpoint('update_buddy', 'POST', [], [
    'profile_id' => $newBuddyProfileId,
    'display_name' => 'CRUD Buddy Tester (Updated)',
    'professional_title' => 'Lead Software Quality Assurance',
    'category' => 'casual',
    'bio' => 'Automated test suite profile updated.',
    'hourly_rate' => 600.00,
    'location' => 'Cavite',
    'availability' => 'Mon-Fri, 10AM-6PM',
    'is_available' => 1
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Buddy profile updated successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 13: Create Booking (CRUD - C)
echo "[Test 13] Creating booking between Client 13 and Buddy Profile: {$newBuddyProfileId}...\n";
$res = test_admin_endpoint('create_booking', 'POST', [], [
    'client_id' => 13, // John Doe Client
    'buddy_profile_id' => $newBuddyProfileId,
    'status_id' => 1, // Requested
    'booking_date' => date('Y-m-d', strtotime('+1 day')),
    'start_time' => '10:00:00',
    'hours_duration' => 2.00,
    'base_price' => 1200.00,
    'discount_amount' => 0.00,
    'platform_fee' => 60.00,
    'total_price' => 1260.00,
    'payment_method' => 'Card',
    'message' => 'CRUD Test Booking message.'
]);
$newBookingId = (int) ($res['decoded']['booking_id'] ?? 0);
if (($res['decoded']['status'] ?? '') === 'success' && $newBookingId > 0) {
    echo "  ✅ Pass! Booking created with ID: " . $newBookingId . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 14: Update Booking (CRUD - U)
echo "[Test 14] Updating booking ID {$newBookingId} to status 2 (Accepted)...\n";
$res = test_admin_endpoint('update_booking', 'POST', [], [
    'booking_id' => $newBookingId,
    'client_id' => 13,
    'buddy_profile_id' => $newBuddyProfileId,
    'status_id' => 2, // Accepted
    'booking_date' => date('Y-m-d', strtotime('+1 day')),
    'start_time' => '11:00:00',
    'hours_duration' => 2.00,
    'base_price' => 1200.00,
    'discount_amount' => 0.00,
    'platform_fee' => 60.00,
    'total_price' => 1260.00,
    'payment_method' => 'Card',
    'message' => 'CRUD Test Booking message updated.'
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Booking updated successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Seed a chat message using PDO for testing chat history endpoints
try {
    $pdo = new PDO("mysql:host=localhost;dbname=anybuddy_db;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $stmt = $pdo->prepare("INSERT INTO `tbl_messages` (`booking_id`, `sender_id`, `message_text`) VALUES (?, ?, ?)");
    $stmt->execute([$newBookingId, 13, 'Hello from automated CRUD and Chat history tester!']);
    echo "  ✅ Seeded a test chat message into tbl_messages for Booking ID: {$newBookingId}.\n";
} catch (Exception $e) {
    echo "  ❌ Failed to seed test chat message: " . $e->getMessage() . "\n";
}

// Test 14.1: Fetching Chat Threads List (R)
echo "[Test 14.1] Fetching chat threads list...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_chats']);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['chats'])) {
    echo "  ✅ Pass! Chat threads count: " . count($res['decoded']['chats']) . "\n";
    $foundChat = false;
    foreach ($res['decoded']['chats'] as $chat) {
        if ((int)$chat['booking_id'] === $newBookingId) {
            $foundChat = true;
            break;
        }
    }
    if ($foundChat) {
        echo "  ✅ Pass! Found chat thread for Booking ID {$newBookingId}.\n";
    } else {
        echo "  ❌ Fail! Chat thread not found in list.\n";
    }
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 14.2: Fetching messages of a chat thread (R)
echo "[Test 14.2] Fetching messages for booking thread {$newBookingId}...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'get_chat_messages', 'booking_id' => $newBookingId]);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['messages'])) {
    echo "  ✅ Pass! Messages count in thread: " . count($res['decoded']['messages']) . "\n";
    if (count($res['decoded']['messages']) > 0) {
        echo "  ✅ Pass! Message content: \"" . $res['decoded']['messages'][0]['message_text'] . "\"\n";
    } else {
        echo "  ❌ Fail! Thread is empty.\n";
    }
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 15: Delete Booking (CRUD - D)
echo "[Test 15] Deleting booking ID {$newBookingId}...\n";
$res = test_admin_endpoint('delete_booking', 'POST', [], [
    'booking_id' => $newBookingId
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Booking deleted successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 16: Delete Buddy (CRUD - D)
echo "[Test 16] Deleting buddy profile ID {$newBuddyProfileId}...\n";
$res = test_admin_endpoint('delete_buddy', 'POST', [], [
    'profile_id' => $newBuddyProfileId
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Buddy profile deleted successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 17: Delete User (CRUD - D)
echo "[Test 17] Deleting user ID {$newUserId}...\n";
$res = test_admin_endpoint('delete_user', 'POST', [], [
    'target_user_id' => $newUserId
]);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! User deleted successfully.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 18: Fetching Audit Logs (R)
echo "[Test 18] Fetching audit logs list...\n";
$res = test_admin_endpoint('', 'GET', ['action' => 'list_audit_logs']);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['logs'])) {
    echo "  ✅ Pass! Audit logs count: " . count($res['decoded']['logs']) . "\n";
    // Check if the initial system log and mutations logs exist
    $foundSysInit = false;
    $foundCreateUser = false;
    $foundCreateBooking = false;
    foreach ($res['decoded']['logs'] as $log) {
        if ($log['action'] === 'system_init') $foundSysInit = true;
        if ($log['action'] === 'create_user' && (int)$log['entity_id'] === $newUserId) $foundCreateUser = true;
        if ($log['action'] === 'create_booking' && (int)$log['entity_id'] === $newBookingId) $foundCreateBooking = true;
    }
    if ($foundSysInit) echo "  ✅ Pass! Found system_init log record.\n";
    else echo "  ❌ Fail! system_init log record not found.\n";

    if ($foundCreateUser) echo "  ✅ Pass! Found create_user log record for User ID {$newUserId}.\n";
    else echo "  ❌ Fail! create_user log record not found.\n";

    if ($foundCreateBooking) echo "  ✅ Pass! Found create_booking log record for Booking ID {$newBookingId}.\n";
    else echo "  ❌ Fail! create_booking log record not found.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

echo "\nAll CRUD and dashboard overhaul verification tests complete.\n";
