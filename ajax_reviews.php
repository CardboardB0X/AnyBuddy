<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Reviews Manager
 *  File   : ajax_reviews.php
 *  Method : GET (fetch reviews) or POST (submit a review)
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
        // --- FETCH REVIEWS FOR A BUDDY PROFILE ---
        $buddyProfileId = (int) ($_GET['buddy_profile_id'] ?? 0);
        if ($buddyProfileId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing buddy profile ID.']);
            exit;
        }

        // Fetch reviews with client details
        $sql = "
            SELECT 
                r.`id`,
                r.`booking_id`,
                r.`client_id`,
                r.`buddy_profile_id`,
                r.`rating`,
                r.`comment`,
                r.`created_at`,
                u.`first_name` AS client_first_name,
                u.`last_name` AS client_last_name,
                u.`profile_photo` AS client_avatar
            FROM `tbl_reviews` r
            INNER JOIN `tbl_users` u ON u.`user_id` = r.`client_id`
            WHERE r.`buddy_profile_id` = ?
            ORDER BY r.`created_at` DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$buddyProfileId]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Format dates and avatars nicely
        $formatted = [];
        foreach ($reviews as $rev) {
            $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $rev['created_at']);
            $niceDate = $dateObj ? $dateObj->format('M d, Y') : $rev['created_at'];

            $avatar = $rev['client_avatar'];
            if (!$avatar) {
                $avatar = 'images/user-light.png';
            }

            $formatted[] = [
                'id' => (int) $rev['id'],
                'booking_id' => (int) $rev['booking_id'],
                'client_id' => (int) $rev['client_id'],
                'buddy_profile_id' => (int) $rev['buddy_profile_id'],
                'rating' => (int) $rev['rating'],
                'comment' => $rev['comment'],
                'created_at' => $rev['created_at'],
                'created_at_fmt' => $niceDate,
                'client_name' => $rev['client_first_name'] . ' ' . $rev['client_last_name'],
                'client_avatar' => $avatar
            ];
        }

        echo json_encode(['status' => 'success', 'reviews' => $formatted]);
        exit;

    } elseif ($method === 'POST') {
        // --- SUBMIT A REVIEW ---
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $bookingId = (int) ($input['booking_id'] ?? 0);
        $userId = ab_require_auth();
        $rating = (int) ($input['rating'] ?? 0);
        $comment = trim(htmlspecialchars((string)($input['comment'] ?? ''), ENT_QUOTES, 'UTF-8'));

        if ($bookingId <= 0 || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters. Rating must be between 1 and 5.']);
            exit;
        }

        // Verify booking exists, status is Completed, and user is the client of the booking
        $stmt = $pdo->prepare("
            SELECT bs.`status_name` AS `status`, b.`client_id`, b.`buddy_profile_id` 
            FROM `tbl_bookings` b
            INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
            WHERE b.`booking_id` = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
            exit;
        }

        if ((int) $booking['client_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only the client who booked this service can leave a review.']);
            exit;
        }

        if ($booking['status'] !== 'Completed') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'You can only review bookings that are marked as Completed.']);
            exit;
        }

        // Check if a review already exists for this booking
        $chkStmt = $pdo->prepare("SELECT `id` FROM `tbl_reviews` WHERE `booking_id` = ?");
        $chkStmt->execute([$bookingId]);
        if ($chkStmt->fetchColumn() !== false) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'You have already submitted a review for this booking.']);
            exit;
        }

        // Insert review
        $insStmt = $pdo->prepare("
            INSERT INTO `tbl_reviews` (`booking_id`, `client_id`, `buddy_profile_id`, `rating`, `comment`)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insStmt->execute([$bookingId, $userId, (int) $booking['buddy_profile_id'], $rating, $comment]);

        echo json_encode(['status' => 'success', 'message' => 'Review submitted successfully!']);
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
