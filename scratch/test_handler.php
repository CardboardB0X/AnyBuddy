<?php
/**
 * AnyBuddy — Suspension Test Handler Helper
 * File: scratch/test_handler.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../db_connect.php';

$action = $_GET['action'] ?? '';

if ($action === 'set_session') {
    $userId = (int)($_GET['user_id'] ?? 0);
    $role = $_GET['role'] ?? 'client';
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    echo json_encode(['status' => 'success', 'session' => $_SESSION]);
    exit;
}

if ($action === 'clear_session') {
    session_unset();
    session_destroy();
    echo json_encode(['status' => 'success', 'message' => 'Session cleared']);
    exit;
}

if ($action === 'test_auth') {
    // This will call ab_require_auth() and exit with 401/403/503 if unauthorized,
    // or return the user ID if authorized.
    $userId = ab_require_auth();
    echo json_encode(['status' => 'success', 'user_id' => $userId]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
