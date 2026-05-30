<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint #2 : Become a Buddy Registration
 *  File   : ajax_become_buddy.php
 *  Method : POST (application/json  OR  multipart/form-data  OR  urlencoded)
 *  Returns: JSON { status, message, [errors], [profile] }
 * ============================================================
 *
 *  Expected POST body fields
 *  ─────────────────────────
 *  user_id          int     required  (logged-in user's ID)
 *  display_name     string  required
 *  title            string  required  (professional_title)
 *  category         string  required  one of: casual|event|security|arts|listener|ally
 *  bio              string  required  ≥ 20 characters
 *  rate             number  required  numeric ≥ 0
 *  location         string  required
 *  availability     string  required
 */

declare(strict_types=1);

// ── JSON response header ─────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

// ── Decode payload ───────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
} else {
    // multipart/form-data or application/x-www-form-urlencoded
    $input = $_POST;
}

// ── Allowed category values (must match DB ENUM exactly) ─────
const VALID_CATEGORIES = ['casual', 'event', 'security', 'arts', 'listener', 'ally'];

// ── Sanitise helper ──────────────────────────────────────────
function clean_str(mixed $v): string
{
    return trim(htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'));
}

// ── Extract fields ───────────────────────────────────────────
$userId           = ab_require_auth();
$displayName      = clean_str($input['display-name'] ?? $input['display_name'] ?? '');
$title            = clean_str($input['title']        ?? '');
$category         = strtolower(trim($input['category'] ?? ''));
$bio              = trim($input['bio'] ?? '');
$rate             = $input['rate'] ?? '';
$location         = clean_str($input['location']     ?? '');
$availability     = clean_str($input['availability'] ?? '');
$verificationType = strtolower(clean_str($input['verification_type'] ?? 'id'));

if (!in_array($verificationType, ['student', 'professional', 'id'], true)) {
    $verificationType = 'id';
}

// ── Validate ─────────────────────────────────────────────────
$errors = [];

// user_id is checked via session

if ($displayName === '') {
    $errors['display_name'] = 'Display name is required.';
} elseif (strlen($displayName) > 120) {
    $errors['display_name'] = 'Display name must be 120 characters or fewer.';
}

if ($title === '') {
    $errors['title'] = 'Professional title is required.';
} elseif (strlen($title) > 150) {
    $errors['title'] = 'Professional title must be 150 characters or fewer.';
}

if ($category === '' || $category === 'select a category') {
    $errors['category'] = 'Please select a category.';
} elseif (!in_array($category, VALID_CATEGORIES, true)) {
    $errors['category'] = 'Invalid category selected.';
}

if ($bio === '') {
    $errors['bio'] = 'Bio / overview is required.';
} elseif (mb_strlen($bio) < 20) {
    $errors['bio'] = 'Bio must be at least 20 characters.';
}

if ($rate === '' || !is_numeric($rate)) {
    $errors['rate'] = 'Hourly rate must be a valid number.';
} elseif ((float)$rate < 0) {
    $errors['rate'] = 'Hourly rate cannot be negative.';
} elseif ((float)$rate > 99999.99) {
    $errors['rate'] = 'Hourly rate exceeds the maximum allowed value.';
}

if ($location === '') {
    $errors['location'] = 'Location is required.';
}

if ($availability === '') {
    $errors['availability'] = 'Availability is required.';
}

// ── File Upload Processing ───────────────────────────────────
$pdo = ab_pdo();

// Check for existing user account and buddy profile in 3NF tables
$existingProfile = null;
$userPhoto = null;
if ($userId > 0) {
    $stmtUserCheck = $pdo->prepare(
        "SELECT u.`profile_photo`, bp.`profile_id`, bv.`id_photo_url`
         FROM `tbl_users` u
         LEFT JOIN `tbl_buddy_profiles` bp ON bp.`user_id` = u.`user_id`
         LEFT JOIN `tbl_buddy_verifications` bv ON bv.`profile_id` = bp.`profile_id`
         WHERE u.`user_id` = ? 
         LIMIT 1"
    );
    $stmtUserCheck->execute([$userId]);
    $existingProfile = $stmtUserCheck->fetch(PDO::FETCH_ASSOC);
}

if (!$existingProfile) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'error',
        'message' => 'User account not found.',
    ]);
    exit;
}

$avatarUrl = $existingProfile['profile_photo'] ?? null;
$idPhotoUrl = $existingProfile['id_photo_url'] ?? null;

$avatarsDir = __DIR__ . '/uploads/avatars';
$verificationsDir = __DIR__ . '/uploads/verification';

if (!is_dir($avatarsDir)) {
    mkdir($avatarsDir, 0777, true);
}
if (!is_dir($verificationsDir)) {
    mkdir($verificationsDir, 0777, true);
}

// Avatar upload validation & save
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $avatarTmp = $_FILES['avatar']['tmp_name'];
    $avatarExt = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
    $allowedImg = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($avatarExt, $allowedImg, true)) {
        $errors['avatar'] = 'Invalid image type. Allowed: JPG, JPEG, PNG, WEBP, GIF.';
    } else {
        $avatarName = uniqid('avatar_') . '.' . $avatarExt;
        $avatarPath = $avatarsDir . '/' . $avatarName;
        if (move_uploaded_file($avatarTmp, $avatarPath)) {
            $avatarUrl = 'uploads/avatars/' . $avatarName;
        } else {
            $errors['avatar'] = 'Failed to save avatar image file.';
        }
    }
} elseif (!$existingProfile['profile_id'] && !$avatarUrl) {
    $errors['avatar'] = 'Profile avatar picture is required.';
}

