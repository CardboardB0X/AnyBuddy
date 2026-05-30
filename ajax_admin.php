<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Admin Dashboard Portal Manager
 *  File   : ajax_admin.php
 *  Method : GET (fetch stats/lists/CRUD lists) or POST (CRUD actions)
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

    // Require admin privileges via PHP session
    $userId = ab_require_admin();

    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');

        // --- CRUD READ ACTIONS ---

        if ($action === 'list_users') {
            $stmt = $pdo->query("SELECT `user_id`, `first_name`, `last_name`, `email`, `role`, `pronouns`, `profile_photo`, `status`, `is_active`, `status_reason` FROM `tbl_users` ORDER BY `user_id` DESC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'users' => $users]);
            exit;
        }

        if ($action === 'list_buddies') {
            $stmt = $pdo->query("
                SELECT bp.`profile_id`, bp.`user_id`, bp.`display_name`, bp.`professional_title`, bp.`category`, bp.`bio`, bp.`hourly_rate`, bp.`location`, bp.`availability`, bp.`total_gigs`, bp.`is_available`, u.`email`
                FROM `tbl_buddy_profiles` bp
                INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
                ORDER BY bp.`profile_id` DESC
            ");
            $buddies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'buddies' => $buddies]);
            exit;
        }

        if ($action === 'list_bookings') {
            $stmt = $pdo->query("
                SELECT b.`booking_id`, b.`client_id`, b.`buddy_profile_id`, b.`status_id`, b.`booking_date`, b.`start_time`, b.`hours_duration`, b.`base_price`, b.`discount_amount`, b.`platform_fee`, b.`total_price`, b.`payment_method`, b.`message`,
                       cu.`first_name` AS client_first_name, cu.`last_name` AS client_last_name,
                       bp.`display_name` AS buddy_name, bs.`status_name` AS status
                FROM `tbl_bookings` b
                INNER JOIN `tbl_users` cu ON cu.`user_id` = b.`client_id`
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
                ORDER BY b.`booking_id` DESC
            ");
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'bookings' => $bookings]);
            exit;
        }

        if ($action === 'list_vouchers') {
            $stmt = $pdo->query("SELECT `voucher_id`, `code`, `discount_type`, `discount_value`, `min_spend`, `is_active`, `expiration_date` FROM `tbl_vouchers` ORDER BY `voucher_id` DESC");
            $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'vouchers' => $vouchers]);
            exit;
        }

        if ($action === 'get_form_lookups') {
            $clients = $pdo->query("SELECT `user_id`, `first_name`, `last_name`, `email` FROM `tbl_users` ORDER BY `first_name` ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $buddies = $pdo->query("SELECT `profile_id`, `display_name` FROM `tbl_buddy_profiles` ORDER BY `display_name` ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $statuses = $pdo->query("SELECT `status_id`, `status_name` FROM `tbl_booking_statuses` ORDER BY `status_order` ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'status' => 'success',
                'clients' => $clients,
                'buddies' => $buddies,
                'statuses' => $statuses
            ]);
            exit;
        }

        if ($action === 'list_audit_logs') {
            $stmt = $pdo->query("
                SELECT al.`log_id`, al.`performed_by`, al.`action`, al.`entity_type`, al.`entity_id`, al.`details`, al.`created_at`,
                       u.`first_name`, u.`last_name`, u.`email`
                FROM `tbl_audit_logs` al
                LEFT JOIN `tbl_users` u ON u.`user_id` = al.`performed_by`
                ORDER BY al.`log_id` DESC
            ");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'logs' => $logs]);
            exit;
        }

        if ($action === 'list_chats') {
            $stmt = $pdo->query("
                SELECT DISTINCT b.`booking_id`, b.`booking_date`, b.`start_time`,
                       cu.`first_name` AS client_first_name, cu.`last_name` AS client_last_name, cu.`email` AS client_email,
                       bp.`display_name` AS buddy_name,
                       (SELECT COUNT(*) FROM `tbl_messages` WHERE `booking_id` = b.`booking_id`) AS message_count
                FROM `tbl_bookings` b
                INNER JOIN `tbl_users` cu ON cu.`user_id` = b.`client_id`
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                WHERE (SELECT COUNT(*) FROM `tbl_messages` WHERE `booking_id` = b.`booking_id`) > 0
                ORDER BY b.`booking_id` DESC
            ");
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'chats' => $chats]);
            exit;
        }

        if ($action === 'get_chat_messages') {
            $bookingId = (int) ($_GET['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing booking ID.']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT m.`id`, m.`sender_id`, m.`message_text`, m.`created_at`,
                       u.`first_name`, u.`last_name`, u.`role`
                FROM `tbl_messages` m
                INNER JOIN `tbl_users` u ON u.`user_id` = m.`sender_id`
                WHERE m.`booking_id` = ?
                ORDER BY m.`created_at` ASC
            ");
            $stmt->execute([$bookingId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['status' => 'success', 'messages' => $messages]);
            exit;
        }

        if ($action === 'list_system_settings') {
            $stmt = $pdo->query("SELECT `key_name`, `key_value` FROM `tbl_system_settings`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['key_name']] = $row['key_value'];
            }
            echo json_encode(['status' => 'success', 'settings' => $settings]);
            exit;
        }


        // --- DEFAULT: FETCH ADMIN DASHBOARD OVERVIEW DATA ---
        
        $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM `tbl_users`")->fetchColumn();
        $totalBookings = (int) $pdo->query("SELECT COUNT(*) FROM `tbl_bookings`")->fetchColumn();
        $totalRevenue = (float) $pdo->query("SELECT SUM(`total_price`) FROM `tbl_bookings` WHERE `status_id` = 4")->fetchColumn();
        $pendingVerificationsCount = (int) $pdo->query("SELECT COUNT(*) FROM `tbl_buddy_verifications` WHERE `verification_status` = 'pending'")->fetchColumn();
        $activeReportsCount = (int) $pdo->query("SELECT COUNT(*) FROM `tbl_reports` WHERE `status` = 'pending'")->fetchColumn();

        // Fetch pending verifications list
        $verifStmt = $pdo->query("
            SELECT 
                bp.`profile_id` AS buddy_profile_id,
                bp.`display_name`,
                bv.`verification_type`,
                bv.`id_photo_url`,
                bp.`created_at`,
                u.`email` AS buddy_email
            FROM `tbl_buddy_profiles` bp
            INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
            INNER JOIN `tbl_buddy_verifications` bv ON bv.`profile_id` = bp.`profile_id`
            WHERE bv.`verification_status` = 'pending'
            ORDER BY bp.`created_at` ASC
        ");
        $pendingVerifications = $verifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch reports list
        $reportsStmt = $pdo->query("
            SELECT 
                r.`report_id`,
                r.`reporter_id`,
                r.`reported_id`,
                r.`reason`,
                r.`description`,
                r.`status`,
                r.`created_at`,
                u1.`first_name` AS reporter_first,
                u1.`last_name` AS reporter_last,
                u1.`email` AS reporter_email,
                u2.`first_name` AS reported_first,
                u2.`last_name` AS reported_last,
                u2.`email` AS reported_email
            FROM `tbl_reports` r
            INNER JOIN `tbl_users` u1 ON u1.`user_id` = r.`reporter_id`
            INNER JOIN `tbl_users` u2 ON u2.`user_id` = r.`reported_id`
            ORDER BY r.`created_at` DESC
        ");
        $reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'status' => 'success',
            'stats' => [
                'total_users' => $totalUsers,
                'total_bookings' => $totalBookings,
                'total_revenue' => $totalRevenue,
                'total_revenue_fmt' => '₱' . number_format($totalRevenue, 2),
                'pending_verifications' => $pendingVerificationsCount,
                'active_reports' => $activeReportsCount
            ],
            'pending_verifications' => $pendingVerifications,
            'reports' => $reports
        ]);
        exit;

    } elseif ($method === 'POST') {
        $action = trim($input['action'] ?? '');

        if ($action === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Action parameter is required.']);
            exit;
        }

        // --- MODERATION ACTIONS ---

        if ($action === 'approve_verification' || $action === 'reject_verification') {
            $buddyProfileId = (int) ($input['buddy_profile_id'] ?? 0);
            if ($buddyProfileId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing buddy profile ID.']);
                exit;
            }

            $buddyStmt = $pdo->prepare("SELECT `user_id`, `display_name` FROM `tbl_buddy_profiles` WHERE `profile_id` = ?");
            $buddyStmt->execute([$buddyProfileId]);
            $buddy = $buddyStmt->fetch();

            if (!$buddy) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Buddy profile not found.']);
                exit;
            }

            $buddyUserId = (int)$buddy['user_id'];
            $buddyName = $buddy['display_name'];

            if ($action === 'approve_verification') {
                $upStmt = $pdo->prepare("UPDATE `tbl_buddy_verifications` SET `verification_status` = 'verified' WHERE `profile_id` = ?");
                $upStmt->execute([$buddyProfileId]);

                ab_add_notification($pdo, $buddyUserId, "Account Verified", "Congratulations! Your ID verification documents have been approved. You now have a verified badge.", "profile.html");

                ab_add_audit_log($pdo, $userId, 'approve_verification', 'buddies', $buddyProfileId, "Approved ID verification for {$buddyName} (User ID: {$buddyUserId})");

                echo json_encode(['status' => 'success', 'message' => "Successfully approved verification for {$buddyName}."]);
                exit;
            } else {
                $upStmt = $pdo->prepare("UPDATE `tbl_buddy_verifications` SET `verification_status` = 'none', `id_photo_url` = NULL WHERE `profile_id` = ?");
                $upStmt->execute([$buddyProfileId]);

                ab_add_notification($pdo, $buddyUserId, "Verification Declined", "Your ID verification request has been declined. Please submit a valid document under Edit Profile.", "profile.html");

                ab_add_audit_log($pdo, $userId, 'reject_verification', 'buddies', $buddyProfileId, "Declined ID verification for {$buddyName} (User ID: {$buddyUserId})");

                echo json_encode(['status' => 'success', 'message' => "Successfully declined verification for {$buddyName}."]);
                exit;
            }

        } elseif ($action === 'resolve_report' || $action === 'dismiss_report' || $action === 'suspend_reported_user' || $action === 'ban_reported_user') {
            $reportId = (int) ($input['report_id'] ?? 0);
            if ($reportId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing report ID.']);
                exit;
            }

            $repStmt = $pdo->prepare("SELECT `reporter_id`, `reported_id`, `reason` FROM `tbl_reports` WHERE `report_id` = ?");
            $repStmt->execute([$reportId]);
            $reportRow = $repStmt->fetch();

            if (!$reportRow) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Report ticket not found.']);
                exit;
            }

            $reporterId = (int)$reportRow['reporter_id'];
            $reportedId = (int)$reportRow['reported_id'];
            $reason = $reportRow['reason'];

            $pdo->beginTransaction();
            try {
                if ($action === 'suspend_reported_user') {
                    // Update user to suspended and inactive
                    $userUp = $pdo->prepare("UPDATE `tbl_users` SET `status` = 'suspended', `is_active` = 0, `status_reason` = ? WHERE `user_id` = ?");
                    $userUp->execute(["Suspended from Safety Report Ticket #{$reportId}: {$reason}", $reportedId]);

                    // Resolve the report
                    $upStmt = $pdo->prepare("UPDATE `tbl_reports` SET `status` = 'resolved' WHERE `report_id` = ?");
                    $upStmt->execute([$reportId]);

                    // Notify reporter
                    $msg = "Your safety report (Ticket #{$reportId}) has been resolved. The reported user has been suspended.";
                    ab_add_notification($pdo, $reporterId, "Report Resolved: User Suspended", $msg, "bookings.html");

                    ab_add_audit_log($pdo, $userId, 'suspend_reported_user', 'users', $reportedId, "Suspended reported user ID {$reportedId} and resolved report Ticket #{$reportId}");

                    $pdo->commit();
                    echo json_encode(['status' => 'success', 'message' => "Report Ticket #{$reportId} resolved and reported user suspended successfully."]);
                    exit;

                } elseif ($action === 'ban_reported_user') {
                    // Update user to permanently banned and inactive
                    $userUp = $pdo->prepare("UPDATE `tbl_users` SET `status` = 'banned', `is_active` = 0, `status_reason` = ? WHERE `user_id` = ?");
                    $userUp->execute(["Banned from Safety Report Ticket #{$reportId}: {$reason}", $reportedId]);

                    // Resolve the report
                    $upStmt = $pdo->prepare("UPDATE `tbl_reports` SET `status` = 'resolved' WHERE `report_id` = ?");
                    $upStmt->execute([$reportId]);

                    // Notify reporter
                    $msg = "Your safety report (Ticket #{$reportId}) has been resolved. The reported user has been permanently banned.";
                    ab_add_notification($pdo, $reporterId, "Report Resolved: User Banned", $msg, "bookings.html");

                    ab_add_audit_log($pdo, $userId, 'ban_reported_user', 'users', $reportedId, "Permanently banned reported user ID {$reportedId} and resolved report Ticket #{$reportId}");

                    $pdo->commit();
                    echo json_encode(['status' => 'success', 'message' => "Report Ticket #{$reportId} resolved and reported user permanently banned successfully."]);
                    exit;

                } else {
                    // Normal resolve/dismiss action
                    $newStatus = ($action === 'resolve_report') ? 'resolved' : 'reviewed';
                    $upStmt = $pdo->prepare("UPDATE `tbl_reports` SET `status` = ? WHERE `report_id` = ?");
                    $upStmt->execute([$newStatus, $reportId]);

                    $msg = ($action === 'resolve_report') 
                        ? "Your submitted report (Ticket #{$reportId}) has been resolved. Thank you for keeping AnyBuddy safe!"
                        : "Your submitted report (Ticket #{$reportId}) has been reviewed by support.";
                    
                    ab_add_notification($pdo, $reporterId, "Report Status Update", $msg, "bookings.html");

                    ab_add_audit_log($pdo, $userId, $action, 'reports', $reportId, "Updated report Ticket #{$reportId} to {$newStatus} (Reporter: User ID {$reporterId})");

                    $pdo->commit();
                    echo json_encode(['status' => 'success', 'message' => "Report Ticket #{$reportId} updated to {$newStatus} successfully."]);
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'update_system_settings') {
            $commRate = trim((string)($input['commission_rate'] ?? '10'));
            $srvFee = trim((string)($input['service_fee'] ?? '50'));
            $maintMode = trim((string)($input['maintenance_mode'] ?? '0'));

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE `tbl_system_settings` SET `key_value` = ? WHERE `key_name` = ?");
                $stmt->execute([$commRate, 'commission_rate']);
                $stmt->execute([$srvFee, 'service_fee']);
                $stmt->execute([$maintMode, 'maintenance_mode']);

                ab_add_audit_log($pdo, $userId, 'update_system_settings', 'system', 0, "Updated settings: commission_rate={$commRate}%, service_fee=₱{$srvFee}, maintenance_mode={$maintMode}");

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'System settings updated successfully!']);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'cancel_refund_booking') {
            $bookingId = (int) ($input['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing booking ID.']);
                exit;
            }

            // Fetch booking details
            $bStmt = $pdo->prepare("
                SELECT b.`client_id`, bp.`user_id` AS buddy_user_id, bp.`display_name` AS buddy_name
                FROM `tbl_bookings` b
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                WHERE b.`booking_id` = ?
            ");
            $bStmt->execute([$bookingId]);
            $booking = $bStmt->fetch();

            if (!$booking) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
                exit;
            }

            $clientId = (int)$booking['client_id'];
            $buddyUserId = (int)$booking['buddy_user_id'];
            $buddyName = $booking['buddy_name'];

            $pdo->beginTransaction();
            try {
                // Status 5 is Declined / Cancelled
                $upStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 5 WHERE `booking_id` = ?");
                $upStmt->execute([$bookingId]);

                // Notify both client and buddy
                ab_add_notification($pdo, $clientId, "Booking Cancelled & Refunded", "Your booking with {$buddyName} (Booking ID: #{$bookingId}) has been cancelled and refunded by an administrator.", "bookings.html");
                ab_add_notification($pdo, $buddyUserId, "Booking Cancelled by Admin", "Booking ID #{$bookingId} has been cancelled by an administrator.", "bookings.html");

                ab_add_audit_log($pdo, $userId, 'cancel_refund_booking', 'bookings', $bookingId, "Admin cancelled and refunded booking ID {$bookingId}");

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => "Successfully cancelled and refunded Booking #{$bookingId}."]);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        } elseif ($action === 'force_complete_booking') {
            $bookingId = (int) ($input['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing booking ID.']);
                exit;
            }

            // Fetch booking details
            $bStmt = $pdo->prepare("
                SELECT b.`client_id`, bp.`user_id` AS buddy_user_id, bp.`display_name` AS buddy_name
                FROM `tbl_bookings` b
                INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
                WHERE b.`booking_id` = ?
            ");
            $bStmt->execute([$bookingId]);
            $booking = $bStmt->fetch();

            if (!$booking) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
                exit;
            }

            $clientId = (int)$booking['client_id'];
            $buddyUserId = (int)$booking['buddy_user_id'];
            $buddyName = $booking['buddy_name'];

            $pdo->beginTransaction();
            try {
                // Status 4 is Completed
                $upStmt = $pdo->prepare("UPDATE `tbl_bookings` SET `status_id` = 4 WHERE `booking_id` = ?");
                $upStmt->execute([$bookingId]);

                // Increment buddy's total gigs count
                $pdo->prepare("
                    UPDATE `tbl_buddy_profiles` bp
                    INNER JOIN `tbl_bookings` b ON b.`buddy_profile_id` = bp.`profile_id`
                    SET bp.`total_gigs` = bp.`total_gigs` + 1
                    WHERE b.`booking_id` = ?
                ")->execute([$bookingId]);

                // Notify client and buddy
                ab_add_notification($pdo, $clientId, "Booking Completed by Admin", "Your booking with {$buddyName} (Booking ID: #{$bookingId}) has been marked as Completed by an administrator.", "bookings.html");
                ab_add_notification($pdo, $buddyUserId, "Booking Completed by Admin", "Booking ID #{$bookingId} has been marked as Completed by an administrator. Payout released.", "bookings.html");

                ab_add_audit_log($pdo, $userId, 'force_complete_booking', 'bookings', $bookingId, "Admin force-completed booking ID {$bookingId}");

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => "Successfully marked Booking #{$bookingId} as Completed."]);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

        // --- USERS CRUD ACTIONS ---

        } elseif ($action === 'create_user') {
            $firstName = trim($input['first_name'] ?? '');
            $lastName = trim($input['last_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $roleValue = trim($input['role'] ?? 'client');
            $pronouns = trim($input['pronouns'] ?? '');
            $profilePhoto = trim($input['profile_photo'] ?? '');

            if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'First name, last name, email, and password are required.']);
                exit;
            }

            // Check email uniqueness
            $check = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `email` = ?");
            $check->execute([$email]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Email already registered.']);
                exit;
            }

            $pwdHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO `tbl_users` (`first_name`, `last_name`, `email`, `password_hash`, `pronouns`, `profile_photo`, `role`)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$firstName, $lastName, $email, $pwdHash, $pronouns, $profilePhoto ?: null, $roleValue]);
            $newUserId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_user', 'users', $newUserId, "Created user {$firstName} {$lastName} ({$email}) with role {$roleValue}");

            echo json_encode(['status' => 'success', 'message' => 'User created successfully!', 'user_id' => $newUserId]);
            exit;

        } elseif ($action === 'update_user') {
            $targetUserId = (int) ($input['target_user_id'] ?? 0);
            $firstName = trim($input['first_name'] ?? '');
            $lastName = trim($input['last_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $password = $input['password'] ?? '';
            $roleValue = trim($input['role'] ?? 'client');
            $pronouns = trim($input['pronouns'] ?? '');
            $profilePhoto = trim($input['profile_photo'] ?? '');
            
            $status = trim($input['status'] ?? 'active');
            if (!in_array($status, ['active', 'suspended', 'banned'])) {
                $status = 'active';
            }
            $isActive = isset($input['is_active']) ? (int) $input['is_active'] : 1;
            if ($status === 'suspended' || $status === 'banned') {
                $isActive = 0;
            }
            $statusReason = trim($input['status_reason'] ?? '');

            if ($targetUserId <= 0 || $firstName === '' || $lastName === '' || $email === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'User ID, names, and email are required.']);
                exit;
            }

            // Check email uniqueness
            $check = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `email` = ? AND `user_id` != ?");
            $check->execute([$email, $targetUserId]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Email already registered to another user.']);
                exit;
            }

            if ($password !== '') {
                $pwdHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    UPDATE `tbl_users` 
                    SET `first_name` = ?, `last_name` = ?, `email` = ?, `password_hash` = ?, `pronouns` = ?, `profile_photo` = ?, `role` = ?, `status` = ?, `is_active` = ?, `status_reason` = ?
                    WHERE `user_id` = ?
                ");
                $stmt->execute([$firstName, $lastName, $email, $pwdHash, $pronouns, $profilePhoto ?: null, $roleValue, $status, $isActive, $statusReason ?: null, $targetUserId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE `tbl_users` 
                    SET `first_name` = ?, `last_name` = ?, `email` = ?, `pronouns` = ?, `profile_photo` = ?, `role` = ?, `status` = ?, `is_active` = ?, `status_reason` = ?
                    WHERE `user_id` = ?
                ");
                $stmt->execute([$firstName, $lastName, $email, $pronouns, $profilePhoto ?: null, $roleValue, $status, $isActive, $statusReason ?: null, $targetUserId]);
            }

            ab_add_audit_log($pdo, $userId, 'update_user', 'users', $targetUserId, "Updated user details for {$firstName} {$lastName} ({$email}) with role {$roleValue}");

            echo json_encode(['status' => 'success', 'message' => 'User updated successfully!']);
            exit;

        } elseif ($action === 'delete_user') {
            $targetUserId = (int) ($input['target_user_id'] ?? 0);
            if ($targetUserId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid user ID.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM `tbl_users` WHERE `user_id` = ?");
            $stmt->execute([$targetUserId]);

            ab_add_audit_log($pdo, $userId, 'delete_user', 'users', $targetUserId, "Deleted user ID {$targetUserId}");

            echo json_encode(['status' => 'success', 'message' => 'User deleted successfully!']);
            exit;

        // --- BUDDIES CRUD ACTIONS ---

        } elseif ($action === 'create_buddy') {
            $targetUserId = (int) ($input['target_user_id'] ?? 0);
            $displayName = trim($input['display_name'] ?? '');
            $title = trim($input['professional_title'] ?? '');
            $category = trim($input['category'] ?? '');
            $bio = trim($input['bio'] ?? '');
            $rate = (float) ($input['hourly_rate'] ?? 0);
            $location = trim($input['location'] ?? '');
            $availability = trim($input['availability'] ?? '');

            if ($targetUserId <= 0 || $displayName === '' || $category === '' || $title === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'User selection, display name, category, and title are required.']);
                exit;
            }

            // Check if user already has a buddy profile
            $check = $pdo->prepare("SELECT COUNT(*) FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
            $check->execute([$targetUserId]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'This user already has a buddy profile.']);
                exit;
            }

            // Set role to buddy in tbl_users
            $pdo->prepare("UPDATE `tbl_users` SET `role` = 'buddy' WHERE `user_id` = ?")->execute([$targetUserId]);

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_buddy_profiles` (`user_id`, `display_name`, `professional_title`, `category`, `bio`, `hourly_rate`, `location`, `availability`, `is_available`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$targetUserId, $displayName, $title, $category, $bio, $rate, $location, $availability]);
            $newProfileId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_buddy', 'buddies', $newProfileId, "Created buddy profile for User ID: {$targetUserId} with title: {$title}");

            echo json_encode(['status' => 'success', 'message' => 'Buddy profile created successfully!', 'profile_id' => $newProfileId]);
            exit;

        } elseif ($action === 'update_buddy') {
            $profileId = (int) ($input['profile_id'] ?? 0);
            $displayName = trim($input['display_name'] ?? '');
            $title = trim($input['professional_title'] ?? '');
            $category = trim($input['category'] ?? '');
            $bio = trim($input['bio'] ?? '');
            $rate = (float) ($input['hourly_rate'] ?? 0);
            $location = trim($input['location'] ?? '');
            $availability = trim($input['availability'] ?? '');
            $isAvailable = isset($input['is_available']) ? (int) $input['is_available'] : 1;

            if ($profileId <= 0 || $displayName === '' || $category === '' || $title === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Profile ID, display name, category, and title are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE `tbl_buddy_profiles` 
                SET `display_name` = ?, `professional_title` = ?, `category` = ?, `bio` = ?, `hourly_rate` = ?, `location` = ?, `availability` = ?, `is_available` = ?
                WHERE `profile_id` = ?
            ");
            $stmt->execute([$displayName, $title, $category, $bio, $rate, $location, $availability, $isAvailable, $profileId]);

            ab_add_audit_log($pdo, $userId, 'update_buddy', 'buddies', $profileId, "Updated buddy profile details for {$displayName} (Profile ID: {$profileId})");

            echo json_encode(['status' => 'success', 'message' => 'Buddy profile updated successfully!']);
            exit;

        } elseif ($action === 'delete_buddy') {
            $profileId = (int) ($input['profile_id'] ?? 0);
            if ($profileId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid profile ID.']);
                exit;
            }

            // Get user_id of the buddy profile
            $uStmt = $pdo->prepare("SELECT `user_id` FROM `tbl_buddy_profiles` WHERE `profile_id` = ?");
            $uStmt->execute([$profileId]);
            $buddyUserId = $uStmt->fetchColumn();

            if ($buddyUserId !== false) {
                // Revert user role to client in tbl_users
                $pdo->prepare("UPDATE `tbl_users` SET `role` = 'client' WHERE `user_id` = ?")->execute([$buddyUserId]);
            }

            $stmt = $pdo->prepare("DELETE FROM `tbl_buddy_profiles` WHERE `profile_id` = ?");
            $stmt->execute([$profileId]);

            ab_add_audit_log($pdo, $userId, 'delete_buddy', 'buddies', $profileId, "Deleted buddy profile ID {$profileId} (User ID {$buddyUserId} reverted to client)");

            echo json_encode(['status' => 'success', 'message' => 'Buddy profile deleted successfully!']);
            exit;

        // --- BOOKINGS CRUD ACTIONS ---

        } elseif ($action === 'create_booking') {
            $clientId = (int) ($input['client_id'] ?? 0);
            $buddyProfileId = (int) ($input['buddy_profile_id'] ?? 0);
            $statusId = (int) ($input['status_id'] ?? 1);
            $bookingDate = trim($input['booking_date'] ?? '');
            $startTime = trim($input['start_time'] ?? '');
            $duration = (float) ($input['hours_duration'] ?? 1);
            $basePrice = (float) ($input['base_price'] ?? 0);
            $discount = (float) ($input['discount_amount'] ?? 0);
            $fee = (float) ($input['platform_fee'] ?? 0);
            $totalPrice = (float) ($input['total_price'] ?? 0);
            $paymentMethod = trim($input['payment_method'] ?? 'Cash On Hand');
            $messageText = trim($input['message'] ?? '');

            if ($clientId <= 0 || $buddyProfileId <= 0 || $bookingDate === '' || $startTime === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Client, Buddy, booking date, and start time are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_bookings` (`client_id`, `buddy_profile_id`, `status_id`, `booking_date`, `start_time`, `hours_duration`, `base_price`, `discount_amount`, `platform_fee`, `total_price`, `payment_method`, `message`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$clientId, $buddyProfileId, $statusId, $bookingDate, $startTime, $duration, $basePrice, $discount, $fee, $totalPrice, $paymentMethod, $messageText]);
            $newBookingId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_booking', 'bookings', $newBookingId, "Created booking ID {$newBookingId} for Client ID {$clientId} and Buddy Profile ID {$buddyProfileId}");

            echo json_encode(['status' => 'success', 'message' => 'Booking created successfully!', 'booking_id' => $newBookingId]);
            exit;

        } elseif ($action === 'update_booking') {
            $bookingId = (int) ($input['booking_id'] ?? 0);
            $clientId = (int) ($input['client_id'] ?? 0);
            $buddyProfileId = (int) ($input['buddy_profile_id'] ?? 0);
            $statusId = (int) ($input['status_id'] ?? 1);
            $bookingDate = trim($input['booking_date'] ?? '');
            $startTime = trim($input['start_time'] ?? '');
            $duration = (float) ($input['hours_duration'] ?? 1);
            $basePrice = (float) ($input['base_price'] ?? 0);
            $discount = (float) ($input['discount_amount'] ?? 0);
            $fee = (float) ($input['platform_fee'] ?? 0);
            $totalPrice = (float) ($input['total_price'] ?? 0);
            $paymentMethod = trim($input['payment_method'] ?? 'Cash On Hand');
            $messageText = trim($input['message'] ?? '');

            if ($bookingId <= 0 || $clientId <= 0 || $buddyProfileId <= 0 || $bookingDate === '' || $startTime === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Booking ID, Client, Buddy, date, and start time are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE `tbl_bookings` 
                SET `client_id` = ?, `buddy_profile_id` = ?, `status_id` = ?, `booking_date` = ?, `start_time` = ?, `hours_duration` = ?, `base_price` = ?, `discount_amount` = ?, `platform_fee` = ?, `total_price` = ?, `payment_method` = ?, `message` = ?
                WHERE `booking_id` = ?
            ");
            $stmt->execute([$clientId, $buddyProfileId, $statusId, $bookingDate, $startTime, $duration, $basePrice, $discount, $fee, $totalPrice, $paymentMethod, $messageText, $bookingId]);

            ab_add_audit_log($pdo, $userId, 'update_booking', 'bookings', $bookingId, "Updated booking ID {$bookingId} details (status_id: {$statusId})");

            echo json_encode(['status' => 'success', 'message' => 'Booking updated successfully!']);
            exit;

        } elseif ($action === 'delete_booking') {
            $bookingId = (int) ($input['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid booking ID.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM `tbl_bookings` WHERE `booking_id` = ?");
            $stmt->execute([$bookingId]);

            ab_add_audit_log($pdo, $userId, 'delete_booking', 'bookings', $bookingId, "Deleted booking ID {$bookingId}");

            echo json_encode(['status' => 'success', 'message' => 'Booking deleted successfully!']);
            exit;

        // --- VOUCHERS CRUD ACTIONS ---

        } elseif ($action === 'create_voucher') {
            $code = trim($input['code'] ?? '');
            $type = trim($input['discount_type'] ?? 'fixed');
            $val = (float) ($input['discount_value'] ?? 0);
            $spend = (float) ($input['min_spend'] ?? 0);
            $active = (int) ($input['is_active'] ?? 1);
            $expiry = trim($input['expiration_date'] ?? '');

            if ($code === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Voucher code is required.']);
                exit;
            }

            // Check uniqueness
            $check = $pdo->prepare("SELECT COUNT(*) FROM `tbl_vouchers` WHERE `code` = ?");
            $check->execute([$code]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Voucher code already exists.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_vouchers` (`code`, `discount_type`, `discount_value`, `min_spend`, `is_active`, `expiration_date`)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$code, $type, $val, $spend, $active, $expiry !== '' ? $expiry : null]);
            $newVoucherId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_voucher', 'vouchers', $newVoucherId, "Created voucher code: {$code} ({$type} discount of {$val})");

            echo json_encode(['status' => 'success', 'message' => 'Voucher created successfully!', 'voucher_id' => $newVoucherId]);
            exit;

        } elseif ($action === 'update_voucher') {
            $voucherId = (int) ($input['voucher_id'] ?? 0);
            $code = trim($input['code'] ?? '');
            $type = trim($input['discount_type'] ?? 'fixed');
            $val = (float) ($input['discount_value'] ?? 0);
            $spend = (float) ($input['min_spend'] ?? 0);
            $active = (int) ($input['is_active'] ?? 1);
            $expiry = trim($input['expiration_date'] ?? '');

            if ($voucherId <= 0 || $code === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Voucher ID and code are required.']);
                exit;
            }

            // Check uniqueness
            $check = $pdo->prepare("SELECT COUNT(*) FROM `tbl_vouchers` WHERE `code` = ? AND `voucher_id` != ?");
            $check->execute([$code, $voucherId]);
            if ($check->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Voucher code already registered to another voucher.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE `tbl_vouchers` 
                SET `code` = ?, `discount_type` = ?, `discount_value` = ?, `min_spend` = ?, `is_active` = ?, `expiration_date` = ?
                WHERE `voucher_id` = ?
            ");
            $stmt->execute([$code, $type, $val, $spend, $active, $expiry !== '' ? $expiry : null, $voucherId]);

            ab_add_audit_log($pdo, $userId, 'update_voucher', 'vouchers', $voucherId, "Updated voucher ID {$voucherId}: code {$code}");

            echo json_encode(['status' => 'success', 'message' => 'Voucher updated successfully!']);
            exit;

        } elseif ($action === 'delete_voucher') {
            $voucherId = (int) ($input['voucher_id'] ?? 0);
            if ($voucherId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid voucher ID.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM `tbl_vouchers` WHERE `voucher_id` = ?");
            $stmt->execute([$voucherId]);

            ab_add_audit_log($pdo, $userId, 'delete_voucher', 'vouchers', $voucherId, "Deleted voucher ID {$voucherId}");

            echo json_encode(['status' => 'success', 'message' => 'Voucher deleted successfully!']);
            exit;

        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid admin action.']);
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
