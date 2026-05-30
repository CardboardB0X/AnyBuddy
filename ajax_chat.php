<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Chat Manager
 *  File   : ajax_chat.php
 *  Method : GET (fetch messages & info) or POST (send message)
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

    if ($method === 'GET') {
        // --- FETCH CHAT HISTORY & CONVERSATION INFO ---
        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $userId = ab_require_auth();

        if ($bookingId <= 0) {
            // --- LIST ALL CHATS FOR THIS USER ---
            $sql = "
                SELECT 
                    b.`booking_id` AS `id`,
                    b.`booking_date`,
                    b.`start_time`,
                    b.`status_id`,
                    bs.`status_name` AS `status`,
                    b.`client_id`,
                    cu.`first_name` AS client_first_name,
                    cu.`last_name` AS client_last_name,
                    cu.`profile_photo` AS client_avatar,
                    bp.`profile_id` AS buddy_profile_id,
                    bp.`user_id` AS buddy_user_id,
                    bp.`display_name` AS buddy_name,
                    bu.`profile_photo` AS buddy_avatar,
                    (SELECT message_text FROM `tbl_messages` WHERE booking_id = b.booking_id ORDER BY created_at DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM `tbl_messages` WHERE booking_id = b.booking_id ORDER BY created_at DESC LIMIT 1) AS last_message_time
                FROM `tbl_bookings` b
                INNER JOIN `tbl_users` cu ON cu.`user_id` = b.`client_id`
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                INNER JOIN `tbl_users` bu ON bu.`user_id` = bp.`user_id`
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                WHERE b.`client_id` = ? OR bp.`user_id` = ?
                ORDER BY COALESCE(last_message_time, b.created_at) DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $userId]);
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            
            $formatted = [];
            foreach ($threads as $t) {
                // Determine other participant name and avatar
                $isUserClient = ($userId === (int) $t['client_id']);
                $otherName = $isUserClient ? $t['buddy_name'] : ($t['client_first_name'] . ' ' . $t['client_last_name']);
                $otherAvatar = $isUserClient ? $t['buddy_avatar'] : $t['client_avatar'];
                if (!$otherAvatar) {
                    $otherAvatar = 'images/user-light.png';
                }
                
                // Format last message time
                $niceTime = '';
                if ($t['last_message_time']) {
                    $msgDate = new DateTime($t['last_message_time']);
                    $today = new DateTime('today');
                    if ($msgDate->format('Y-m-d') === $today->format('Y-m-d')) {
                        $niceTime = $msgDate->format('g:i A');
                    } else {
                        $niceTime = $msgDate->format('M d');
                    }
                }
                
                $formatted[] = [
                    'booking_id' => (int) $t['id'],
                    'other_name' => $otherName,
                    'other_avatar' => $otherAvatar,
                    'last_message' => $t['last_message'] ?: 'No messages yet.',
                    'last_message_time' => $niceTime,
                    'status' => $t['status']
                ];
            }
            
            echo json_encode([
                'status' => 'success',
                'chats' => $formatted
            ]);
            exit;
        }

        // Fetch booking, client, and buddy profile info
        $stmt = $pdo->prepare("
            SELECT 
                b.`booking_id`,
                b.`client_id`,
                b.`buddy_profile_id`,
                b.`booking_date`,
                b.`start_time`,
                bs.`status_name` AS `status`,
                cu.`first_name` AS client_first_name,
                cu.`last_name` AS client_last_name,
                cu.`profile_photo` AS client_avatar,
                bp.`user_id` AS buddy_user_id,
                bp.`display_name` AS buddy_name,
                bu.`profile_photo` AS buddy_avatar,
                bp.`professional_title` AS buddy_title,
                bp.`location` AS buddy_location
            FROM `tbl_bookings` b
            INNER JOIN `tbl_users` cu ON cu.`user_id` = b.`client_id`
            INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
            INNER JOIN `tbl_users` bu ON bu.`user_id` = bp.`user_id`
            INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
            WHERE b.`booking_id` = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
            exit;
        }

        // Verify if user is part of the booking
        $isClient = ($userId === (int) $booking['client_id']);
        $isBuddy = ($userId === (int) $booking['buddy_user_id']);

        if (!$isClient && !$isBuddy) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied. You are not a participant in this booking.']);
            exit;
        }

        // Format dates & times
        $dateObj = DateTime::createFromFormat('Y-m-d', $booking['booking_date']);
        $niceDate = $dateObj ? $dateObj->format('M d, Y') : $booking['booking_date'];

        $timeObj = DateTime::createFromFormat('H:i:s', $booking['start_time']);
        if (!$timeObj) {
            $timeObj = DateTime::createFromFormat('H:i', $booking['start_time']);
        }
        $niceTime = $timeObj ? $timeObj->format('g:i A') : $booking['start_time'];

        $formattedBooking = [
            'id' => (int) $booking['booking_id'],
            'client_id' => (int) $booking['client_id'],
            'client_name' => $booking['client_first_name'] . ' ' . $booking['client_last_name'],
            'client_avatar' => $booking['client_avatar'] ?: 'images/user-light.png',
            'buddy_profile_id' => (int) $booking['buddy_profile_id'],
            'buddy_name' => $booking['buddy_name'],
            'buddy_avatar' => $booking['buddy_avatar'] ?: 'images/AnyBuddy LOGO.png',
            'buddy_title' => $booking['buddy_title'] ?: 'Social Companion',
            'buddy_location' => $booking['buddy_location'] ?: 'Manila',
            'booking_date_fmt' => $niceDate,
            'start_time_fmt' => $niceTime,
            'status' => $booking['status']
        ];

        // Fetch messages history
        $msgStmt = $pdo->prepare("
            SELECT 
                m.`id`,
                m.`booking_id`,
                m.`sender_id`,
                m.`message_text`,
                m.`is_read`,
                m.`created_at`,
                u.`first_name`,
                u.`last_name`,
                u.`profile_photo` AS sender_avatar
            FROM `tbl_messages` m
            INNER JOIN `tbl_users` u ON u.`user_id` = m.`sender_id`
            WHERE m.`booking_id` = ?
            ORDER BY m.`created_at` ASC
        ");
        $msgStmt->execute([$bookingId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $formattedMessages = [];
        foreach ($messages as $msg) {
            $msgDateObj = DateTime::createFromFormat('Y-m-d H:i:s', $msg['created_at']);
            $niceMsgTime = $msgDateObj ? $msgDateObj->format('g:i A') : $msg['created_at'];

            $formattedMessages[] = [
                'id' => (int) $msg['id'],
                'booking_id' => (int) $msg['booking_id'],
                'sender_id' => (int) $msg['sender_id'],
                'sender_name' => $msg['first_name'] . ' ' . $msg['last_name'],
                'sender_avatar' => $msg['sender_avatar'] ?: 'images/user-light.png',
                'message_text' => $msg['message_text'],
                'created_at' => $msg['created_at'],
                'created_at_fmt' => $niceMsgTime
            ];
        }

        echo json_encode([
            'status' => 'success',
            'booking' => $formattedBooking,
            'messages' => $formattedMessages
        ]);
        exit;

    } elseif ($method === 'POST') {
        // --- SEND A MESSAGE ---
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $bookingId = (int) ($input['booking_id'] ?? 0);
        $senderId = ab_require_auth();
        $messageText = trim(htmlspecialchars((string)($input['message_text'] ?? ''), ENT_QUOTES, 'UTF-8'));

        if ($bookingId <= 0 || $messageText === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters. Message text cannot be empty.']);
            exit;
        }

        // Verify booking exists
        $stmt = $pdo->prepare("
            SELECT b.`client_id`, bp.`user_id` AS buddy_user_id 
            FROM `tbl_bookings` b
            INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
            WHERE b.`booking_id` = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
            exit;
        }

        // Verify sender is part of the booking
        $isClient = ($senderId === (int) $booking['client_id']);
        $isBuddy = ($senderId === (int) $booking['buddy_user_id']);

        if (!$isClient && !$isBuddy) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied. You cannot send messages in this chat.']);
            exit;
        }

        // Insert message
        $insStmt = $pdo->prepare("
            INSERT INTO `tbl_messages` (`booking_id`, `sender_id`, `message_text`, `is_read`)
            VALUES (?, ?, ?, 0)
        ");
        $insStmt->execute([$bookingId, $senderId, $messageText]);
        $messageId = (int) $pdo->lastInsertId();

        // Get sender details for response
        $uStmt = $pdo->prepare("SELECT `first_name`, `last_name`, `profile_photo` AS `avatar_url` FROM `tbl_users` WHERE `user_id` = ?");
        $uStmt->execute([$senderId]);
        $user = $uStmt->fetch();

        // Notify recipient
        $recipientId = $isClient ? (int)$booking['buddy_user_id'] : (int)$booking['client_id'];
        $senderName = $user['first_name'] . ' ' . $user['last_name'];
        ab_add_notification(
            $pdo, 
            $recipientId, 
            "New Message from {$senderName}", 
            mb_strimwidth($messageText, 0, 80, "..."),
            "chat.html?booking_id=" . $bookingId
        );

        $niceMsgTime = date('g:i A');

        echo json_encode([
            'status' => 'success',
            'message' => [
                'id' => $messageId,
                'booking_id' => $bookingId,
                'sender_id' => $senderId,
                'sender_name' => $senderName,
                'sender_avatar' => $user['avatar_url'] ?: 'images/user-light.png',
                'message_text' => $messageText,
                'created_at' => date('Y-m-d H:i:s'),
                'created_at_fmt' => $niceMsgTime
            ]
        ]);
        exit;

    } else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.', 'detail' => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null]);
    exit;
}
