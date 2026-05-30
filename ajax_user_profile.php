<?php
/**
 * ============================================================
 *  AnyBuddy — User Profile AJAX Endpoint
 *  File   : ajax_user_profile.php
 *  Method : GET (fetch user info) / POST (update user info)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

/**
 * Saves a base64 encoded image to the filesystem.
 * Returns the relative path to the image or null on failure.
 */
function save_base64_image(string $base64Data, string $prefix, int $userId): ?string {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $ext = strtolower($type[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, ['jpg', 'png', 'gif', 'webp'])) {
            return null;
        }
        $data = base64_decode($data);
        if ($data === false) {
            return null;
        }

        // Add file size limit (e.g. 5MB)
        if (strlen($data) > 5 * 1024 * 1024) {
            return null;
        }

        // Validate actual MIME type using finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            return null;
        }
        $dir = __DIR__ . '/images/buddies';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = "{$prefix}_{$userId}_" . time() . ".{$ext}";
        $filepath = "{$dir}/{$filename}";
        if (file_put_contents($filepath, $data) !== false) {
            return "images/buddies/{$filename}";
        }
    }
    return null;
}

header('Content-Type: application/json; charset=utf-8');

// Ensure session exists or read user_id from authorization/payload
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true) ?? [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = ab_pdo();

    if ($method === 'POST') {
        $userId = ab_require_auth();

        $action = isset($data['action']) ? trim($data['action']) : '';

        // ── ACTION 1: UPDATE PERSONAL INFO ──
        if ($action === 'update_personal_info') {
            $avatarUrl = isset($data['avatar_url']) ? trim($data['avatar_url']) : null;
            $bio = isset($data['bio']) ? trim($data['bio']) : null;
            $pronouns = isset($data['pronouns']) ? trim($data['pronouns']) : null;
            $firstName = isset($data['first_name']) ? trim($data['first_name']) : '';
            $lastName = isset($data['last_name']) ? trim($data['last_name']) : '';
            
            $emergencyName = isset($data['emergency_name']) ? trim($data['emergency_name']) : '';
            $emergencyEmail = isset($data['emergency_email']) ? trim($data['emergency_email']) : '';
            $emergencyPhone = isset($data['emergency_phone']) ? trim($data['emergency_phone']) : '';

            // Handle base64 avatar image upload
            if (!empty($data['avatar_image_data'])) {
                $newAvatarPath = save_base64_image($data['avatar_image_data'], 'avatar', $userId);
                if ($newAvatarPath) {
                    // Delete old local avatar
                    $oldStmt = $pdo->prepare("SELECT `profile_photo` FROM `tbl_users` WHERE `user_id` = ?");
                    $oldStmt->execute([$userId]);
                    $oldAvatar = $oldStmt->fetchColumn();
                    if ($oldAvatar && strpos($oldAvatar, 'images/buddies/') === 0) {
                        $oldFilePath = __DIR__ . '/' . $oldAvatar;
                        if (file_exists($oldFilePath)) {
                            @unlink($oldFilePath);
                        }
                    }
                    $avatarUrl = $newAvatarPath;
                }
            }

            if ($avatarUrl === '') {
                // Delete previous avatar file
                $oldStmt = $pdo->prepare("SELECT `profile_photo` FROM `tbl_users` WHERE `user_id` = ?");
                $oldStmt->execute([$userId]);
                $oldAvatar = $oldStmt->fetchColumn();
                if ($oldAvatar && strpos($oldAvatar, 'images/buddies/') === 0) {
                    $oldFilePath = __DIR__ . '/' . $oldAvatar;
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                }
                $avatarUrl = null;
            }

            // Update user first_name, last_name, profile_photo, pronouns
            $stmt = $pdo->prepare("
                UPDATE `tbl_users` 
                SET `first_name` = :first_name,
                    `last_name` = :last_name,
                    `profile_photo` = :avatar_url, 
                    `pronouns` = :pronouns
                WHERE `user_id` = :user_id
            ");
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':avatar_url' => $avatarUrl,
                ':pronouns' => $pronouns,
                ':user_id' => $userId
            ]);

            // Update emergency contacts
            if ($emergencyName !== '' || $emergencyEmail !== '' || $emergencyPhone !== '') {
                $stmtContacts = $pdo->prepare("
                    INSERT INTO `tbl_emergency_contacts` (`user_id`, `contact_name`, `contact_email`, `contact_phone`)
                    VALUES (:user_id, :contact_name, :contact_email, :contact_phone)
                    ON DUPLICATE KEY UPDATE 
                        `contact_name` = VALUES(`contact_name`),
                        `contact_email` = VALUES(`contact_email`),
                        `contact_phone` = VALUES(`contact_phone`)
                ");
                $stmtContacts->execute([
                    ':user_id' => $userId,
                    ':contact_name' => $emergencyName,
                    ':contact_email' => $emergencyEmail,
                    ':contact_phone' => $emergencyPhone
                ]);
            }

            // Update buddy profiles bio if buddy exists
            $buddyCheckStmt = $pdo->prepare("SELECT `profile_id` FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
            $buddyCheckStmt->execute([$userId]);
            $buddyProfileId = $buddyCheckStmt->fetchColumn();
            if ($buddyProfileId !== false) {
                $upTblBuddy = $pdo->prepare("UPDATE `tbl_buddy_profiles` SET `bio` = ? WHERE `user_id` = ?");
                $upTblBuddy->execute([$bio, $userId]);
            }

            ab_add_audit_log($pdo, $userId, 'update_profile', 'users', $userId, "User updated personal details and emergency contacts.");

            echo json_encode([
                'status' => 'success',
                'message' => 'Personal details updated successfully!',
                'avatar_url' => $avatarUrl,
                'bio' => $bio,
                'pronouns' => $pronouns
            ]);
        // ── ACTION 1b: UPDATE PRESENCE STATUS ──
        } elseif ($action === 'update_presence_status') {
            $status = isset($data['status']) ? trim($data['status']) : 'online';
            $isAvailable = ($status === 'online') ? 1 : 0;
            
            $stmt = $pdo->prepare("UPDATE `tbl_buddy_profiles` SET `is_available` = ? WHERE `user_id` = ?");
            $stmt->execute([$isAvailable, $userId]);
            
            echo json_encode(['status' => 'success', 'message' => 'Presence status updated successfully!']);
            exit;

        // ── ACTION 2: ADD PAYMENT CARD ──
        } elseif ($action === 'add_payment_card') {
            $holder = isset($data['cardholder_name']) ? trim($data['cardholder_name']) : '';
            $number = isset($data['card_number']) ? trim($data['card_number']) : '';
            $expiry = isset($data['expiry_date']) ? trim($data['expiry_date']) : '';

            $number = preg_replace('/[^0-9]/', '', $number);
            if (strlen($number) < 12) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid card number.']);
                exit;
            }

            if ($holder === '' || $expiry === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Missing payment details.']);
                exit;
            }

            $maskedNumber = '**** **** **** ' . substr($number, -4);

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_payment_methods` (`user_id`, `cardholder_name`, `card_number`, `expiry_date`)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $holder, $maskedNumber, $expiry]);
            $cardId = (int) $pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'add_payment_method', 'users', $userId, "User added a new payment card ID {$cardId}.");

            echo json_encode([
                'status' => 'success',
                'message' => 'Card added successfully!',
                'card_id' => $cardId
            ]);
            exit;

        // ── ACTION 3: DELETE PAYMENT CARD ──
        } elseif ($action === 'delete_payment_card') {
            $cardId = isset($data['card_id']) ? (int) $data['card_id'] : 0;
            if ($cardId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Missing card ID.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM `tbl_payment_methods` WHERE `id` = ? AND `user_id` = ?");
            $stmt->execute([$cardId, $userId]);

            ab_add_audit_log($pdo, $userId, 'delete_payment_method', 'users', $userId, "User deleted payment card ID {$cardId}.");

            echo json_encode(['status' => 'success', 'message' => 'Card deleted.']);
            exit;

        // ── ACTION 4: UPDATE OR REGISTER BUDDY PROFILE ──
        } elseif ($action === 'update_buddy_profile') {
            $isRegistering = !empty($data['is_registering']);
            
            $displayName = isset($data['display_name']) ? trim($data['display_name']) : '';
            $category = isset($data['category']) ? trim($data['category']) : '';
            $title = isset($data['title']) ? trim($data['title']) : '';
            $rate = isset($data['rate']) ? (float)$data['rate'] : 0.00;
            $location = isset($data['location']) ? trim($data['location']) : '';
            $availability = isset($data['availability']) ? trim($data['availability']) : '';
            $bio = isset($data['bio']) ? trim($data['bio']) : '';
            
            $verificationType = isset($data['verification_type']) ? trim($data['verification_type']) : 'id';
            $idPhotoUrl = isset($data['id_photo_url']) ? trim($data['id_photo_url']) : null;
            
            $languages = isset($data['languages']) ? $data['languages'] : []; // Array of IDs
            $specialties = isset($data['specialties']) ? $data['specialties'] : []; // Array of IDs
            
            $deletedGalleryIds = isset($data['deleted_gallery_ids']) ? $data['deleted_gallery_ids'] : []; // Array of IDs
            $galleryImagesData = isset($data['gallery_images_data']) ? $data['gallery_images_data'] : []; // Array of base64 strings

            if ($displayName === '' || $category === '' || $title === '' || $rate < 0 || $location === '' || $availability === '' || $bio === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Missing required buddy details.']);
                exit;
            }

            $pdo->beginTransaction();

            // Handle ID document photo upload if provided
            if (!empty($data['id_photo_image_data'])) {
                $newIdPhotoPath = save_base64_image($data['id_photo_image_data'], 'id_photo', $userId);
                if ($newIdPhotoPath) {
                    $idPhotoUrl = $newIdPhotoPath;
                }
            }

            $profileId = null;
            if ($isRegistering) {
                // Update role to buddy in users
                $pdo->prepare("UPDATE `tbl_users` SET `role` = 'buddy' WHERE `user_id` = ?")->execute([$userId]);
                
                // Insert profile
                $bpStmt = $pdo->prepare("
                    INSERT INTO `tbl_buddy_profiles` 
                    (`user_id`, `display_name`, `professional_title`, `category`, `bio`, `hourly_rate`, `location`, `availability`, `is_available`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $bpStmt->execute([$userId, $displayName, $title, $category, $bio, $rate, $location, $availability]);
                $profileId = (int)$pdo->lastInsertId();
                
                // Insert verification
                $verifStmt = $pdo->prepare("
                    INSERT INTO `tbl_buddy_verifications` (`profile_id`, `verification_type`, `id_photo_url`, `verification_status`)
                    VALUES (?, ?, ?, 'pending')
                ");
                $verifStmt->execute([$profileId, $verificationType, $idPhotoUrl]);

                // Notify Admins and User
                ab_add_notification($pdo, $userId, "Buddy Application Submitted", "Your buddy profile has been submitted and is awaiting administrator verification.", "profile.html");
                $admins = $pdo->query("SELECT `user_id` FROM `tbl_users` WHERE `role` = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $adminId) {
                    ab_add_notification($pdo, (int)$adminId, "New Buddy Application", "A new buddy profile is pending review. Click to inspect.", "admin.html");
                }
            } else {
                // Update profile
                $bpStmt = $pdo->prepare("SELECT `profile_id` FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
                $bpStmt->execute([$userId]);
                $profileId = (int)$bpStmt->fetchColumn();

                if (!$profileId) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Buddy profile not found.']);
                    exit;
                }

                $upStmt = $pdo->prepare("
                    UPDATE `tbl_buddy_profiles` 
                    SET `display_name` = ?,
                        `professional_title` = ?,
                        `category` = ?,
                        `bio` = ?,
                        `hourly_rate` = ?,
                        `location` = ?,
                        `availability` = ?
                    WHERE `profile_id` = ?
                ");
                $upStmt->execute([$displayName, $title, $category, $bio, $rate, $location, $availability, $profileId]);

                // Update verification if details updated
                if ($idPhotoUrl !== null) {
                    $verifStmt = $pdo->prepare("
                        INSERT INTO `tbl_buddy_verifications` (`profile_id`, `verification_type`, `id_photo_url`, `verification_status`)
                        VALUES (?, ?, ?, 'pending')
                        ON DUPLICATE KEY UPDATE 
                            `verification_type` = VALUES(`verification_type`),
                            `id_photo_url` = VALUES(`id_photo_url`),
                            `verification_status` = 'pending'
                    ");
                    $verifStmt->execute([$profileId, $verificationType, $idPhotoUrl]);
                }
            }

            // ── Update languages checklist ──
            $pdo->prepare("DELETE FROM `tbl_buddy_languages` WHERE `profile_id` = ?")->execute([$profileId]);
            if (!empty($languages)) {
                $langIns = $pdo->prepare("INSERT INTO `tbl_buddy_languages` (`profile_id`, `language_id`) VALUES (?, ?)");
                foreach ($languages as $langId) {
                    $langIns->execute([$profileId, $langId]);
                }
            }

            // ── Update specialties checklist ──
            $pdo->prepare("DELETE FROM `tbl_buddy_specialties` WHERE `profile_id` = ?")->execute([$profileId]);
            if (!empty($specialties)) {
                $specIns = $pdo->prepare("INSERT INTO `tbl_buddy_specialties` (`profile_id`, `specialty_id`) VALUES (?, ?)");
                foreach ($specialties as $specId) {
                    $specIns->execute([$profileId, $specId]);
                }
            }

            // ── Update gallery images ──
            // 1. Delete requested gallery photos
            if (!empty($deletedGalleryIds)) {
                $delStmt = $pdo->prepare("SELECT `image_url` FROM `tbl_buddy_gallery` WHERE `id` = ? AND `profile_id` = ?");
                $delRow = $pdo->prepare("DELETE FROM `tbl_buddy_gallery` WHERE `id` = ? AND `profile_id` = ?");
                foreach ($deletedGalleryIds as $imgId) {
                    $delStmt->execute([$imgId, $profileId]);
                    $path = $delStmt->fetchColumn();
                    if ($path && file_exists(__DIR__ . '/' . $path)) {
                        @unlink(__DIR__ . '/' . $path);
                    }
                    $delRow->execute([$imgId, $profileId]);
                }
            }

            // 2. Insert new gallery photos (saved dynamically in images/buddies)
            if (!empty($galleryImagesData)) {
                $galIns = $pdo->prepare("INSERT INTO `tbl_buddy_gallery` (`profile_id`, `image_url`) VALUES (?, ?)");
                foreach ($galleryImagesData as $idx => $base64) {
                    $path = save_base64_image($base64, 'gallery_' . $idx, $userId);
                    if ($path) {
                        $galIns->execute([$profileId, $path]);
                    }
                }
            }

            $pdo->commit();

            ab_add_audit_log($pdo, $userId, 'update_buddy_profile', 'buddies', $profileId, $isRegistering ? "User applied to become a Buddy (Profile ID: {$profileId})" : "Buddy updated profile settings (Profile ID: {$profileId})");

            echo json_encode([
                'status' => 'success',
                'message' => $isRegistering ? 'Buddy application submitted successfully!' : 'Buddy profile settings saved!',
                'profile_id' => $profileId
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter.']);
        exit;
    }

    // GET request logic
    $userId = ab_require_auth();

    // Fetch user and emergency details
    $stmt = $pdo->prepare("
        SELECT 
            u.`user_id`, u.`first_name`, u.`last_name`, u.`email`, u.`pronouns`, u.`profile_photo`, u.`role`, u.`theme_preference`,
            ec.`contact_name`, ec.`contact_email`, ec.`contact_phone`,
            bp.`profile_id` AS buddy_profile_id,
            bv.`verification_status`, bv.`verification_type`, bv.`id_photo_url`
        FROM `tbl_users` u
        LEFT JOIN `tbl_emergency_contacts` ec ON ec.`user_id` = u.`user_id`
        LEFT JOIN `tbl_buddy_profiles` bp ON bp.`user_id` = u.`user_id`
        LEFT JOIN `tbl_buddy_verifications` bv ON bv.`profile_id` = bp.`profile_id`
        WHERE u.`user_id` = :id
    ");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    // Loyalty Tier & completed bookings details
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `tbl_bookings` WHERE `client_id` = ? AND `status_id` = 4");
    $countStmt->execute([$userId]);
    $completedCount = (int)$countStmt->fetchColumn();

    $tierStmt = $pdo->prepare("
        SELECT `tier_name`, `platform_fee_percent`, `discount_percent` FROM `tbl_user_tiers` 
        WHERE `min_bookings` <= ? 
        ORDER BY `min_bookings` DESC LIMIT 1
    ");
    $tierStmt->execute([$completedCount]);
    $tier = $tierStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'tier_name' => 'Bronze',
        'platform_fee_percent' => 5.0,
        'discount_percent' => 0.0
    ];

    // Bookings list details
    $bookingsStmt = $pdo->prepare("
        SELECT 
            b.`booking_id`,
            b.`booking_date`,
            b.`start_time`,
            b.`base_price`,
            b.`platform_fee`,
            b.`discount_amount`,
            (b.`base_price` + b.`platform_fee` - b.`discount_amount`) AS `total_price`,
            bs.`status_name` AS `status`,
            cu.`first_name` AS `client_first_name`,
            cu.`last_name` AS `client_last_name`,
            bp.`display_name` AS `buddy_name`
        FROM `tbl_bookings` b
        INNER JOIN `tbl_users` cu ON cu.`user_id` = b.`client_id`
        INNER JOIN `tbl_buddy_profiles` bp ON bp.`profile_id` = b.`buddy_profile_id`
        INNER JOIN `tbl_booking_statuses` bs ON bs.`status_id` = b.`status_id`
        WHERE b.`client_id` = ? OR bp.`user_id` = ?
        ORDER BY b.`booking_date` DESC, b.`start_time` DESC
    ");
    $bookingsStmt->execute([$userId, $userId]);
    $bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $formattedBookings = [];
    foreach ($bookings as $b) {
        $formattedBookings[] = [
            'id' => (int)$b['booking_id'],
            'booking_date' => $b['booking_date'],
            'start_time' => substr($b['start_time'], 0, 5),
            'base_price' => (float)$b['base_price'],
            'platform_fee' => (float)$b['platform_fee'],
            'discount_amount' => (float)$b['discount_amount'],
            'total_price' => (float)$b['total_price'],
            'status' => $b['status'],
            'client_name' => $b['client_first_name'] . ' ' . $b['client_last_name'],
            'buddy_name' => $b['buddy_name']
        ];
    }

    // Payment methods saved list
    $cardsStmt = $pdo->prepare("SELECT `id`, `cardholder_name`, `card_number`, `expiry_date` FROM `tbl_payment_methods` WHERE `user_id` = ? ORDER BY `id` DESC");
    $cardsStmt->execute([$userId]);
    $cards = $cardsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Lookup structures
    $languagesList = $pdo->query("SELECT `language_id`, `language_name` FROM `tbl_languages` ORDER BY `language_name` ASC")->fetchAll(PDO::FETCH_ASSOC);
    $specialtiesList = $pdo->query("SELECT `specialty_id`, `specialty_name` FROM `tbl_specialties` ORDER BY `specialty_name` ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Buddy Profile Details if applicable
    $buddyProfile = null;
    $buddyLanguages = [];
    $buddySpecialties = [];
    $buddyGallery = [];
    
    if ($user['buddy_profile_id']) {
        $bpStmt = $pdo->prepare("SELECT * FROM `tbl_buddy_profiles` WHERE `profile_id` = ?");
        $bpStmt->execute([$user['buddy_profile_id']]);
        $buddyProfile = $bpStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        
        if ($buddyProfile) {
            // languages
            $blStmt = $pdo->prepare("SELECT `language_id` FROM `tbl_buddy_languages` WHERE `profile_id` = ?");
            $blStmt->execute([$user['buddy_profile_id']]);
            $buddyLanguages = $blStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            
            // specialties
            $bsStmt = $pdo->prepare("SELECT `specialty_id` FROM `tbl_buddy_specialties` WHERE `profile_id` = ?");
            $bsStmt->execute([$user['buddy_profile_id']]);
            $buddySpecialties = $bsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            
            // gallery
            $bgStmt = $pdo->prepare("SELECT `id`, `image_url` FROM `tbl_buddy_gallery` WHERE `profile_id` = ? ORDER BY `id` ASC");
            $bgStmt->execute([$user['buddy_profile_id']]);
            $buddyGallery = $bgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    echo json_encode([
        'status' => 'success',
        'user' => [
            'id' => (int) $user['user_id'],
            'user_id' => (int) $user['user_id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'pronouns' => $user['pronouns'],
            'avatar_url' => $user['profile_photo'],
            'emergency_name' => $user['contact_name'] ?? '',
            'emergency_email' => $user['contact_email'] ?? '',
            'emergency_phone' => $user['contact_phone'] ?? '',
            'role' => $user['role'],
            'theme_preference' => $user['theme_preference'],
            'buddy_profile_id' => $user['buddy_profile_id'] ? (int) $user['buddy_profile_id'] : null,
            'verification_status' => $user['verification_status'] ?: 'none',
            'verification_type' => $user['verification_type'] ?: 'id',
            'id_photo_url' => $user['id_photo_url']
        ],
        'completed_bookings' => $completedCount,
        'loyalty_tier' => $tier,
        'bookings' => $formattedBookings,
        'payment_methods' => $cards,
        'languages_list' => $languagesList,
        'specialties_list' => $specialtiesList,
        'buddy_profile' => $buddyProfile,
        'buddy_languages' => array_map('intval', $buddyLanguages),
        'buddy_specialties' => array_map('intval', $buddySpecialties),
        'buddy_gallery' => $buddyGallery
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.', 'detail' => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null]);
}
