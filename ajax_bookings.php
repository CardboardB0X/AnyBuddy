<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Bookings Manager
 *  File   : ajax_bookings.php
 *  Method : GET (list) or POST (create / cancel)
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
        // ── LIST USER BOOKINGS ─────────────────────────────────
        $userId = ab_require_auth();

        $role = trim($_GET['role'] ?? 'client');

        if ($role === 'buddy') {
            // Find buddy profile ID first
            $profileStmt = $pdo->prepare("SELECT `profile_id` FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
            $profileStmt->execute([$userId]);
            $buddyProfileId = $profileStmt->fetchColumn();

            if ($buddyProfileId === false) {
                echo json_encode(['status' => 'success', 'bookings' => [], 'buddy_profile_id' => null]);
                exit;
            }

            // Fetch bookings for this buddy, showing client details and reviews status
            $sql = "
                SELECT 
                    b.`booking_id` AS `id`,
                    b.`client_id`,
                    b.`buddy_profile_id`,
                    b.`booking_date`,
                    b.`start_time`,
                    b.`hours_duration`,
                    b.`base_price`,
                    b.`discount_amount`,
                    b.`platform_fee`,
                    b.`total_price`,
                    b.`message`,
                    bs.`status_name` AS `status`,
                    b.`payment_method`,
                    b.`created_at`,
                    u.`first_name` AS client_first_name,
                    u.`last_name` AS client_last_name,
                    u.`profile_photo` AS client_avatar,
                    r.`id` AS review_id
                FROM `tbl_bookings` b
                INNER JOIN `tbl_users` u ON u.`user_id` = b.`client_id`
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                LEFT JOIN `tbl_reviews` r ON r.`booking_id` = b.`booking_id`
                WHERE b.`buddy_profile_id` = ?
                ORDER BY b.`booking_date` DESC, b.`start_time` DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$buddyProfileId]);
            $bookings = $stmt->fetchAll();
        } else {
            // Fetch bookings for this client, showing buddy details and reviews status
            $sql = "
                SELECT 
                    b.`booking_id` AS `id`,
                    b.`client_id`,
                    b.`buddy_profile_id`,
                    b.`booking_date`,
                    b.`start_time`,
                    b.`hours_duration`,
                    b.`base_price`,
                    b.`discount_amount`,
                    b.`platform_fee`,
                    b.`total_price`,
                    b.`message`,
                    bs.`status_name` AS `status`,
                    b.`payment_method`,
                    b.`created_at`,
                    bp.`display_name` AS buddy_name,
                    u.`profile_photo` AS buddy_avatar,
                    r.`id` AS review_id
                FROM `tbl_bookings` b
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                LEFT JOIN `tbl_reviews` r ON r.`booking_id` = b.`booking_id`
                WHERE b.`client_id` = ?
                ORDER BY b.`booking_date` DESC, b.`start_time` DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $bookings = $stmt->fetchAll();
        }

        // Format outputs for UI convenience
        $formatted = [];
        foreach ($bookings as $b) {
            // Format Date nicely
            $dateObj = DateTime::createFromFormat('Y-m-d', $b['booking_date']);
            $niceDate = $dateObj ? $dateObj->format('M d, Y') : $b['booking_date'];

            // Format Time nicely
            $timeObj = DateTime::createFromFormat('H:i:s', $b['start_time']);
            if (!$timeObj) {
                $timeObj = DateTime::createFromFormat('H:i', $b['start_time']);
            }
            $niceTime = $timeObj ? $timeObj->format('g:i A') : $b['start_time'];

            // Display name & Avatar based on role
            if ($role === 'buddy') {
                $displayName = $b['client_first_name'] . ' ' . $b['client_last_name'];
                $avatar = $b['client_avatar'];
                if (!$avatar) {
                    $avatar = 'images/user-light.png';
                }
            } else {
                $displayName = $b['buddy_name'];
                $avatar = $b['buddy_avatar'];
                if (!$avatar) {
                    $avatar = 'images/user-light.png';
                }
            }

            // Calc platform fee and discounts from columns, or fall back to 10% logic for older rows
            $totalPrice = (float) $b['total_price'];
            $basePrice = isset($b['base_price']) && (float)$b['base_price'] > 0 ? (float)$b['base_price'] : ($totalPrice * (10.0 / 11.0));
            $platformFee = isset($b['platform_fee']) && (float)$b['platform_fee'] > 0 ? (float)$b['platform_fee'] : ($totalPrice * (1.0 / 11.0));
            $discountAmt = isset($b['discount_amount']) ? (float)$b['discount_amount'] : 0.00;

            $formatted[] = [
                'id' => (int) $b['id'],
                'client_id' => (int) $b['client_id'],
                'buddy_profile_id' => (int) $b['buddy_profile_id'],
                'displayName' => $displayName,
                'avatar' => $avatar,
                'buddy_name' => $displayName,
                'buddy_avatar' => $avatar,
                'booking_date' => $b['booking_date'],
                'booking_date_fmt' => $niceDate,
                'start_time' => $b['start_time'],
                'start_time_fmt' => $niceTime,
                'hours_duration' => (float) $b['hours_duration'],
                'base_price' => $basePrice,
                'base_price_fmt' => '₱' . number_format($basePrice, 2),
                'discount_amount' => $discountAmt,
                'discount_amount_fmt' => '₱' . number_format($discountAmt, 2),
                'platform_fee' => $platformFee,
                'platform_fee_fmt' => '₱' . number_format($platformFee, 2),
                'total_price' => $totalPrice,
                'total_price_fmt' => '₱' . number_format($totalPrice, 2),
                'message' => $b['message'],
                'status' => $b['status'],
                'payment_method' => $b['payment_method'],
                'review_id' => $b['review_id'] !== null ? (int)$b['review_id'] : null,
                'created_at' => $b['created_at']
            ];
        }

        if ($role === 'buddy') {
            echo json_encode(['status' => 'success', 'bookings' => $formatted, 'buddy_profile_id' => (int) $buddyProfileId]);
        } else {
            echo json_encode(['status' => 'success', 'bookings' => $formatted]);
        }
        exit;

    } elseif ($method === 'POST') {
        // ── CREATE, CANCEL, OR UPDATE BOOKING STATUS ───────────
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?? [];
        } else {
            $input = $_POST;
        }

        $action = trim($input['action'] ?? '');

        if ($action === 'cancel') {
            // Cancel booking
            $bookingId = (int) ($input['booking_id'] ?? 0);
            $userId = ab_require_auth();

            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid parameters for cancellation.']);
                exit;
            }

            // Verify booking exists, belongs to client, and status is Requested (status_id = 1)
            $stmt = $pdo->prepare("
                SELECT b.`status_id`, bs.`status_name` AS `status`, b.`buddy_profile_id`, b.`booking_date`, b.`start_time` 
                FROM `tbl_bookings` b
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                WHERE b.`booking_id` = ? AND b.`client_id` = ?
            ");
            $stmt->execute([$bookingId, $userId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Booking request not found.']);
                exit;
            }

            if ($booking['status'] !== 'Requested') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Only booking requests that are still pending (Requested) can be cancelled.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Delete the booking
                $delStmt = $pdo->prepare("DELETE FROM `tbl_bookings` WHERE `booking_id` = ?");
                $delStmt->execute([$bookingId]);

                // Release availability slot!
                $releaseStmt = $pdo->prepare("
                    UPDATE `tbl_buddy_availability` 
                    SET `is_booked` = 0 
                    WHERE `buddy_profile_id` = ? 
                      AND `available_date` = ? 
                      AND (`start_time` = ? OR TIME_FORMAT(`start_time`, '%H:%i') = TIME_FORMAT(?, '%H:%i'))
                ");
                $releaseStmt->execute([
                    $booking['buddy_profile_id'], 
                    $booking['booking_date'], 
                    $booking['start_time'],
                    $booking['start_time']
                ]);

                ab_add_audit_log($pdo, $userId, 'cancel_booking', 'bookings', $bookingId, "Client cancelled booking ID {$bookingId} (Buddy Profile ID: {$booking['buddy_profile_id']} on {$booking['booking_date']})");

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            echo json_encode(['status' => 'success', 'message' => 'Booking cancelled successfully.']);
            exit;
        } elseif ($action === 'accept' || $action === 'decline' || $action === 'complete' || $action === 'verify') {
            $bookingId = (int) ($input['booking_id'] ?? 0);
            $userId = ab_require_auth();

            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid parameters for status update.']);
                exit;
            }

            // Get booking details with buddy profile details
            $stmt = $pdo->prepare("
                SELECT b.`status_id`, bs.`status_name` AS `status`, b.`client_id`, b.`buddy_profile_id`, b.`booking_date`, b.`start_time`, bp.`user_id` AS buddy_user_id, bp.`display_name` AS buddy_name
                FROM `tbl_bookings` b
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
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

            $isClient = ($userId === (int) $booking['client_id']);
            $isBuddy = ($userId === (int) $booking['buddy_user_id']);

            if (!$isClient && !$isBuddy) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'You are not authorized to perform actions on this booking.']);
                exit;
            }

            // Fetch client details for notifications
            $clientNameStmt = $pdo->prepare("SELECT `first_name`, `last_name` FROM `tbl_users` WHERE `user_id` = ?");
            $clientNameStmt->execute([$booking['client_id']]);
            $clientRow = $clientNameStmt->fetch();
            $clientName = $clientRow ? ($clientRow['first_name'] . ' ' . $clientRow['last_name']) : 'A client';

            if ($action === 'accept') {
                if (!$isBuddy) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Only the buddy can accept booking requests.']);
                    exit;
                }
                if ($booking['status'] !== 'Requested') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Only Requested bookings can be accepted.']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    $updateStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 2 WHERE `booking_id` = ?");
                    $updateStmt->execute([$bookingId]);

                    // Notify Client
                    ab_add_notification(
                        $pdo, 
                        (int) $booking['client_id'], 
                        "Booking Accepted", 
                        "{$booking['buddy_name']} has accepted your booking request for {$booking['booking_date']}.",
                        "bookings.html"
                    );

                    ab_add_audit_log($pdo, $userId, 'accept_booking', 'bookings', $bookingId, "Buddy accepted booking request ID {$bookingId} (Client ID: {$booking['client_id']})");

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode(['status' => 'success', 'message' => 'Booking accepted successfully.']);
                exit;
            } elseif ($action === 'decline') {
                if (!$isBuddy) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Only the buddy can decline booking requests.']);
                    exit;
                }
                if ($booking['status'] !== 'Requested') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Only Requested bookings can be declined.']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    $updateStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 5 WHERE `booking_id` = ?");
                    $updateStmt->execute([$bookingId]);

                    // Release slot!
                    $releaseStmt = $pdo->prepare("
                        UPDATE `tbl_buddy_availability` 
                        SET `is_booked` = 0 
                        WHERE `buddy_profile_id` = ? 
                          AND `available_date` = ? 
                          AND (`start_time` = ? OR TIME_FORMAT(`start_time`, '%H:%i') = TIME_FORMAT(?, '%H:%i'))
                    ");
                    $releaseStmt->execute([
                        $booking['buddy_profile_id'], 
                        $booking['booking_date'], 
                        $booking['start_time'],
                        $booking['start_time']
                    ]);

                    // Notify Client
                    ab_add_notification(
                        $pdo, 
                        (int) $booking['client_id'], 
                        "Booking Declined", 
                        "{$booking['buddy_name']} has declined your booking request for {$booking['booking_date']}.",
                        "bookings.html"
                    );

                    ab_add_audit_log($pdo, $userId, 'decline_booking', 'bookings', $bookingId, "Buddy declined booking request ID {$bookingId} (Client ID: {$booking['client_id']})");

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode(['status' => 'success', 'message' => 'Booking declined successfully.']);
                exit;
            } elseif ($action === 'verify') {
                if ($booking['status'] !== 'Accepted') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Only Accepted bookings can be moved to Verification.']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    $updateStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 3 WHERE `booking_id` = ?");
                    $updateStmt->execute([$bookingId]);

                    // Notify Client
                    ab_add_notification(
                        $pdo, 
                        (int) $booking['client_id'], 
                        "Booking in Verification", 
                        "Your booking with {$booking['buddy_name']} is now in verification stage.",
                        "bookings.html"
                    );

                    ab_add_audit_log($pdo, $userId, 'verify_booking', 'bookings', $bookingId, "Booking ID {$bookingId} moved to Verification stage");

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode(['status' => 'success', 'message' => 'Booking moved to Verification stage.']);
                exit;
            } elseif ($action === 'complete') {
                if ($booking['status'] !== 'Accepted' && $booking['status'] !== 'Verification') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Only Accepted or Verification bookings can be marked as Completed.']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    $updateStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 4 WHERE `booking_id` = ?");
                    $updateStmt->execute([$bookingId]);

                    // Notify Client
                    ab_add_notification(
                        $pdo, 
                        (int) $booking['client_id'], 
                        "Booking Completed", 
                        "Your booking with {$booking['buddy_name']} is now complete! Tap to write a review.",
                        "bookings.html"
                    );

                    // Notify Buddy
                    ab_add_notification(
                        $pdo, 
                        (int) $booking['buddy_user_id'], 
                        "Booking Completed & Earnings Credited", 
                        "Your booking with {$clientName} is marked complete. Your earnings dashboard has been updated.",
                        "bookings.html"
                    );

                    ab_add_audit_log($pdo, $userId, 'complete_booking', 'bookings', $bookingId, "Booking ID {$bookingId} marked as Completed");

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                echo json_encode(['status' => 'success', 'message' => 'Booking marked as Completed successfully.']);
                exit;
            }
        } else {
            // Create booking
            $clientId = ab_require_auth();
            $buddyProfileId = (int) ($input['buddy_profile_id'] ?? 0);
            $bookingDate = trim($input['booking_date'] ?? '');
            $startTime = trim($input['start_time'] ?? '');
            $hoursDuration = (float) ($input['hours_duration'] ?? 1.0);
            $message = trim($input['message'] ?? '');
            $paymentMethod = trim($input['payment_method'] ?? 'Card');
            if (!in_array($paymentMethod, ['Card', 'Cash On Hand'])) {
                $paymentMethod = 'Card';
            }

            $errors = [];

            if ($clientId <= 0) {
                $errors['user_id'] = 'You must be logged in to create a booking.';
            } else {
                // Verify user exists in the database (handles session out-of-sync)
                $userCheck = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `user_id` = ?");
                $userCheck->execute([$clientId]);
                if ((int)$userCheck->fetchColumn() === 0) {
                    $errors['user_id'] = 'Your session is invalid or has expired. Please log out and log in again.';
                }
            }

            if ($buddyProfileId <= 0) {
                $errors['buddy_profile_id'] = 'Invalid buddy selection.';
            } else {
                // Check if buddy profile exists
                $buddyStmt = $pdo->prepare("SELECT `user_id`, `hourly_rate`, `display_name` FROM `tbl_buddy_profiles` WHERE `profile_id` = ?");
                $buddyStmt->execute([$buddyProfileId]);
                $buddyRow = $buddyStmt->fetch();

                if ($buddyRow === false) {
                    $errors['buddy_profile_id'] = 'Selected buddy profile does not exist.';
                } else {
                    $buddyRate = (float) $buddyRow['hourly_rate'];
                    $buddyUserId = (int) $buddyRow['user_id'];
                    $buddyName = $buddyRow['display_name'];
                    
                    if ($clientId === $buddyUserId) {
                        $errors['buddy_profile_id'] = 'You cannot book your own buddy profile.';
                    }
                }
            }

            if ($bookingDate === '') {
                $errors['booking_date'] = 'Booking date is required.';
            } else {
                $dateObj = DateTime::createFromFormat('Y-m-d', $bookingDate);
                $today = new DateTime('today');
                if (!$dateObj) {
                    $errors['booking_date'] = 'Invalid date format.';
                } elseif ($dateObj < $today) {
                    $errors['booking_date'] = 'Booking date must be today or in the future.';
                } else {
                    // Check duplicate booking on booking_date (Day-based booking)
                    if (isset($buddyProfileId) && $buddyProfileId > 0) {
                        // Only run day-based check if there are no custom slots defined for this day
                        $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM `tbl_buddy_availability` WHERE `buddy_profile_id` = ? AND `available_date` = ?");
                        $slotCheck->execute([$buddyProfileId, $bookingDate]);
                        $hasCustomSlots = ((int)$slotCheck->fetchColumn() > 0);

                        if (!$hasCustomSlots) {
                            $dupStmt = $pdo->prepare("
                                SELECT COUNT(*) FROM `tbl_bookings` 
                                WHERE `buddy_profile_id` = ? 
                                  AND `booking_date` = ? 
                                  AND `status_id` IN (1, 2, 3, 4)
                            ");
                            $dupStmt->execute([$buddyProfileId, $bookingDate]);
                            if ((int)$dupStmt->fetchColumn() > 0) {
                                $errors['booking_date'] = 'This buddy is already booked or requested on this date.';
                            }
                        }
                    }
                }
            }

            $slotIdToBook = null;
            if ($startTime === '') {
                $errors['start_time'] = 'Start time is required.';
            } elseif (isset($buddyProfileId) && $buddyProfileId > 0 && $bookingDate !== '' && !isset($errors['booking_date'])) {
                // Check if buddy has custom slots for this date
                $availCheck = $pdo->prepare("
                    SELECT `id`, `start_time`, `is_booked` FROM `tbl_buddy_availability`
                    WHERE `buddy_profile_id` = ? AND `available_date` = ?
                ");
                $availCheck->execute([$buddyProfileId, $bookingDate]);
                $slots = $availCheck->fetchAll();

                if (count($slots) > 0) {
                    $matchFound = false;
                    $alreadyBooked = false;
                    
                    foreach ($slots as $slot) {
                        $slotStartFormatted = date('H:i', strtotime($slot['start_time']));
                        $clientStartFormatted = date('H:i', strtotime($startTime));
                        if ($slotStartFormatted === $clientStartFormatted) {
                            $matchFound = true;
                            $slotIdToBook = (int) $slot['id'];
                            if ((int) $slot['is_booked'] === 1) {
                                $alreadyBooked = true;
                            }
                            break;
                        }
                    }
                    
                    if (!$matchFound) {
                        $errors['start_time'] = 'The selected start time does not match any of the buddy\'s available slots on this date.';
                    } elseif ($alreadyBooked) {
                        $errors['start_time'] = 'This availability slot has already been booked.';
                    }
                }
            }

            if ($hoursDuration <= 0 || $hoursDuration > 24) {
                $errors['hours_duration'] = 'Duration must be between 1 and 24 hours.';
            }

            if ($paymentMethod === 'Card') {
                $cardNumber = trim($input['card_number'] ?? '');
                $expiration = trim($input['expiration'] ?? '');
                $cvv = trim($input['cvv'] ?? '');

                if ($cardNumber === '') {
                    $errors['card_number'] = 'Card number is required.';
                }
                if ($expiration === '') {
                    $errors['expiration'] = 'Expiration date MM/YY is required.';
                }
                if ($cvv === '') {
                    $errors['cvv'] = 'CVV is required.';
                }
            }

            if (!empty($errors)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Validation failed.',
                    'errors' => $errors
                ]);
                exit;
            }

            // Calculate User Tier benefits
            $tierCompletedStmt = $pdo->prepare("
                SELECT COUNT(*) FROM `tbl_bookings` 
                WHERE `client_id` = ? AND `status_id` = 4
            ");
            $tierCompletedStmt->execute([$clientId]);
            $completedBookingsCount = (int)$tierCompletedStmt->fetchColumn();

            $tierStmt = $pdo->prepare("
                SELECT * FROM `tbl_user_tiers` 
                WHERE `min_bookings` <= ? 
                ORDER BY `min_bookings` DESC 
                LIMIT 1
            ");
            $tierStmt->execute([$completedBookingsCount]);
            $tier = $tierStmt->fetch() ?: [
                'platform_fee_percent' => 5.00,
                'discount_percent' => 0.00
            ];

            $rate = (float) $buddyRate;
            $basePrice = $rate * $hoursDuration;

            // Calculate Tier Discount
            $tierDiscountPercent = (float)$tier['discount_percent'];
            $tierDiscount = $basePrice * ($tierDiscountPercent / 100.0);

            // Calculate Voucher Discount
            $voucherDiscount = 0.00;
            $voucherId = null;
            $voucherCode = trim($input['voucher_code'] ?? '');

            if ($voucherCode !== '') {
                $vStmt = $pdo->prepare("
                    SELECT * FROM `tbl_vouchers` 
                    WHERE `code` = ? AND `is_active` = 1
                ");
                $vStmt->execute([$voucherCode]);
                $voucher = $vStmt->fetch();

                if ($voucher) {
                    $meetsExpiry = true;
                    if ($voucher['expiration_date'] !== null) {
                        $expiry = new DateTime($voucher['expiration_date']);
                        $today = new DateTime('today');
                        if ($expiry < $today) {
                            $meetsExpiry = false;
                        }
                    }

                    $meetsSpend = ($basePrice >= (float)$voucher['min_spend']);

                    if ($meetsExpiry && $meetsSpend) {
                        $voucherId = (int)$voucher['voucher_id'];
                        $discountVal = (float)$voucher['discount_value'];
                        if ($voucher['discount_type'] === 'fixed') {
                            $voucherDiscount = $discountVal;
                        } elseif ($voucher['discount_type'] === 'percentage') {
                            $voucherDiscount = $basePrice * ($discountVal / 100.0);
                        }
                    }
                }
            }

            $totalDiscount = $tierDiscount + $voucherDiscount;
            if ($totalDiscount > $basePrice) {
                $totalDiscount = $basePrice;
            }

            $discountedBase = max(0.00, $basePrice - $totalDiscount);
            $feePercent = (float)$tier['platform_fee_percent'];
            $platformFee = $discountedBase * ($feePercent / 100.0);
            $totalPrice = $discountedBase + $platformFee;

            // Fetch client name for buddy notification
            $clientNameStmt = $pdo->prepare("SELECT `first_name`, `last_name` FROM `tbl_users` WHERE `user_id` = ?");
            $clientNameStmt->execute([$clientId]);
            $clientUser = $clientNameStmt->fetch();
            $clientName = $clientUser ? ($clientUser['first_name'] . ' ' . $clientUser['last_name']) : 'A client';

            $pdo->beginTransaction();
            try {
                // Insert booking
                $insStmt = $pdo->prepare("
                    INSERT INTO `tbl_bookings` 
                        (`client_id`, `buddy_profile_id`, `booking_date`, `start_time`, `hours_duration`, `base_price`, `discount_amount`, `platform_fee`, `total_price`, `voucher_id`, `message`, `status_id`, `payment_method`)
                    VALUES 
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ");

                $insStmt->execute([
                    $clientId,
                    $buddyProfileId,
                    $bookingDate,
                    $startTime,
                    $hoursDuration,
                    $basePrice,
                    $totalDiscount,
                    $platformFee,
                    $totalPrice,
                    $voucherId,
                    $message !== '' ? $message : null,
                    $paymentMethod
                ]);

                $newBookingId = (int) $pdo->lastInsertId();

                // Mark availability slot as booked if slot ID is found
                if (isset($slotIdToBook) && $slotIdToBook > 0) {
                    $markSlot = $pdo->prepare("UPDATE `tbl_buddy_availability` SET `is_booked` = 1 WHERE `id` = ?");
                    $markSlot->execute([$slotIdToBook]);
                }

                // Notify Buddy
                ab_add_notification(
                    $pdo, 
                    $buddyUserId, 
                    "New Booking Request", 
                    "You have a new booking request from {$clientName} on {$bookingDate}.",
                    "bookings.html"
                );

                ab_add_audit_log($pdo, $clientId, 'create_booking', 'bookings', $newBookingId, "Client requested booking ID {$newBookingId} with Buddy Profile ID {$buddyProfileId} on {$bookingDate}");

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => 'Booking requested successfully!',
                'booking_id' => $newBookingId
            ]);
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
