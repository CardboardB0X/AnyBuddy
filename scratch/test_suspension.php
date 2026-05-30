<?php
/**
 * AnyBuddy — Suspension Test Suite
 * File: scratch/test_suspension.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../db_connect.php';

$pdo = ab_pdo();

// Config
$baseUrl = "http://localhost/AnyBuddy/scratch/test_handler.php";
$cookieFile = __DIR__ . '/test_cookies.txt';

// Ensure cookie file is clean
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function curl_request(string $url) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $http_code,
        'body' => json_decode($response, true) ?: $response
    ];
}

echo "========================================================\n";
echo " AnyBuddy — Suspension Verification Suite\n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $description, bool $condition) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "✅ PASS: $description\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: $description\n";
        $testsFailed++;
    }
}

try {
    // ----------------------------------------------------
    // TEST 1: Unauthenticated request
    // ----------------------------------------------------
    echo "Running Test 1: Request without session...\n";
    $res = curl_request("$baseUrl?action=test_auth");
    assertTest("HTTP status is 401", $res['code'] === 401);
    assertTest("Error status returned", is_array($res['body']) && $res['body']['status'] === 'error');
    assertTest("Error message contains Please log in", is_array($res['body']) && strpos($res['body']['message'], 'Please log in') !== false);

    // ----------------------------------------------------
    // Insert temporary user
    // ----------------------------------------------------
    echo "\nInserting temporary test user...\n";
    $email = "test_suspend_user_" . time() . "@example.com";
    $pwdHash = password_hash("password123", PASSWORD_BCRYPT);
    $pdo->prepare("
        INSERT INTO `tbl_users` (`first_name`, `last_name`, `email`, `password_hash`, `role`, `status`, `is_active`)
        VALUES ('Test', 'Suspend', ?, ?, 'client', 'active', 1)
    ")->execute([$email, $pwdHash]);
    $tempUserId = (int)$pdo->lastInsertId();
    echo "Temp user created with ID: $tempUserId\n";

    // ----------------------------------------------------
    // TEST 2: Active User Authentication
    // ----------------------------------------------------
    echo "\nRunning Test 2: Authenticating active user...\n";
    $sessRes = curl_request("$baseUrl?action=set_session&user_id=$tempUserId&role=client");
    assertTest("Set session call succeeded", $sessRes['code'] === 200);

    $authRes = curl_request("$baseUrl?action=test_auth");
    assertTest("HTTP status is 200", $authRes['code'] === 200);
    assertTest("Returns correct user_id", is_array($authRes['body']) && ($authRes['body']['user_id'] ?? 0) === $tempUserId);

    // ----------------------------------------------------
    // TEST 3: Suspended mid-session
    // ----------------------------------------------------
    echo "\nRunning Test 3: Suspend user mid-session...\n";
    $pdo->prepare("UPDATE `tbl_users` SET `status` = 'suspended', `is_active` = 0 WHERE `user_id` = ?")->execute([$tempUserId]);
    
    $susRes = curl_request("$baseUrl?action=test_auth");
    assertTest("HTTP status is 403", $susRes['code'] === 403);
    assertTest("Error message contains suspended", is_array($susRes['body']) && strpos($susRes['body']['message'], 'suspended') !== false);

    // Verify session was destroyed
    $afterRes = curl_request("$baseUrl?action=test_auth");
    assertTest("Subsequent request is 401 (session destroyed)", $afterRes['code'] === 401);

    // ----------------------------------------------------
    // TEST 4: Banned mid-session
    // ----------------------------------------------------
    echo "\nRunning Test 4: Banned user mid-session...\n";
    // Reactivate user first
    $pdo->prepare("UPDATE `tbl_users` SET `status` = 'active', `is_active` = 1 WHERE `user_id` = ?")->execute([$tempUserId]);
    
    // Set session again
    curl_request("$baseUrl?action=set_session&user_id=$tempUserId&role=client");
    
    // Ban user
    $pdo->prepare("UPDATE `tbl_users` SET `status` = 'banned', `is_active` = 0 WHERE `user_id` = ?")->execute([$tempUserId]);
    
    $banRes = curl_request("$baseUrl?action=test_auth");
    assertTest("HTTP status is 403", $banRes['code'] === 403);
    assertTest("Error message contains permanently banned", is_array($banRes['body']) && strpos($banRes['body']['message'], 'permanently banned') !== false);

    // Verify session was destroyed
    $afterBanRes = curl_request("$baseUrl?action=test_auth");
    assertTest("Subsequent request is 401 (session destroyed)", $afterBanRes['code'] === 401);

    // ----------------------------------------------------
    // TEST 5: Deactivated (is_active = 0) mid-session
    // ----------------------------------------------------
    echo "\nRunning Test 5: Disabled user (is_active = 0) mid-session...\n";
    // Reactivate user first
    $pdo->prepare("UPDATE `tbl_users` SET `status` = 'active', `is_active` = 1 WHERE `user_id` = ?")->execute([$tempUserId]);
    
    // Set session again
    curl_request("$baseUrl?action=set_session&user_id=$tempUserId&role=client");
    
    // Deactivate user
    $pdo->prepare("UPDATE `tbl_users` SET `is_active` = 0 WHERE `user_id` = ?")->execute([$tempUserId]);
    
    $deactRes = curl_request("$baseUrl?action=test_auth");
    assertTest("HTTP status is 403", $deactRes['code'] === 403);
    assertTest("Error message contains disabled or inactive", is_array($deactRes['body']) && strpos($deactRes['body']['message'], 'disabled or inactive') !== false);

    // Verify session was destroyed
    $afterDeactRes = curl_request("$baseUrl?action=test_auth");
    assertTest("Subsequent request is 401 (session destroyed)", $afterDeactRes['code'] === 401);

    // ----------------------------------------------------
    // Clean up
    // ----------------------------------------------------
    echo "\nCleaning up database and files...\n";
    $pdo->prepare("DELETE FROM `tbl_users` WHERE `user_id` = ?")->execute([$tempUserId]);
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    echo "Cleanup complete.\n";

} catch (Exception $e) {
    echo "An exception occurred: " . $e->getMessage() . "\n";
    // Ensure clean up is run if possible
    if (isset($tempUserId)) {
        $pdo->prepare("DELETE FROM `tbl_users` WHERE `user_id` = ?")->execute([$tempUserId]);
    }
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}

echo "\n========================================================\n";
echo " Verification Complete\n";
echo " PASSED: $testsPassed\n";
echo " FAILED: $testsFailed\n";
echo "========================================================\n";
exit($testsFailed > 0 ? 1 : 0);
