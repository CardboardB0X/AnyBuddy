<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint: Fetch Profile Details
 *  File   : ajax_profile.php
 *  Method : GET
 *  Returns: JSON { status, buddy { ... } }
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing profile ID.']);
    exit;
}

try {
    $pdo = ab_pdo();
    
    // Fetch core profile information and join user
    $sql = "
        SELECT
            bp.`profile_id` AS `id`,
            bp.`user_id`,
            bp.`display_name`,
            bp.`professional_title`,
            bp.`category`,
            bp.`bio`,
            bp.`hourly_rate`,
            bp.`location`,
            bp.`availability`,
            bp.`response_time`,
            bp.`total_gigs`,
            (CASE WHEN bv.`verification_status` = 'verified' THEN 1 ELSE 0 END) AS `is_verified`,
            u.`profile_photo` AS `avatar_url`,
            u.`pronouns`,
            u.`email`,
            u.`status`,
            u.`is_active` AS `user_active`,
            'Flexible' AS `service_mode`,
            NULL AS `tagline`
        FROM `tbl_buddy_profiles` bp
        INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
        LEFT JOIN `tbl_buddy_verifications` bv ON bv.`profile_id` = bp.`profile_id`
        WHERE bp.`profile_id` = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if (!$row || $row['status'] !== 'active' || (int)$row['user_active'] === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'This buddy profile is no longer available.']);
        exit;
    }
    
    // Fetch gallery image URLs
    $stmtGallery = $pdo->prepare("SELECT `image_url` FROM `tbl_buddy_gallery` WHERE `profile_id` = ?");
    $stmtGallery->execute([$id]);
    $gallery = $stmtGallery->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch specialties
    $stmtSpecialties = $pdo->prepare("
        SELECT ts.`specialty_name` AS `name`
        FROM `tbl_buddy_specialties` tbs
        INNER JOIN `tbl_specialties` ts ON ts.`specialty_id` = tbs.`specialty_id`
        WHERE tbs.`profile_id` = ?
    ");
    $stmtSpecialties->execute([$id]);
    $specialties = $stmtSpecialties->fetchAll(PDO::FETCH_COLUMN);
    
    // Fetch languages
    $stmtLanguages = $pdo->prepare("
        SELECT l.`language_name`
        FROM `tbl_buddy_languages` bl
        INNER JOIN `tbl_languages` l ON l.`language_id` = bl.`language_id`
        WHERE bl.`profile_id` = ?
    ");
    $stmtLanguages->execute([$id]);
    $languages = $stmtLanguages->fetchAll(PDO::FETCH_COLUMN);
    if (empty($languages)) {
        $languages = ['English'];
    }

    // Fetch aggregate rating stats from the reviews table
    $statsSql = "
        SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM `tbl_reviews`
        WHERE `buddy_profile_id` = ?
    ";
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute([$id]);
    $stats = $statsStmt->fetch() ?: ['total_reviews' => 0, 'avg_rating' => 0.00];

    $realReviewCount = (int) $stats['total_reviews'];
    $realAvgRating = $stats['avg_rating'] !== null ? round((float)$stats['avg_rating'], 1) : 5.0;

    if ($realReviewCount > 0) {
        $rating = $realAvgRating;
        $reviewCount = $realReviewCount;
        $totalGigs = ($row['total_gigs'] !== null && (int)$row['total_gigs'] > 0) ? (int)$row['total_gigs'] : $realReviewCount;
    } else {
        $rating = 0.0;
        $reviewCount = 0;
        $totalGigs = ($row['total_gigs'] !== null) ? (int)$row['total_gigs'] : 0;
    }

    // Map database category to nice labels
    $categoryLabels = [
        'casual'   => 'Casual Hangout',
        'event'    => 'Event Plus-One',
        'security' => 'Bodyguard/Security',
        'arts'     => 'Visual Arts',
        'listener' => 'Listener',
        'ally'     => 'LGBTQ+ Ally',
    ];
    $categoryLabel = $categoryLabels[$row['category']] ?? ucfirst($row['category']);
    
    // If specialties is empty, use the main category label
    if (empty($specialties)) {
        $specialties = [$categoryLabel];
    }
    
    // Default avatar
    $avatarUrl = $row['avatar_url'];
    if (!$avatarUrl) {
        $avatarUrl = 'images/user-light.png';
    }
    
    // If gallery is empty, seed it with the avatar as fallback
    if (empty($gallery)) {
        $gallery = [$avatarUrl];
    }
    
    // Fetch individual reviews with client names
    $reviewsSql = "
        SELECT 
            r.`id`,
            r.`rating`,
            r.`comment` AS text,
            r.`created_at`,
            u.`first_name`,
            u.`last_name`,
            u.`profile_photo` AS client_avatar
        FROM `tbl_reviews` r
        INNER JOIN `tbl_users` u ON u.`user_id` = r.`client_id`
        WHERE r.`buddy_profile_id` = ?
        ORDER BY r.`created_at` DESC
    ";
    $reviewsStmt = $pdo->prepare($reviewsSql);
    $reviewsStmt->execute([$id]);
    $realReviews = $reviewsStmt->fetchAll();
    
    // Convert keys to match what JS expects
    $formattedReviews = [];
    foreach ($realReviews as $rev) {
        $revAvatar = $rev['client_avatar'] ?: 'images/user-light.png';
        $formattedReviews[] = [
            'reviewer_name' => $rev['first_name'] . ' ' . $rev['last_name'],
            'avatar_url' => $revAvatar,
            'rating' => (int) $rev['rating'],
            'text' => $rev['text']
        ];
    }

    $buddy = [
        'id'                 => (int) $row['id'],
        'user_id'            => (int) $row['user_id'],
        'display_name'       => $row['display_name'],
        'professional_title' => $row['professional_title'],
        'tagline'            => $row['tagline'],
        'category'           => $row['category'],
        'category_label'     => $categoryLabel,
        'bio'                => $row['bio'],
        'hourly_rate'        => (float) $row['hourly_rate'],
        'hourly_rate_fmt'    => '₱' . number_format((float)$row['hourly_rate'], 0),
        'location'           => $row['location'],
        'availability'       => $row['availability'],
        'is_verified'        => (bool) $row['is_verified'],
        'avatar_url'         => $avatarUrl,
        'pronouns'           => $row['pronouns'],
        'email'              => $row['email'],
        'rating'             => $rating,
        'review_count'       => $reviewCount,
        'total_gigs'         => $totalGigs,
        'service_mode'       => $row['service_mode'] ?? 'Flexible',
        'response_time'      => $row['response_time'] ?: 'Flexible',
        'languages'          => $languages,
        'gallery'            => $gallery,
        'specialties'        => $specialties,
        'reviews'            => $formattedReviews
    ];
    
    echo json_encode([
        'status' => 'success',
        'buddy'  => $buddy
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error.',
        'detail'  => (defined('AB_DEBUG') && AB_DEBUG) ? $e->getMessage() : null
    ]);
}
