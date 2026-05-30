<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint #1 : User Registration
 *  File   : ajax_register.php
 *  Method : POST (application/json  OR  application/x-www-form-urlencoded)
 *  Returns: JSON { status, message, [errors], [user] }
 * ============================================================
 *
 *  Expected POST body fields
 *  ─────────────────────────
 *  first_name       string  required
 *  last_name        string  required
 *  email            string  required  valid e-mail format
 *  password         string  required  ≥ 8 characters
 *  confirm_password string  required  must match password
 *  pronouns         string  optional
 */

declare(strict_types=1);

// ── Emit JSON header immediately ────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── CORS: allow same-origin AJAX only (adjust for prod) ─────
header('X-Content-Type-Options: nosniff');

// ── Reject non-POST requests ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

// ── Decode payload (JSON body or form-encoded) ───────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw, true) ?? [];
} else {
    // application/x-www-form-urlencoded or multipart/form-data
    $input = $_POST;
}

// ── Sanitise helper ─────────────────────────────────────────
function clean(mixed $v): string
{
    // Avoid htmlspecialchars here to prevent double-encoding in DB
    return trim((string)($v ?? ''));
}

// ── Extract & sanitise fields ────────────────────────────────
$firstName       = clean($input['first_name']       ?? '');
$lastName        = clean($input['last_name']        ?? '');
$email           = strtolower(trim($input['email']  ?? ''));
$password        = $input['password']               ?? '';
$confirmPassword = $input['confirm_password']       ?? '';
$pronouns        = clean($input['pronouns']         ?? '');

// ── Validate ─────────────────────────────────────────────────
$errors = [];

if ($firstName === '') {
    $errors['first_name'] = 'First name is required.';
}

if ($lastName === '') {
    $errors['last_name'] = 'Last name is required.';
}

if ($email === '') {
    $errors['email'] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

if ($password === '') {
    $errors['password'] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters.';
}

if ($confirmPassword === '') {
    $errors['confirm_password'] = 'Please confirm your password.';
} elseif ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

// ── Return early if validation failed ────────────────────────
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Validation failed. Please fix the highlighted fields.',
        'errors'  => $errors,
    ]);
    exit;
}

// ── Check for duplicate e-mail ───────────────────────────────
$pdo = ab_pdo();

$stmtCheck = $pdo->prepare(
    "SELECT `user_id` FROM `tbl_users` WHERE `email` = ? LIMIT 1"
);
$stmtCheck->execute([$email]);

if ($stmtCheck->fetchColumn() !== false) {
    http_response_code(409);
    echo json_encode([
        'status'  => 'error',
        'message' => 'An account with that email address already exists.',
        'errors'  => ['email' => 'Email is already registered.'],
    ]);
    exit;
}

// ── Hash password & insert ───────────────────────────────────
try {
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmtInsert = $pdo->prepare(
        "INSERT INTO `tbl_users`
         (`first_name`, `last_name`, `email`, `password_hash`, `pronouns`, `theme_preference`)
         VALUES (?, ?, ?, ?, ?, 'light')"
    );
    $stmtInsert->execute([
        $firstName,
        $lastName,
        $email,
        $passwordHash,
        $pronouns !== '' ? $pronouns : null,
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    // Establish Session
    session_regenerate_id(true);
    $_SESSION['user_id']    = $newUserId;
    $_SESSION['user_email'] = $email;
    $_SESSION['role']       = 'client';

    http_response_code(201);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Account created successfully! Welcome to AnyBuddy.',
        'user'    => [
            'id'         => $newUserId,
            'user_id'    => $newUserId,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $email,
            'pronouns'   => $pronouns !== '' ? $pronouns : null,
        ],
        'redirect' => 'login.html',
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Registration could not be completed. Please try again.',
    ]);
}
