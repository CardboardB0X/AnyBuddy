<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Safety Emergency Alert Dispatcher
 *  File   : ajax_safety_alert.php
 *  Method : POST (simulates emergency alert)
 *  Returns: JSON
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = ab_pdo();

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
        exit;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_POST;
    }

    $userId = ab_require_auth();
    $bookingId = (int) ($input['booking_id'] ?? 0);

    if ($bookingId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid parameters. Booking ID is required.']);
        exit;
    }

    // 1. Fetch user emergency contacts
    $userStmt = $pdo->prepare("
        SELECT u.`first_name`, u.`last_name`, ec.`contact_name` AS `emergency_name`, ec.`contact_email` AS `emergency_email`, ec.`contact_phone` AS `emergency_phone` 
        FROM `tbl_users` u
        LEFT JOIN `tbl_emergency_contacts` ec ON ec.`user_id` = u.`user_id`
        WHERE u.`user_id` = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    if (empty($user['emergency_name']) || (empty($user['emergency_email']) && empty($user['emergency_phone']))) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Emergency contacts are not configured. Please edit your profile to add an emergency name and email/phone first.'
        ]);
        exit;
    }

    // 2. Fetch booking details
    $bookingStmt = $pdo->prepare("
        SELECT b.`booking_date`, b.`start_time`, b.`hours_duration`, bp.`display_name` AS buddy_name 
        FROM `tbl_bookings` b
        INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
        WHERE b.`booking_id` = ? AND b.`client_id` = ?
    ");
    $bookingStmt->execute([$bookingId, $userId]);
    $booking = $bookingStmt->fetch();

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Booking not found or does not belong to you.']);
        exit;
    }

    // 3. Format emergency email body
    $timestamp = date('Y-m-d H:i:s');
    $clientName = $user['first_name'] . ' ' . $user['last_name'];
    $emName = $user['emergency_name'];
    $emEmail = $user['emergency_email'] ?? 'N/A';
    $emPhone = $user['emergency_phone'] ?? 'N/A';
    
    $bookingDate = $booking['booking_date'];
    $bookingTime = $booking['start_time'];
    $buddyName = $booking['buddy_name'];
    $duration = $booking['hours_duration'];

    $subject = "ALERT: AnyBuddy Safety Shield Activated by {$clientName}";
    
    $messageBody = "
=========================================
SIMULATED EMERGENCY NOTIFICATION DISPATCHED
=========================================
Timestamp       : {$timestamp}
Recipient Name  : {$emName}
Recipient Email : {$emEmail}
Recipient Phone : {$emPhone}
-----------------------------------------
Sender (Client) : {$clientName}
Selected Buddy  : {$buddyName}
Booking Details : Date: {$bookingDate}, Time: {$bookingTime}, Duration: {$duration} hours
Status          : Safety Shield Triggered. Client indicated a possible safety concern or requested immediate check-in.
-----------------------------------------
This is a simulated automated emergency alert dispatched by the AnyBuddy Safety Center.
=========================================
\n";

    // Write to scratch log file
    $logFile = __DIR__ . '/scratch/safety_alerts.log';
    file_put_contents($logFile, $messageBody, FILE_APPEND | LOCK_EX);

    // 4. Send notification in-app to the client confirming dispatch
    ab_add_notification(
        $pdo, 
        $userId, 
        "Safety Shield Activated", 
        "We have dispatched an emergency check-in alert to your contact {$emName} ({$emEmail}). Stay safe!",
        "bookings.html"
    );

    echo json_encode([
        'status' => 'success',
        'message' => "Safety Shield activated! Alert successfully sent to {$emName}.",
        'details' => [
            'recipient_name' => $emName,
            'recipient_email' => $emEmail,
            'recipient_phone' => $emPhone
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred on the server.',
        'detail' => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null
    ]);
    exit;
}
