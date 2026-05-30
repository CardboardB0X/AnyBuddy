<?php
/**
 * ============================================================
 *  AnyBuddy — AJAX Endpoint #3 : Marketplace Search & Filter
 *  File   : ajax_marketplace.php
 *  Method : GET
 *  Returns: JSON { status, total, buddies[] }
 * ============================================================
 *
 *  Accepted GET parameters
 *  ──────────────────────────────────────────────────────────
 *  query        string   keyword search (display_name, title, bio, location)
 *  location     string   location keyword filter
 *  category[]   array    one or more: casual|event|security|arts|listener|ally
 *  min-rate     numeric  minimum hourly rate  (₱)
 *  max-rate     numeric  maximum hourly rate  (₱)
 *  min-rating   numeric  minimum average rating (1–5) — NOTE: ratings are
 *                        simulated (see inline comment) until a reviews table
 *                        is added; the filter is still honoured here.
 *  sort         string   recommended | price-low | price-high | rating
 *  page         int      page number (1-based), default 1
 *  per_page     int      results per page,  default 12, max 50
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Only GET ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

// ── Allowed category values ───────────────────────────────────
const ALLOWED_CATEGORIES = ['casual', 'event', 'security', 'arts', 'listener', 'ally'];

// ── Allowed sort modes ────────────────────────────────────────
const SORT_MAP = [
    'recommended' => '`is_verified` DESC, bp.`profile_id` ASC',
    'price-low'   => 'bp.`hourly_rate` ASC',
    'price-high'  => 'bp.`hourly_rate` DESC',
    'rating'      => '`real_avg_rating` DESC, `is_verified` DESC, bp.`profile_id` DESC',
];

// ── Read & normalise GET params ───────────────────────────────
$queryKw    = trim($_GET['query']    ?? '');
$locationKw = trim($_GET['location'] ?? '');

// category[] can arrive as ?category[]=casual&category[]=event
$rawCategories = isset($_GET['category'])
                 ? (array) $_GET['category']
                 : [];

// Sanitise categories against the whitelist
$categories = array_filter(
    array_map('strtolower', $rawCategories),
    fn($c) => in_array($c, ALLOWED_CATEGORIES, true)
);
$categories = array_values($categories);

// Rate range
$minRate = isset($_GET['min-rate']) && is_numeric($_GET['min-rate'])
           ? max(0.0, (float)$_GET['min-rate'])
           : null;

// Max rate
$maxRate = isset($_GET['max-rate']) && is_numeric($_GET['max-rate'])
           ? min(99999.99, (float)$_GET['max-rate'])
           : null;

// Min rating (1–5)
$minRating = isset($_GET['min-rating']) && is_numeric($_GET['min-rating'])
             ? max(1.0, min(5.0, (float)$_GET['min-rating']))
             : null;

// Sort
$sortKey = isset($_GET['sort']) && array_key_exists($_GET['sort'], SORT_MAP)
           ? $_GET['sort']
           : 'recommended';
$orderBy = SORT_MAP[$sortKey];

// Pagination
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = min(50, max(1, (int)($_GET['per_page'] ?? 12)));
$offset  = ($page - 1) * $perPage;

// ── Build dynamic WHERE clause ────────────────────────────────
$conditions = [
    "u.`is_active` = 1",
    "u.`status` = 'active'"
];
$bindings   = [];

// Exclude self from marketplace companion listings
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    $conditions[] = "bp.`user_id` != ?";
    $bindings[]   = (int)$_SESSION['user_id'];
}

// Filter by a specific set of IDs (e.g. for Favourites view)
$idsParam = trim($_GET['ids'] ?? '');
if ($idsParam !== '') {
    $ids = array_filter(array_map('intval', explode(',', $idsParam)), fn($id) => $id > 0);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $conditions[] = "bp.`profile_id` IN ({$placeholders})";
        $bindings     = array_merge($bindings, $ids);
    } else {
        $conditions[] = "1 = 0";
    }
}

// Keyword search across display_name, professional_title, bio, location
if ($queryKw !== '') {
    $conditions[] = "(bp.`display_name` LIKE ?
                   OR bp.`professional_title` LIKE ?
                   OR bp.`bio` LIKE ?
                   OR bp.`location` LIKE ?)";
    $like = '%' . $queryKw . '%';
    array_push($bindings, $like, $like, $like, $like);
}

// Location keyword filter
if ($locationKw !== '') {
    $conditions[] = "bp.`location` LIKE ?";
    $bindings[]   = '%' . $locationKw . '%';
}

// Category filter (multi-select — SQL IN clause with placeholders)
if (!empty($categories)) {
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $conditions[] = "bp.`category` IN ({$placeholders})";
    $bindings     = array_merge($bindings, $categories);
}

// Hourly rate range
if ($minRate !== null) {
    $conditions[] = "bp.`hourly_rate` >= ?";
    $bindings[]   = $minRate;
}
if ($maxRate !== null) {
    $conditions[] = "bp.`hourly_rate` <= ?";
    $bindings[]   = $maxRate;
}

$whereSQL = !empty($conditions)
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';

// ── Count total matching records (for pagination metadata) ────
$pdo = ab_pdo();

$countSQL  = "
    SELECT COUNT(*) 
    FROM `tbl_buddy_profiles` bp 
    INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
    {$whereSQL}
";
$stmtCount = $pdo->prepare($countSQL);
$stmtCount->execute($bindings);
$totalRows = (int) $stmtCount->fetchColumn();

// ── Fetch paginated results ───────────────────────────────────
$dataSQL = "
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
        'Flexible' AS `service_mode`,
        NULL AS `tagline`,
        (
            SELECT GROUP_CONCAT(l.`language_name` SEPARATOR ', ')
            FROM `tbl_buddy_languages` bl
            JOIN `tbl_languages` l ON l.`language_id` = bl.`language_id`
            WHERE bl.`profile_id` = bp.`profile_id`
        ) AS `languages`,
        COALESCE(rev.`cnt`, 0) AS real_review_count,
        COALESCE(rev.`avg_rate`, 0.00) AS real_avg_rating
    FROM `tbl_buddy_profiles` bp
    INNER JOIN `tbl_users` u ON u.`user_id` = bp.`user_id`
    LEFT JOIN `tbl_buddy_verifications` bv ON bv.`profile_id` = bp.`profile_id`
    LEFT JOIN (
        SELECT `buddy_profile_id`, COUNT(*) AS `cnt`, AVG(`rating`) AS `avg_rate`
        FROM `tbl_reviews`
        GROUP BY `buddy_profile_id`
    ) rev ON rev.`buddy_profile_id` = bp.`profile_id`
    {$whereSQL}
    ORDER BY {$orderBy}
    LIMIT {$perPage} OFFSET {$offset}
";

$stmtData = $pdo->prepare($dataSQL);
$stmtData->execute($bindings);
$rows = $stmtData->fetchAll();

// Prepare gallery statement to fetch gallery images for each profile
$stmtGallery = $pdo->prepare("SELECT `image_url` FROM `tbl_buddy_gallery` WHERE `profile_id` = ?");

// ── Enrich each row with database or simulated details ─────
$buddies = [];

foreach ($rows as $row) {
    $stmtGallery->execute([(int)$row['id']]);
    $galleryImages = $stmtGallery->fetchAll(PDO::FETCH_COLUMN);

    // If there are real reviews in the database, use them.
    if ((int)$row['real_review_count'] > 0) {
        $rating = round((float)$row['real_avg_rating'], 1);
        $reviewCount = (int)$row['real_review_count'];
        $totalGigs = ($row['total_gigs'] !== null && (int)$row['total_gigs'] > 0) ? (int)$row['total_gigs'] : $reviewCount;
    } else {
        $rating = 0.0; // Default fallback instead of fake 5.0
        $reviewCount = 0;
        $totalGigs = ($row['total_gigs'] !== null) ? (int)$row['total_gigs'] : 0;
    }

    // Apply min-rating filter in PHP
    if ($minRating !== null && $rating < $minRating) {
        $totalRows--;
        continue;
    }

    // Category display label map
    $categoryLabels = [
        'casual'   => 'Casual Hangout',
        'event'    => 'Event Plus-One',
        'security' => 'Bodyguard/Security',
        'arts'     => 'Visual Arts',
        'listener' => 'Listener',
        'ally'     => 'LGBTQ+ Ally',
    ];

    // Fallback avatar from local anonymous placeholder
    $avatarUrl = $row['avatar_url'] ?: 'images/user-light.png';

    $buddies[] = [
        'id'                 => (int) $row['id'],
        'user_id'            => (int) $row['user_id'],
        'display_name'       => htmlspecialchars($row['display_name'],       ENT_QUOTES, 'UTF-8'),
        'professional_title' => htmlspecialchars($row['professional_title'], ENT_QUOTES, 'UTF-8'),
        'tagline'            => $row['tagline'] ? htmlspecialchars($row['tagline'], ENT_QUOTES, 'UTF-8') : null,
        'category'           => $row['category'],
        'category_label'     => $categoryLabels[$row['category']] ?? ucfirst($row['category']),
        'bio'                => htmlspecialchars(
                                    mb_strimwidth($row['bio'], 0, 160, '…'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ),
        'full_bio'           => htmlspecialchars($row['bio'], ENT_QUOTES, 'UTF-8'),
        'hourly_rate'        => (float) $row['hourly_rate'],
        'hourly_rate_fmt'    => '₱' . number_format((float)$row['hourly_rate'], 0),
        'location'           => htmlspecialchars($row['location'],   ENT_QUOTES, 'UTF-8'),
        'availability'       => htmlspecialchars($row['availability'], ENT_QUOTES, 'UTF-8'),
        'is_verified'        => (bool) $row['is_verified'],
        'avatar_url'         => $avatarUrl,
        'gallery'            => $galleryImages,
        'pronouns'           => $row['pronouns']
                                ? htmlspecialchars($row['pronouns'], ENT_QUOTES, 'UTF-8')
                                : null,
        'rating'             => $rating,
        'review_count'       => $reviewCount,
        'total_gigs'         => $totalGigs,
        'service_mode'       => $row['service_mode'] ?? 'Flexible',
        'response_time'      => $row['response_time'] ? htmlspecialchars($row['response_time'], ENT_QUOTES, 'UTF-8') : 'Flexible',
        'languages'          => $row['languages'] ? htmlspecialchars($row['languages'], ENT_QUOTES, 'UTF-8') : 'English',
        'profile_url'        => 'profile.html?id=' . $row['id'],
    ];

}

// Fetch all unique locations for filters
$stmtLoc = $pdo->query("SELECT DISTINCT `location` FROM `tbl_buddy_profiles` WHERE `location` IS NOT NULL AND `location` != '' ORDER BY `location` ASC");
$locations = $stmtLoc->fetchAll(PDO::FETCH_COLUMN);

// ── Build pagination metadata ─────────────────────────────────
$totalPages = (int) ceil($totalRows / $perPage);

http_response_code(200);
echo json_encode([
    'status'  => 'success',
    'total'   => $totalRows,
    'page'    => $page,
    'per_page' => $perPage,
    'total_pages' => $totalPages,
    'buddies' => $buddies,
    'locations' => $locations,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
