<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Submit User Report
 *  File   : ajax_report.php
 *  Method : POST (application/json  OR  application/x-www-form-urlencoded)
 *  Returns: JSON { status, message, [errors] }
 * ============================================================
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

try {
    $pdo = ab_pdo();

    // ── Decode payload ───────────────────────────────────────────
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $raw   = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    } else {
        $input = $_POST;
    }

    $reporterId  = ab_require_auth();
    $reportedId  = (int) ($input['reported_id'] ?? 0);
    $reason      = trim($input['reason'] ?? '');
    $description = trim($input['description'] ?? '');

    $errors = [];

    // ── Validate reporter ────────────────────────────────────────
    // reporter_id checked by session

    // ── Validate reported user ───────────────────────────────────
    if ($reportedId <= 0) {
        $errors['reported_id'] = 'Invalid reported user.';
    } elseif ($reporterId === $reportedId) {
        $errors['reported_id'] = 'You cannot report yourself.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `user_id` = ?");
        $stmt->execute([$reportedId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors['reported_id'] = 'Reported user does not exist.';
        }
    }

    // ── Validate reason & description ────────────────────────────
    if ($reason === '') {
        $errors['reason'] = 'Please select a reason for reporting.';
    }

    if ($description === '') {
        $errors['description'] = 'Please provide a detailed description.';
    } elseif (strlen($description) < 10) {
        $errors['description'] = 'Please provide a description of at least 10 characters.';
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

    // ── Insert report into tbl_reports ───────────────────────────
    $sql = "
        INSERT INTO `tbl_reports` 
            (`reporter_id`, `reported_id`, `reason`, `description`, `status`, `created_at`) 
        VALUES 
            (?, ?, ?, ?, 'pending', NOW())
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $reporterId,
        $reportedId,
        $reason,
        $description
    ]);

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Thank you. Your report has been submitted successfully and will be reviewed by our team.'
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred on the server while submitting the report.',
        'detail' => (defined('DB_DEBUG') && DB_DEBUG) ? $e->getMessage() : null
    ]);
    exit;
}
