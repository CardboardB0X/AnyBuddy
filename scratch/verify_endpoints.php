<?php
/**
 * ============================================================
 *  AnyBuddy — Verification Test Suite
 *  File   : verify_endpoints.php
 *  Usage  : run via php CLI to test all refactored AJAX endpoints
 * ============================================================
 */

declare(strict_types=1);

// Helper to simulate request
function test_endpoint(string $filename, string $method, array $params = [], array $payload = []): array
{
    // Setup superglobals to mock the request
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $params;
    $_POST = $payload;

    if ($method === 'POST') {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        // Mock php://input content using a stream filter or temporary file replacement isn't trivial,
        // so we modify the endpoints to fallback to $_POST which we populated,
        // or we override file_get_contents inside the target file context.
        // To keep it simple, we can run these tests via HTTP requests using curl!
    }

    // Actually, curl via HTTP localhost is much more realistic!
    $url = "http://localhost/AnyBuddy/" . $filename;
    if ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($method === 'POST') {
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

echo "=== ANYBUDDY 3NF BACKEND VERIFICATION ===\n\n";

// Test 1: login with valid buddy (Angelo Maduro)
echo "[Test 1] Login as Angelo Maduro (valid buddy)...\n";
$res = test_endpoint('ajax_login.php', 'POST', [], [
    'email' => 'angelo@anybuddy.ph',
    'password' => 'password123'
]);
if (($res['decoded']['status'] ?? '') === 'success' && $res['decoded']['user']['is_buddy'] === true) {
    echo "  ✅ Pass! Logged in as: " . $res['decoded']['user']['first_name'] . " (Buddy ID: " . $res['decoded']['user']['buddy_profile_id'] . ")\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 2: login with valid admin (Admin AnyBuddy)
echo "[Test 2] Login as Admin...\n";
$res = test_endpoint('ajax_login.php', 'POST', [], [
    'email' => 'admin@anybuddy.ph',
    'password' => 'password123'
]);
if (($res['decoded']['status'] ?? '') === 'success' && $res['decoded']['user']['role'] === 'admin') {
    echo "  ✅ Pass! Logged in as Admin.\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 3: Marketplace fetching
echo "[Test 3] Fetching marketplace buddies...\n";
$res = test_endpoint('ajax_marketplace.php', 'GET', ['sort' => 'recommended', 'per_page' => 5]);
if (($res['decoded']['status'] ?? '') === 'success' && is_array($res['decoded']['buddies'])) {
    echo "  ✅ Pass! Fetched " . count($res['decoded']['buddies']) . " buddies. First buddy: " . $res['decoded']['buddies'][0]['display_name'] . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 4: Profile details page (Angelo Maduro, profile_id = 1)
echo "[Test 4] Fetching Angelo Maduro profile (ID 1)...\n";
$res = test_endpoint('ajax_profile.php', 'GET', ['id' => 1]);
if (($res['decoded']['status'] ?? '') === 'success' && $res['decoded']['buddy']['display_name'] === 'Angelo Maduro') {
    echo "  ✅ Pass! Display Name: " . $res['decoded']['buddy']['display_name'] . ", Languages: " . implode(', ', $res['decoded']['buddy']['languages']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 5: Fetch bookings list for client (John Doe, user_id = 13)
echo "[Test 5] Fetching bookings for client John Doe (user_id 13)...\n";
$res = test_endpoint('ajax_bookings.php', 'GET', ['user_id' => 13, 'role' => 'client']);
if (($res['decoded']['status'] ?? '') === 'success') {
    echo "  ✅ Pass! Bookings count: " . count($res['decoded']['bookings']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 6: Fetch availability slots for buddy_profile_id = 1
echo "[Test 6] Fetching availability slots for profile 1...\n";
$res = test_endpoint('ajax_availability.php', 'GET', ['buddy_profile_id' => 1]);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['slots'])) {
    echo "  ✅ Pass! Slots count: " . count($res['decoded']['slots']) . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

// Test 7: Fetch admin stats (Admin, user_id = 14)
echo "[Test 7] Fetching Admin Portal details (user_id 14)...\n";
$res = test_endpoint('ajax_admin.php', 'GET', ['user_id' => 14]);
if (($res['decoded']['status'] ?? '') === 'success' && isset($res['decoded']['stats'])) {
    echo "  ✅ Pass! Total users: " . $res['decoded']['stats']['total_users'] . ", Total Bookings: " . $res['decoded']['stats']['total_bookings'] . "\n";
} else {
    echo "  ❌ Fail!\n";
    print_r($res);
}

echo "\nVerification complete.\n";