// ID document upload validation & save
if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
    $idTmp = $_FILES['id_photo']['tmp_name'];
    $idExt = strtolower(pathinfo($_FILES['id_photo']['name'], PATHINFO_EXTENSION));
    $allowedDoc = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    if (!in_array($idExt, $allowedDoc, true)) {
        $errors['id_photo'] = 'Invalid document type. Allowed: JPG, JPEG, PNG, WEBP, PDF.';
    } else {
        $idName = uniqid('id_') . '.' . $idExt;
        $idPath = $verificationsDir . '/' . $idName;
        if (move_uploaded_file($idTmp, $idPath)) {
            $idPhotoUrl = 'uploads/verification/' . $idName;
        } else {
            $errors['id_photo'] = 'Failed to save verification document file.';
        }
    }
} elseif (!$existingProfile['profile_id'] && !$idPhotoUrl) {
    $errors['id_photo'] = 'Verification document photo is required.';
}

// ── Return early on validation failure ───────────────────────
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please fix the highlighted fields and try again.',
        'errors'  => $errors,
    ]);
    exit;
}

$hourlyRate = round((float)$rate, 2);
$bioClean   = htmlspecialchars($bio, ENT_QUOTES, 'UTF-8');

// ── Transactional INSERT or UPDATE ───────────────────────────
try {
    $pdo->beginTransaction();

    // 1. Update user's avatar and role in tbl_users
    $stmtUserUpdate = $pdo->prepare("
        UPDATE `tbl_users`
        SET `profile_photo` = ?, `role` = 'buddy'
        WHERE `user_id` = ?
    ");
    $stmtUserUpdate->execute([$avatarUrl, $userId]);

    $profileId = null;
    if ($existingProfile['profile_id']) {
        $profileId = (int) $existingProfile['profile_id'];
        // Update existing buddy profile
        $stmtSave = $pdo->prepare(
            "UPDATE `tbl_buddy_profiles`
             SET `display_name`        = ?,
                 `professional_title`  = ?,
                 `category`            = ?,
                 `bio`                 = ?,
                 `hourly_rate`         = ?,
                 `location`            = ?,
                 `availability`        = ?
             WHERE `user_id` = ?"
        );
        $stmtSave->execute([
            $displayName,
            $title,
            $category,
            $bioClean,
            $hourlyRate,
            $location,
            $availability,
            $userId,
        ]);

        $httpStatus  = 200;
        $successMsg  = 'Your Buddy profile has been updated and submitted for review!';
        $action      = 'updated';
    } else {
        // Insert new profile (initially available but pending verification status)
        $stmtSave = $pdo->prepare(
            "INSERT INTO `tbl_buddy_profiles`
             (`user_id`, `display_name`, `professional_title`, `category`,
              `bio`, `hourly_rate`, `location`, `availability`, `is_available`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmtSave->execute([
            $userId,
            $displayName,
            $title,
            $category,
            $bioClean,
            $hourlyRate,
            $location,
            $availability
        ]);

        $profileId  = (int) $pdo->lastInsertId();
        $httpStatus = 201;
        $successMsg = 'Your Buddy profile has been submitted and is pending verification!';
        $action     = 'created';
    }

    // 2. Insert or update tbl_buddy_verifications
    $stmtVerif = $pdo->prepare("
        INSERT INTO `tbl_buddy_verifications` (`profile_id`, `verification_type`, `id_photo_url`, `verification_status`)
        VALUES (?, ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE
            `verification_type` = VALUES(`verification_type`),
            `id_photo_url` = VALUES(`id_photo_url`),
            `verification_status` = 'pending'
    ");
    $stmtVerif->execute([$profileId, $verificationType, $idPhotoUrl]);

    ab_add_audit_log($pdo, $userId, 'register_buddy', 'buddies', $profileId, "User registered/updated their buddy profile (Profile ID: {$profileId}, action: {$action})");

    $pdo->commit();

    // Send notifications
    ab_add_notification(
        $pdo,
        $userId,
        "Buddy Application Submitted",
        "Your buddy profile has been submitted and is awaiting administrator verification.",
        "profile.html"
    );

    $adminsStmt = $pdo->query("SELECT `user_id` FROM `tbl_users` WHERE `role` = 'admin'");
    $admins = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        ab_add_notification(
            $pdo,
            (int)$adminId,
            "New Buddy Application",
            "A new buddy profile is pending review. Click to view.",
            "admin.html"
        );
    }

    http_response_code($httpStatus);
    echo json_encode([
        'status'  => 'success',
        'action'  => $action,
        'message' => $successMsg,
        'profile' => [
            'id'                 => $profileId,
            'user_id'            => $userId,
            'display_name'       => $displayName,
            'professional_title' => $title,
            'category'           => $category,
            'hourly_rate'        => $hourlyRate,
            'location'           => $location,
            'availability'       => $availability,
            'verification_status'=> 'pending',
            'avatar_url'         => $avatarUrl,
        ],
        'redirect' => 'marketplace.html',
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'An error occurred while saving your profile.',
        'detail'  => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null,
    ]);
}
