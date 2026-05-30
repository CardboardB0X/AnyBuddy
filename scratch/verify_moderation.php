<?php
/**
 * ============================================================
 *  AnyBuddy — Moderation (Suspend & Ban) Verification
 *  File   : verify_moderation.php
 *  Usage  : run via php CLI to test suspension, banning, login
 *           blocking, and ticket resolution flows.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../db_connect.php';

// Helper to simulate request via cURL
function test_endpoint(string $filename, string $method, array $params = [], array $payload = []): array
{
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

echo "=== ANYBUDDY MODERATION VERIFICATION ===\n\n";

try {
    $pdo = ab_pdo();

    // 1. Ensure Admin exists
    $adminEmail = 'admin@anybuddy.ph';
    $stmt = $pdo->prepare("SELECT `user_id` FROM `tbl_users` WHERE `email` = ? AND `role` = 'admin'");
    $stmt->execute([$adminEmail]);
    $adminId = $stmt->fetchColumn();
    if (!$adminId) {
        // Create Admin
        $passHash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO `tbl_users` (`first_name`, `last_name`, `email`, `password_hash`, `role`, `status`, `is_active`) VALUES ('Admin', 'Moderator', ?, ?, 'admin', 'active', 1)");
        $ins->execute([$adminEmail, $passHash]);
        $adminId = $pdo->lastInsertId();
        echo "Created mock admin user (ID: $adminId)\n";
    } else {
        echo "Found existing admin user (ID: $adminId)\n";
    }

    // 2. Create mock reporter user
    $reporterEmail = 'reporter@test.com';
    $pdo->prepare("DELETE FROM `tbl_users` WHERE `email` = ?")->execute([$reporterEmail]);
    $passHash = password_hash('password123', PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO `tbl_users` (`first_name`, `last_name`, `email`, `password_hash`, `role`, `status`, `is_active`) VALUES ('John', 'Reporter', ?, ?, 'client', 'active', 1)");
    $ins->execute([$reporterEmail, $passHash]);
    $reporterId = $pdo->lastInsertId();
    echo "Created mock reporter user (ID: $reporterId)\n";

    // 3. Create mock reported user
    $reportedEmail = 'baduser@test.com';
    $pdo->prepare("DELETE FROM `tbl_users` WHERE `email` = ?")->execute([$reportedEmail]);
    $ins = $pdo->prepare("INSERT INTO `tbl_users` (`first_name`, `last_name`, `email`, `password_hash`, `role`, `status`, `is_active`) VALUES ('Trouble', 'Maker', ?, ?, 'buddy', 'active', 1)");
    $ins->execute([$reportedEmail, $passHash]);
    $reportedId = $pdo->lastInsertId();
    echo "Created mock reported user (ID: $reportedId)\n";

    // Create a buddy profile for the reported user to verify marketplace hiding and profile viewing
    $insBuddy = $pdo->prepare("INSERT INTO `tbl_buddy_profiles` (`user_id`, `display_name`, `professional_title`, `category`, `bio`, `hourly_rate`, `location`, `availability`, `is_available`) VALUES (?, 'Trouble Maker', 'Party Buddy', 'Event', 'Always causing problems.', 150.00, 'Manila', 'flexible', 1)");
    $insBuddy->execute([$reportedId]);
    $buddyProfileId = $pdo->lastInsertId();
    echo "Created buddy profile for reported user (ID: $buddyProfileId)\n";

    // 4. Create mock safety report ticket
    $insReport = $pdo->prepare("INSERT INTO `tbl_reports` (`reporter_id`, `reported_id`, `reason`, `description`, `status`) VALUES (?, ?, 'Harassment', 'User was offensive.', 'pending')");
    $insReport->execute([$reporterId, $reportedId]);
    $reportId = $pdo->lastInsertId();
    echo "Created mock report ticket #$reportId for user ID $reportedId\n\n";

    // ============================================================
    // TEST 1: SUSPEND REPORTED USER
    // ============================================================
    echo "[TEST 1] Suspend reported user via ajax_admin.php...\n";
    $res = test_endpoint('ajax_admin.php', 'POST', [], [
        'user_id' => $adminId,
        'report_id' => $reportId,
        'action' => 'suspend_reported_user'
    ]);

    if (($res['decoded']['status'] ?? '') === 'success') {
        echo "  ✅ Pass! Admin endpoint returned success.\n";
    } else {
        echo "  ❌ Fail! Admin endpoint response:\n";
        print_r($res);
        exit(1);
    }

    // Verify user state in DB
    $userCheck = $pdo->prepare("SELECT `status`, `is_active`, `status_reason` FROM `tbl_users` WHERE `user_id` = ?");
    $userCheck->execute([$reportedId]);
    $userRow = $userCheck->fetch();

    if ($userRow['status'] === 'suspended' && (int)$userRow['is_active'] === 0) {
        echo "  ✅ Pass! User is suspended and deactivated in DB. Reason: {$userRow['status_reason']}\n";
    } else {
        echo "  ❌ Fail! User DB state: Status = {$userRow['status']}, Active = {$userRow['is_active']}\n";
    }

    // Verify report ticket resolved
    $reportCheck = $pdo->prepare("SELECT `status` FROM `tbl_reports` WHERE `report_id` = ?");
    $reportCheck->execute([$reportId]);
    if ($reportCheck->fetchColumn() === 'resolved') {
        echo "  ✅ Pass! Report ticket status updated to resolved.\n";
    } else {
        echo "  ❌ Fail! Report ticket not resolved.\n";
    }

    // Verify audit log
    $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM `tbl_audit_logs` WHERE `action` = 'suspend_reported_user' AND `entity_id` = ?");
    $auditCheck->execute([$reportedId]);
    if ($auditCheck->fetchColumn() > 0) {
        echo "  ✅ Pass! Audit log entry created.\n";
    } else {
        echo "  ❌ Fail! No audit log found.\n";
    }

    // Verify login attempt fails with 403 Forbidden
    echo "[TEST 1.1] Attempt login as suspended user...\n";
    $loginRes = test_endpoint('ajax_login.php', 'POST', [], [
        'email' => $reportedEmail,
        'password' => 'password123'
    ]);
    if ($loginRes['http_code'] === 403 && ($loginRes['decoded']['status'] ?? '') === 'error') {
        echo "  ✅ Pass! Login blocked. Message: " . $loginRes['decoded']['message'] . "\n";
    } else {
        echo "  ❌ Fail! Login should have returned 403, got code: " . $loginRes['http_code'] . "\n";
        print_r($loginRes);
    }

    // Verify profile page returns 404
    echo "[TEST 1.2] Attempt fetch suspended profile...\n";
    $profileRes = test_endpoint('ajax_profile.php', 'GET', ['id' => $buddyProfileId]);
    if ($profileRes['decoded']['status'] === 'error') {
        echo "  ✅ Pass! Profile view blocked. Message: " . $profileRes['decoded']['message'] . "\n";
    } else {
        echo "  ❌ Fail! Profile view allowed for suspended user.\n";
        print_r($profileRes);
    }

    // Verify buddy is excluded from marketplace
    echo "[TEST 1.3] Check marketplace query excludes suspended buddy...\n";
    $marketRes = test_endpoint('ajax_marketplace.php', 'GET', ['sort' => 'recommended']);
    $found = false;
    foreach (($marketRes['decoded']['buddies'] ?? []) as $b) {
        if ($b['user_id'] == $reportedId) {
            $found = true;
        }
    }
    if (!$found) {
        echo "  ✅ Pass! Suspended buddy is not in the marketplace list.\n";
    } else {
        echo "  ❌ Fail! Suspended buddy was found in the marketplace list.\n";
    }

    // ============================================================
    // TEST 2: BAN REPORTED USER
    // ============================================================
    echo "\n[TEST 2] Ban reported user via ajax_admin.php...\n";
    // Reset user to active and report ticket to pending to test the ban action
    $pdo->prepare("UPDATE `tbl_users` SET `status` = 'active', `is_active` = 1, `status_reason` = NULL WHERE `user_id` = ?")->execute([$reportedId]);
    $pdo->prepare("UPDATE `tbl_reports` SET `status` = 'pending' WHERE `report_id` = ?")->execute([$reportId]);

    $res = test_endpoint('ajax_admin.php', 'POST', [], [
        'user_id' => $adminId,
        'report_id' => $reportId,
        'action' => 'ban_reported_user'
    ]);

    if (($res['decoded']['status'] ?? '') === 'success') {
        echo "  ✅ Pass! Admin endpoint returned success.\n";
    } else {
        echo "  ❌ Fail! Admin endpoint response:\n";
        print_r($res);
        exit(1);
    }

    // Verify user state in DB
    $userCheck->execute([$reportedId]);
    $userRow = $userCheck->fetch();

    if ($userRow['status'] === 'banned' && (int)$userRow['is_active'] === 0) {
        echo "  ✅ Pass! User is banned and deactivated in DB. Reason: {$userRow['status_reason']}\n";
    } else {
        echo "  ❌ Fail! User DB state: Status = {$userRow['status']}, Active = {$userRow['is_active']}\n";
    }

    // Verify report ticket resolved
    $reportCheck->execute([$reportId]);
    if ($reportCheck->fetchColumn() === 'resolved') {
        echo "  ✅ Pass! Report ticket status updated to resolved.\n";
    } else {
        echo "  ❌ Fail! Report ticket not resolved.\n";
    }

    // Verify audit log
    $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM `tbl_audit_logs` WHERE `action` = 'ban_reported_user' AND `entity_id` = ?");
    $auditCheck->execute([$reportedId]);
    if ($auditCheck->fetchColumn() > 0) {
        echo "  ✅ Pass! Audit log entry created.\n";
    } else {
        echo "  ❌ Fail! No audit log found.\n";
    }

    // Verify login attempt fails with 403 Forbidden
    echo "[TEST 2.1] Attempt login as banned user...\n";
    $loginRes = test_endpoint('ajax_login.php', 'POST', [], [
        'email' => $reportedEmail,
        'password' => 'password123'
    ]);
    if ($loginRes['http_code'] === 403 && ($loginRes['decoded']['status'] ?? '') === 'error') {
        echo "  ✅ Pass! Login blocked. Message: " . $loginRes['decoded']['message'] . "\n";
    } else {
        echo "  ❌ Fail! Login should have returned 403, got code: " . $loginRes['http_code'] . "\n";
        print_r($loginRes);
    }

    // Clean up mock data
    $pdo->prepare("DELETE FROM `tbl_reports` WHERE `report_id` = ?")->execute([$reportId]);
    $pdo->prepare("DELETE FROM `tbl_buddy_profiles` WHERE `profile_id` = ?")->execute([$buddyProfileId]);
    $pdo->prepare("DELETE FROM `tbl_users` WHERE `user_id` IN (?, ?)")->execute([$reporterId, $reportedId]);
    $pdo->prepare("DELETE FROM `tbl_notifications` WHERE `user_id` = ?")->execute([$reporterId]);
    $pdo->prepare("DELETE FROM `tbl_audit_logs` WHERE `entity_id` = ? AND `entity_type` = 'users'")->execute([$reportedId]);
    echo "\nCleaned up mock data successfully.\n";

} catch (Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
}

echo "\nVerification complete.\n";
