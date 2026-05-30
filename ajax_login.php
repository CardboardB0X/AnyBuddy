<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint #1b : User Login
 *  File   : ajax_login.php
 *  Method : POST (application/json  OR  application/x-www-form-urlencoded)
 *  Returns: JSON { status, message, [errors], [user] }
 * ============================================================
 *
 *  Expected POST body fields
 *  ─────────────────────────
 *  email     string  required
 *  password  string  required
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
    $input = $_POST;
}

$email    = strtolower(trim($input['email']    ?? ''));
$password = $input['password'] ?? '';

// ── Validate presence ────────────────────────────────────────
$errors = [];

if ($email === '') {
    $errors['email'] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

if ($password === '') {
    $errors['password'] = 'Password is required.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please fix the highlighted fields.',
        'errors'  => $errors,
    ]);
    exit;
}

// ── Fetch user by e-mail ─────────────────────────────────────
$pdo  = ab_pdo();
$stmt = $pdo->prepare(
    "SELECT u.`user_id`, u.`first_name`, u.`last_name`, u.`email`,
            u.`password_hash`, u.`pronouns`, u.`theme_preference`, u.`profile_photo`, u.`role`,
            u.`status`, u.`is_active`,
            bp.`profile_id` AS `buddy_profile_id`, bp.`bio`
     FROM `tbl_users` u
     LEFT JOIN `tbl_buddy_profiles` bp ON bp.`user_id` = u.`user_id`
     WHERE u.`email` = ?
     LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── Verify password (constant-time comparison via PHP) ───────
if (!$user || !password_verify($password, $user['password_hash'])) {
    sleep(1); // Basic brute-force deterrent
    // Deliberate generic message to prevent user enumeration
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid email or password.',
        'errors'  => ['password' => 'Credentials did not match our records.'],
    ]);
    exit;
}

// ── Verify account activation status ─────────────────────────
if ($user['status'] === 'suspended') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your account has been suspended by an administrator due to safety or policy violations.',
    ]);
    exit;
}

if ($user['status'] === 'banned') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your account has been permanently banned due to safety or policy violations.',
    ]);
    exit;
}

if ((int)$user['is_active'] === 0) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Your account is currently disabled or inactive.',
    ]);
    exit;
}

// ── Establish Session ────────────────────────────────────────
session_regenerate_id(true); // Prevent session fixation
$_SESSION['user_id']    = (int) $user['user_id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['role']       = $user['role'];

http_response_code(200);
echo json_encode([
    'status'  => 'success',
    'message' => 'Welcome back, ' . htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8') . '!',
    'user'    => [
        'id'               => (int) $user['user_id'],
        'user_id'          => (int) $user['user_id'],
        'first_name'       => $user['first_name'],
        'last_name'        => $user['last_name'],
        'email'            => $user['email'],
        'pronouns'         => $user['pronouns'],
        'theme_preference' => $user['theme_preference'],
        'avatar_url'       => $user['profile_photo'],
        'profile_photo'    => $user['profile_photo'],
        'bio'              => $user['bio'] ?? '',
        'role'             => $user['role'],
        'is_buddy'         => !empty($user['buddy_profile_id']),
        'buddy_profile_id' => $user['buddy_profile_id'] ? (int) $user['buddy_profile_id'] : null,
    ],
    'redirect' => 'homepage.html',
]);
