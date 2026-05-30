<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Availability Scheduler Manager
 *  File   : ajax_availability.php
 *  Method : GET (fetch slots) or POST (add/delete slot)
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
        // --- FETCH SLOTS / BOOKED DATES ---
        $buddyProfileId = (int) ($_GET['buddy_profile_id'] ?? 0);
        if ($buddyProfileId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing buddy profile ID.']);
            exit;
        }

        $month = (int) ($_GET['month'] ?? 0);
        $year = (int) ($_GET['year'] ?? 0);

        if ($month > 0 && $year > 0) {
            // Fetch all booked dates for this buddy in this month/year where status is Requested or Accepted or active
            $bookingStmt = $pdo->prepare("
                SELECT DISTINCT b.`booking_date`
                FROM `tbl_bookings` b
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                WHERE b.`buddy_profile_id` = ? 
                  AND YEAR(b.`booking_date`) = ? 
                  AND MONTH(b.`booking_date`) = ? 
                  AND bs.`status_name` IN ('Accepted', 'Requested', 'Verification', 'Completed')
            ");
            $bookingStmt->execute([$buddyProfileId, $year, $month]);
            $bookedDates = $bookingStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            echo json_encode(['status' => 'success', 'booked_dates' => $bookedDates]);
            exit;
        }

        $date = trim($_GET['date'] ?? '');
        if ($date === '') {
            $date = date('Y-m-d', strtotime('+1 day'));
        }

        // Generate standard daily slots: 8 AM to 10 PM (2-hour increments)
        $standardSlots = [
            ['start' => '08:00:00', 'end' => '10:00:00'],
            ['start' => '10:00:00', 'end' => '12:00:00'],
            ['start' => '12:00:00', 'end' => '14:00:00'],
            ['start' => '14:00:00', 'end' => '16:00:00'],
            ['start' => '16:00:00', 'end' => '18:00:00'],
            ['start' => '18:00:00', 'end' => '20:00:00'],
            ['start' => '20:00:00', 'end' => '22:00:00'],
        ];

        // Fetch accepted bookings for this buddy on this date
        $bookingStmt = $pdo->prepare("
            SELECT b.`start_time`, b.`hours_duration`
            FROM `tbl_bookings` b
            INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
            WHERE b.`buddy_profile_id` = ? AND b.`booking_date` = ? AND bs.`status_name` IN ('Accepted', 'Requested')
        ");
        $bookingStmt->execute([$buddyProfileId, $date]);
        $acceptedBookings = $bookingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Helper functions
        if (!function_exists('time_to_mins')) {
            function time_to_mins(string $timeStr): int {
                $parts = explode(':', $timeStr);
                return ((int)($parts[0] ?? 0)) * 60 + ((int)($parts[1] ?? 0));
            }
        }

        if (!function_exists('is_slot_overlapping')) {
            function is_slot_overlapping(string $slotStart, string $slotEnd, array $bookings): bool {
                $sStart = time_to_mins($slotStart);
                $sEnd = time_to_mins($slotEnd);

                foreach ($bookings as $b) {
                    $bStart = time_to_mins($b['start_time']);
                    $bEnd = $bStart + (int)(floatval($b['hours_duration']) * 60);
                    if ($sStart < $bEnd && $sEnd > $bStart) {
                        return true;
                    }
                }
                return false;
            }
        }

        // Build response slots
        $formatted = [];
        $dummyId = 1;
        foreach ($standardSlots as $slot) {
            $isBooked = is_slot_overlapping($slot['start'], $slot['end'], $acceptedBookings);
            
            $startTimeObj = DateTime::createFromFormat('H:i:s', $slot['start']);
            $endTimeObj = DateTime::createFromFormat('H:i:s', $slot['end']);
            
            $formatted[] = [
                'id' => $dummyId++,
                'buddy_profile_id' => $buddyProfileId,
                'available_date' => $date,
                'start_time' => $slot['start'],
                'start_time_fmt' => $startTimeObj ? $startTimeObj->format('H:i') : $slot['start'],
                'nice_start_time' => $startTimeObj ? $startTimeObj->format('g:i A') : $slot['start'],
                'end_time' => $slot['end'],
                'end_time_fmt' => $endTimeObj ? $endTimeObj->format('H:i') : $slot['end'],
                'nice_end_time' => $endTimeObj ? $endTimeObj->format('g:i A') : $slot['end'],
                'is_booked' => $isBooked ? 1 : 0
            ];
        }

        echo json_encode(['status' => 'success', 'slots' => $formatted]);
        exit;

    } elseif ($method === 'POST') {
        // --- ADD OR DELETE SLOT ---
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

        // Verify user is a buddy and get their profile ID
        $profileStmt = $pdo->prepare("SELECT `profile_id` FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
        $profileStmt->execute([$userId]);
        $buddyProfileId = $profileStmt->fetchColumn();

        if ($buddyProfileId === false) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Only registered buddies can manage availability slots.']);
            exit;
        }
        $buddyProfileId = (int) $buddyProfileId;

        if ($action === 'add') {
            $availableDate = trim($input['available_date'] ?? '');
            $startTime = trim($input['start_time'] ?? '');
            $endTime = trim($input['end_time'] ?? '');

            if ($availableDate === '' || $startTime === '' || $endTime === '') {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Date, start time, and end time are required.']);
                exit;
            }

            // Simple date validation
            $dateObj = DateTime::createFromFormat('Y-m-d', $availableDate);
            if (!$dateObj) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
                exit;
            }

            // Simple time validation and ordering check
            $timeStart = DateTime::createFromFormat('H:i', $startTime);
            if (!$timeStart) {
                $timeStart = DateTime::createFromFormat('H:i:s', $startTime);
            }
            $timeEnd = DateTime::createFromFormat('H:i', $endTime);
            if (!$timeEnd) {
                $timeEnd = DateTime::createFromFormat('H:i:s', $endTime);
            }

            if (!$timeStart || !$timeEnd) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Invalid time format. Use HH:MM.']);
                exit;
            }

            if ($timeStart >= $timeEnd) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Start time must be before end time.']);
                exit;
            }

            // Check for duplicate or overlapping slot
            $overlapStmt = $pdo->prepare("
                SELECT COUNT(*) FROM `tbl_buddy_availability`
                WHERE `buddy_profile_id` = ? 
                  AND `available_date` = ?
                  AND (
                    (`start_time` <= ? AND `end_time` > ?) OR
                    (`start_time` < ? AND `end_time` >= ?) OR
                    (? <= `start_time` AND ? > `start_time`)
                  )
            ");
            $startStr = $timeStart->format('H:i:s');
            $endStr = $timeEnd->format('H:i:s');
            $overlapStmt->execute([
                $buddyProfileId, $availableDate, 
                $startStr, $startStr,
                $endStr, $endStr,
                $startStr, $endStr
            ]);
            
            if ((int) $overlapStmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => 'This slot overlaps with an existing slot.']);
                exit;
            }

            // Insert new slot
            $insertStmt = $pdo->prepare("
                INSERT INTO `tbl_buddy_availability` (`buddy_profile_id`, `available_date`, `start_time`, `end_time`, `is_booked`)
                VALUES (?, ?, ?, ?, 0)
            ");
            $insertStmt->execute([$buddyProfileId, $availableDate, $startStr, $endStr]);
            $newId = (int) $pdo->lastInsertId();

            echo json_encode([
                'status' => 'success',
                'message' => 'Availability slot added successfully.',
                'slot' => [
                    'id' => $newId,
                    'buddy_profile_id' => $buddyProfileId,
                    'available_date' => $availableDate,
                    'start_time' => $startStr,
                    'end_time' => $endStr,
                    'is_booked' => 0
                ]
            ]);
            exit;

        } elseif ($action === 'delete') {
            $slotId = (int) ($input['slot_id'] ?? 0);
            if ($slotId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing slot ID.']);
                exit;
            }

            // Check if slot exists and belongs to buddy
            $slotStmt = $pdo->prepare("
                SELECT `buddy_profile_id`, `is_booked` 
                FROM `tbl_buddy_availability` 
                WHERE `id` = ?
            ");
            $slotStmt->execute([$slotId]);
            $slot = $slotStmt->fetch();

            if (!$slot) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Slot not found.']);
                exit;
            }

            if ((int)$slot['buddy_profile_id'] !== $buddyProfileId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'You do not own this slot.']);
                exit;
            }

            if ((int)$slot['is_booked'] === 1) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete a slot that is already booked.']);
                exit;
            }

            // Delete slot
            $deleteStmt = $pdo->prepare("DELETE FROM `tbl_buddy_availability` WHERE `id` = ?");
            $deleteStmt->execute([$slotId]);

            echo json_encode(['status' => 'success', 'message' => 'Availability slot deleted successfully.']);
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
        'detail' => $e->getMessage()
    ]);
    exit;
}
