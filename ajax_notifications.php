<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Notifications Center
 *  File   : ajax_notifications.php
 *  Method : GET (list notifications) or POST (mark as read / clear)
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
        // --- FETCH NOTIFICATIONS ---
        $userId = ab_require_auth();

        $limit = (int) ($_GET['limit'] ?? 20);
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        $stmt = $pdo->prepare("
            SELECT `id`, `user_id`, `title`, `message`, `link`, `is_read`, `created_at`
            FROM `tbl_notifications`
            WHERE `user_id` = ?
            ORDER BY `created_at` DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Format time nicely
        $formatted = [];
        foreach ($notifications as $n) {
            $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $n['created_at']);
            $niceTime = $dateObj ? $dateObj->format('M d, g:i A') : $n['created_at'];
            
            $formatted[] = [
                'id' => (int) $n['id'],
                'user_id' => (int) $n['user_id'],
                'title' => $n['title'],
                'message' => $n['message'],
                'link' => $n['link'],
                'is_read' => (int) $n['is_read'],
                'created_at' => $n['created_at'],
                'created_at_fmt' => $niceTime
            ];
        }

        // Also fetch total unread count for navbar badge
        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM `tbl_notifications` WHERE `user_id` = ? AND `is_read` = 0");
        $unreadStmt->execute([$userId]);
        $unreadCount = (int) $unreadStmt->fetchColumn();

        echo json_encode([
            'status' => 'success',
            'notifications' => $formatted,
            'unread_count' => $unreadCount
        ]);
        exit;

    } elseif ($method === 'POST') {
        // --- MARK NOTIFICATIONS AS READ ---
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $input = $_POST;
        }

        $userId = ab_require_auth();
        $action = trim($input['action'] ?? '');

        if ($action === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing action.']);
            exit;
        }

        if ($action === 'read') {
            $notificationId = (int) ($input['notification_id'] ?? 0);
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing notification ID.']);
                exit;
            }

            // Verify notification belongs to user
            $checkStmt = $pdo->prepare("SELECT `user_id` FROM `tbl_notifications` WHERE `id` = ?");
            $checkStmt->execute([$notificationId]);
            $ownerId = $checkStmt->fetchColumn();

            if ($ownerId === false) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Notification not found.']);
                exit;
            }

            if ((int) $ownerId !== $userId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized action.']);
                exit;
            }

            $updateStmt = $pdo->prepare("UPDATE `tbl_notifications` SET `is_read` = 1 WHERE `id` = ?");
            $updateStmt->execute([$notificationId]);

            echo json_encode(['status' => 'success', 'message' => 'Notification marked as read.']);
            exit;

        } elseif ($action === 'read_all') {
            $updateStmt = $pdo->prepare("UPDATE `tbl_notifications` SET `is_read` = 1 WHERE `user_id` = ?");
            $updateStmt->execute([$userId]);

            echo json_encode(['status' => 'success', 'message' => 'All notifications marked as read.']);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
            exit;
        }
    } else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred on the server.',
        'detail' => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null
    ]);
    exit;
}
