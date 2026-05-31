<?php
/**
 * ============================================================
 *  AnyBuddy — Shared PDO Database Connection Helper
 *  File   : db_connect.php
 *  Usage  : require_once __DIR__ . '/db_connect.php';
 *           // $pdo is now available
 * ============================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST',    getenv('DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME') ?: 'anybuddy_db');
define('DB_USER',    getenv('DB_USER') ?: 'root');
define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');
define('DB_DEBUG',   false);

/**
 * Returns a singleton PDO connection.
 * Emits a 500 JSON error and exits if the connection fails.
 */
function ab_pdo(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Ensure status and status_reason columns exist on tbl_users
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'tbl_users'")->fetch();
                if ($checkTable !== false) {
                    $checkColumn = $pdo->query("SHOW COLUMNS FROM `tbl_users` LIKE 'status'")->fetch();
                    if ($checkColumn === false) {
                        $pdo->exec("ALTER TABLE `tbl_users` ADD `status` ENUM('active','suspended','banned') NOT NULL DEFAULT 'active' AFTER `is_active`");
                        $pdo->exec("ALTER TABLE `tbl_users` ADD `status_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `status`");
                    }
                }

                // Ensure tbl_system_settings exists and is seeded
                $checkSettingsTable = $pdo->query("SHOW TABLES LIKE 'tbl_system_settings'")->fetch();
                if ($checkSettingsTable === false) {
                    $pdo->exec("
                        CREATE TABLE `tbl_system_settings` (
                            `key_name` VARCHAR(50) PRIMARY KEY,
                            `key_value` VARCHAR(255) NOT NULL,
                            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                    $pdo->exec("
                        INSERT INTO `tbl_system_settings` (`key_name`, `key_value`) VALUES
                        ('commission_rate', '10'),
                        ('service_fee', '50'),
                        ('maintenance_mode', '0');
                    ");
                }

                // Ensure community tables exist
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `tbl_community_posts` (
                        `post_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id`    INT UNSIGNED NOT NULL,
                        `category`   ENUM('General', 'Booking Tips', 'Social Hangout', 'Safety Alert') NOT NULL DEFAULT 'General',
                        `content`    TEXT NOT NULL,
                        `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
                        `status`     ENUM('active', 'flagged', 'hidden') NOT NULL DEFAULT 'active',
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`post_id`),
                        CONSTRAINT `fk_posts_user_mig` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `tbl_post_likes` (
                        `post_id` INT UNSIGNED NOT NULL,
                        `user_id` INT UNSIGNED NOT NULL,
                        PRIMARY KEY (`post_id`, `user_id`),
                        CONSTRAINT `fk_likes_post_mig` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
                        CONSTRAINT `fk_likes_user_mig` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `tbl_post_comments` (
                        `comment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `post_id`    INT UNSIGNED NOT NULL,
                        `user_id`    INT UNSIGNED NOT NULL,
                        `content`    TEXT NOT NULL,
                        `status`     ENUM('active', 'flagged', 'hidden') NOT NULL DEFAULT 'active',
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`comment_id`),
                        CONSTRAINT `fk_comments_post_mig` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
                        CONSTRAINT `fk_comments_user_mig` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `tbl_community_reports` (
                        `report_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `post_id`     INT UNSIGNED NULL DEFAULT NULL,
                        `comment_id`  INT UNSIGNED NULL DEFAULT NULL,
                        `reporter_id` INT UNSIGNED NOT NULL,
                        `reason`      VARCHAR(100) NOT NULL,
                        `details`     TEXT NOT NULL,
                        `status`      ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
                        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`report_id`),
                        CONSTRAINT `fk_rep_post_mig` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
                        CONSTRAINT `fk_rep_comment_mig` FOREIGN KEY (`comment_id`) REFERENCES `tbl_post_comments` (`comment_id`) ON DELETE CASCADE,
                        CONSTRAINT `fk_rep_user_mig` FOREIGN KEY (`reporter_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            } catch (Exception $migEx) {
                // Silently ignore during bootstrap / initialization
            }
        } catch (PDOException $e) {
            // Must output JSON since every endpoint expects it
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'status'  => 'error',
                'message' => 'Database connection failed.',
                // Only expose detail in a non-production environment
                'detail'  => (defined('DB_DEBUG') && DB_DEBUG)
                             ? $e->getMessage()
                             : null,
            ]);
            exit;
        }
    }

    return $pdo;
}

/**
 * Helper to add notifications for a user.
 */
function ab_add_notification(PDO $pdo, int $userId, string $title, string $message, ?string $link = null): bool
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `tbl_notifications` (`user_id`, `title`, `message`, `link`, `is_read`, `created_at`)
            VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
        ");
        return $stmt->execute([$userId, $title, $message, $link]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper to add audit logs.
 */
function ab_add_audit_log(PDO $pdo, ?int $performedBy, string $action, string $entityType, int $entityId, string $details): bool
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `tbl_audit_logs` (`performed_by`, `action`, `entity_type`, `entity_id`, `details`, `created_at`)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        return $stmt->execute([$performedBy, $action, $entityType, $entityId, $details]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Require user authentication. Returns user_id or exits with 401.
 */
function ab_require_auth(): int
{
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in.']);
        exit;
    }
    
    $userId = (int) $_SESSION['user_id'];
    $pdo = ab_pdo();

    // Query database for the user's status and active flag
    try {
        $stmt = $pdo->prepare("SELECT `status`, `is_active` FROM `tbl_users` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            // User does not exist, clean up session
            session_unset();
            session_destroy();
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Your account no longer exists.']);
            exit;
        }

        if ($user['status'] === 'suspended') {
            session_unset();
            session_destroy();
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Your account has been suspended by an administrator due to safety or policy violations.']);
            exit;
        }

        if ($user['status'] === 'banned') {
            session_unset();
            session_destroy();
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Your account has been permanently banned due to safety or policy violations.']);
            exit;
        }

        if ((int)$user['is_active'] === 0) {
            session_unset();
            session_destroy();
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Your account is currently disabled or inactive.']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Database error verifying session.']);
        exit;
    }
    
    // Check maintenance mode (admins can bypass)
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        $settings = ab_get_system_settings($pdo);
        if (!empty($settings['maintenance_mode']) && $settings['maintenance_mode'] == '1') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'System is currently undergoing maintenance. Please try again later.']);
            exit;
        }
    }
    
    return $userId;
}

/**
 * Fetch all system settings as key-value pairs
 */
function ab_get_system_settings(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT `key_name`, `key_value` FROM `tbl_system_settings`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key_name']] = $row['key_value'];
    }
    return $settings;
}

/**
 * Require admin role. Returns user_id or exits with 403.
 */
function ab_require_admin(): int
{
    $userId = ab_require_auth();
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden. Admin access required.']);
        exit;
    }
    return $userId;
}

// ── Database Schema Creation & Seeding Routine ───────────────────────

/**
 * Unifies 3NF Database Migration / Initialization.
 * Seeds reference data and demo profiles.
 */
function ab_init_database(PDO $pdo, bool $echoHtml = false): void
{
    $step = function(string $label, bool $ok, string $detail = '') use ($echoHtml) {
        $icon   = $ok ? '✅' : '❌';
        $colour = $ok ? 'green' : 'red';
        if ($echoHtml) {
            echo "<p style=\"font-family:monospace;color:{$colour}\">{$icon} {$label}"
                . ($detail ? " — <small>{$detail}</small>" : '')
                . "</p>\n";
        } else {
            echo "{$icon} {$label}" . ($detail ? " — {$detail}" : '') . "\n";
        }
    };

    if ($echoHtml) {
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'>"
           . "<title>AnyBuddy 3NF DB Init</title></head><body>"
           . "<h2 style='font-family:sans-serif'>AnyBuddy — 3NF Database Initialization</h2>\n";
    }

    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        $step('Database `' . DB_NAME . '` ready', true);
    } catch (PDOException $e) {
        $step('Create database failed', false, $e->getMessage());
        if ($echoHtml) echo "</body></html>";
        exit(1);
    }

    // ── Drop old tables if they exist ─────────────────────────────
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $tablesToDrop = [
        'users', 'buddy_profiles', 'bookings', 'messages', 'reviews',
        'tbl_users', 'tbl_buddy_profiles', 'tbl_buddy_gallery', 'tbl_buddy_specialties',
        'tbl_buddy_languages', 'tbl_buddy_verifications', 'tbl_booking_statuses',
        'tbl_specialties', 'tbl_languages', 'tbl_notifications', 'tbl_reports',
        'tbl_buddy_availability', 'tbl_emergency_contacts', 'tbl_bookings',
        'tbl_messages', 'tbl_reviews', 'tbl_user_tiers', 'tbl_vouchers', 'tbl_payment_methods',
        'tbl_audit_logs', 'tbl_system_settings',
        'tbl_community_reports', 'tbl_post_comments', 'tbl_post_likes', 'tbl_community_posts'
    ];
    foreach ($tablesToDrop as $tbl) {
        $pdo->exec("DROP TABLE IF EXISTS `$tbl` CASCADE");
    }
    $step('Dropped legacy and conflict tables (foreign key checks disabled during reset)', true);

    // ── Create tables in 3NF ──────────────────────────────────────

    // 1. tbl_users
    $sqlUsers = "
    CREATE TABLE `tbl_users` (
        `user_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `first_name`       VARCHAR(80) NOT NULL,
        `last_name`        VARCHAR(80) NOT NULL,
        `email`            VARCHAR(255) NOT NULL,
        `password_hash`    VARCHAR(255) NOT NULL,
        `pronouns`         VARCHAR(50) NULL DEFAULT NULL,
        `profile_photo`    VARCHAR(500) NULL DEFAULT NULL,
        `role`             ENUM('client','buddy','admin') NOT NULL DEFAULT 'client',
        `theme_preference` ENUM('light','dark') NOT NULL DEFAULT 'light',
        `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
        `status`           ENUM('active','suspended','banned') NOT NULL DEFAULT 'active',
        `status_reason`    VARCHAR(255) NULL DEFAULT NULL,
        `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`),
        UNIQUE KEY `uq_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlUsers);
    $step('Table `tbl_users` created', true);

    // 2. tbl_emergency_contacts
    $sqlContacts = "
    CREATE TABLE `tbl_emergency_contacts` (
        `user_id`          INT UNSIGNED NOT NULL,
        `contact_name`     VARCHAR(150) NOT NULL,
        `contact_email`    VARCHAR(255) NOT NULL,
        `contact_phone`    VARCHAR(50) NOT NULL,
        PRIMARY KEY (`user_id`),
        CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlContacts);
    $step('Table `tbl_emergency_contacts` created', true);

    // 2b. tbl_payment_methods
    $sqlPayments = "
    CREATE TABLE `tbl_payment_methods` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`         INT UNSIGNED NOT NULL,
        `cardholder_name` VARCHAR(150) NOT NULL,
        `card_number`     VARCHAR(50) NOT NULL,
        `expiry_date`     VARCHAR(10) NOT NULL,
        `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_payment_method_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlPayments);
    $step('Table `tbl_payment_methods` created', true);

    // 3. tbl_booking_statuses
    $sqlStatus = "
    CREATE TABLE `tbl_booking_statuses` (
        `status_id`    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `status_name`  VARCHAR(50) NOT NULL,
        `status_color` VARCHAR(7) NOT NULL DEFAULT '#888888',
        `status_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`status_id`),
        UNIQUE KEY `uq_status_name` (`status_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlStatus);
    $step('Table `tbl_booking_statuses` created', true);

    // 4. tbl_specialties
    $sqlSpecialties = "
    CREATE TABLE `tbl_specialties` (
        `specialty_id`   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `specialty_name` VARCHAR(100) NOT NULL,
        `specialty_icon` VARCHAR(10) NOT NULL DEFAULT '',
        PRIMARY KEY (`specialty_id`),
        UNIQUE KEY `uq_specialty_name` (`specialty_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlSpecialties);
    $step('Table `tbl_specialties` created', true);

    // 5. tbl_languages
    $sqlLanguages = "
    CREATE TABLE `tbl_languages` (
        `language_id`   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `language_name` VARCHAR(100) NOT NULL,
        PRIMARY KEY (`language_id`),
        UNIQUE KEY `uq_language_name` (`language_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlLanguages);
    $step('Table `tbl_languages` created', true);

    // 5b. tbl_user_tiers
    $sqlTiers = "
    CREATE TABLE `tbl_user_tiers` (
        `tier_id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `tier_name`            VARCHAR(50) NOT NULL,
        `min_bookings`         INT UNSIGNED NOT NULL DEFAULT 0,
        `platform_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
        `discount_percent`     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`tier_id`),
        UNIQUE KEY `uq_tier_name` (`tier_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlTiers);
    $step('Table `tbl_user_tiers` created', true);

    // 5c. tbl_vouchers
    $sqlVouchers = "
    CREATE TABLE `tbl_vouchers` (
        `voucher_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code`            VARCHAR(50) NOT NULL,
        `discount_type`   ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
        `discount_value`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `min_spend`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
        `expiration_date` DATE NULL DEFAULT NULL,
        PRIMARY KEY (`voucher_id`),
        UNIQUE KEY `uq_voucher_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlVouchers);
    $step('Table `tbl_vouchers` created', true);

    // 6. tbl_buddy_profiles
    $sqlBuddyProfiles = "
    CREATE TABLE `tbl_buddy_profiles` (
        `profile_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`            INT UNSIGNED NOT NULL,
        `display_name`       VARCHAR(120) NOT NULL,
        `professional_title` VARCHAR(150) NOT NULL,
        `category`           ENUM('casual','event','security','arts','listener','ally') NOT NULL,
        `bio`                TEXT NOT NULL,
        `hourly_rate`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `location`           VARCHAR(150) NOT NULL,
        `availability`       VARCHAR(255) NOT NULL,
        `response_time`      VARCHAR(50) NOT NULL DEFAULT 'Within 1 hour',
        `total_gigs`         INT UNSIGNED NOT NULL DEFAULT 0,
        `is_available`       TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`profile_id`),
        UNIQUE KEY `uq_buddy_user` (`user_id`),
        CONSTRAINT `fk_buddy_profile_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlBuddyProfiles);
    $step('Table `tbl_buddy_profiles` created', true);

    // 7. tbl_buddy_gallery
    $sqlGallery = "
    CREATE TABLE `tbl_buddy_gallery` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `profile_id` INT UNSIGNED NOT NULL,
        `image_url`  VARCHAR(500) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_gallery_profile` FOREIGN KEY (`profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlGallery);
    $step('Table `tbl_buddy_gallery` created', true);

    // 8. tbl_buddy_specialties
    $sqlBuddySpecialties = "
    CREATE TABLE `tbl_buddy_specialties` (
        `profile_id`   INT UNSIGNED NOT NULL,
        `specialty_id` SMALLINT UNSIGNED NOT NULL,
        PRIMARY KEY (`profile_id`, `specialty_id`),
        CONSTRAINT `fk_bsp_profile` FOREIGN KEY (`profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_bsp_specialty` FOREIGN KEY (`specialty_id`) REFERENCES `tbl_specialties` (`specialty_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlBuddySpecialties);
    $step('Table `tbl_buddy_specialties` created', true);

    // 9. tbl_buddy_languages
    $sqlBuddyLanguages = "
    CREATE TABLE `tbl_buddy_languages` (
        `profile_id`  INT UNSIGNED NOT NULL,
        `language_id` SMALLINT UNSIGNED NOT NULL,
        PRIMARY KEY (`profile_id`, `language_id`),
        CONSTRAINT `fk_bl_profile` FOREIGN KEY (`profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_bl_language` FOREIGN KEY (`language_id`) REFERENCES `tbl_languages` (`language_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlBuddyLanguages);
    $step('Table `tbl_buddy_languages` created', true);

    // 10. tbl_buddy_verifications
    $sqlVerifications = "
    CREATE TABLE `tbl_buddy_verifications` (
        `profile_id`          INT UNSIGNED NOT NULL,
        `verification_type`   ENUM('student','professional','id') NOT NULL DEFAULT 'id',
        `id_photo_url`        VARCHAR(500) NULL DEFAULT NULL,
        `verification_status` ENUM('none','pending','verified') NOT NULL DEFAULT 'none',
        PRIMARY KEY (`profile_id`),
        CONSTRAINT `fk_verif_profile` FOREIGN KEY (`profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlVerifications);
    $step('Table `tbl_buddy_verifications` created', true);

    // 11. tbl_bookings
    $sqlBookings = "
    CREATE TABLE `tbl_bookings` (
        `booking_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `client_id`        INT UNSIGNED NOT NULL,
        `buddy_profile_id` INT UNSIGNED NOT NULL,
        `status_id`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `booking_date`     DATE NOT NULL,
        `start_time`       TIME NOT NULL,
        `hours_duration`   DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        `base_price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `discount_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `platform_fee`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `total_price`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `voucher_id`       INT UNSIGNED NULL DEFAULT NULL,
        `payment_method`   ENUM('Card', 'Cash On Hand') NOT NULL DEFAULT 'Card',
        `message`          TEXT NULL DEFAULT NULL,
        `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`booking_id`),
        CONSTRAINT `fk_bookings_client` FOREIGN KEY (`client_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_bookings_profile` FOREIGN KEY (`buddy_profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_bookings_status` FOREIGN KEY (`status_id`) REFERENCES `tbl_booking_statuses` (`status_id`) ON DELETE RESTRICT,
        CONSTRAINT `fk_bookings_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `tbl_vouchers` (`voucher_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlBookings);
    $step('Table `tbl_bookings` created', true);

    // 12. tbl_reviews
    $sqlReviews = "
    CREATE TABLE `tbl_reviews` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `booking_id`       INT UNSIGNED NOT NULL,
        `client_id`        INT UNSIGNED NOT NULL,
        `buddy_profile_id` INT UNSIGNED NOT NULL,
        `rating`           TINYINT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
        `comment`          TEXT NULL DEFAULT NULL,
        `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_booking_review` (`booking_id`),
        CONSTRAINT `fk_reviews_booking` FOREIGN KEY (`booking_id`) REFERENCES `tbl_bookings` (`booking_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reviews_profile` FOREIGN KEY (`buddy_profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlReviews);
    $step('Table `tbl_reviews` created', true);

    // 13. tbl_messages
    $sqlMessages = "
    CREATE TABLE `tbl_messages` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `booking_id`   INT UNSIGNED NOT NULL,
        `sender_id`    INT UNSIGNED NOT NULL,
        `message_text` TEXT NOT NULL,
        `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_messages_booking` FOREIGN KEY (`booking_id`) REFERENCES `tbl_bookings` (`booking_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlMessages);
    $step('Table `tbl_messages` created', true);

    // 14. tbl_notifications
    $sqlNotifications = "
    CREATE TABLE `tbl_notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `title`      VARCHAR(150) NOT NULL,
        `message`    TEXT NOT NULL,
        `link`       VARCHAR(255) NULL DEFAULT NULL,
        `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlNotifications);
    $step('Table `tbl_notifications` created', true);

    // 15. tbl_reports
    $sqlReports = "
    CREATE TABLE `tbl_reports` (
        `report_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `reporter_id` INT UNSIGNED NOT NULL,
        `reported_id` INT UNSIGNED NOT NULL,
        `reason`      VARCHAR(100) NOT NULL,
        `description` TEXT NOT NULL,
        `status`      ENUM('pending','reviewed','resolved') NOT NULL DEFAULT 'pending',
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`report_id`),
        CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reports_reported` FOREIGN KEY (`reported_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlReports);
    $step('Table `tbl_reports` created', true);

    // 16. tbl_buddy_availability
    $sqlAvail = "
    CREATE TABLE `tbl_buddy_availability` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `buddy_profile_id` INT UNSIGNED NOT NULL,
        `available_date`   DATE NOT NULL,
        `start_time`       TIME NOT NULL,
        `end_time`         TIME NOT NULL,
        `is_booked`        TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_avail_profile` FOREIGN KEY (`buddy_profile_id`) REFERENCES `tbl_buddy_profiles` (`profile_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlAvail);
    $step('Table `tbl_buddy_availability` created', true);

    // 17. tbl_audit_logs
    $sqlAuditLogs = "
    CREATE TABLE `tbl_audit_logs` (
        `log_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `performed_by`    INT UNSIGNED NULL DEFAULT NULL,
        `action`          VARCHAR(50) NOT NULL,
        `entity_type`     VARCHAR(50) NOT NULL,
        `entity_id`       INT UNSIGNED NOT NULL,
        `details`         TEXT NOT NULL,
        `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`performed_by`) REFERENCES `tbl_users` (`user_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlAuditLogs);
    $step('Table `tbl_audit_logs` created', true);

    // 18. tbl_system_settings
    $sqlSystemSettings = "
    CREATE TABLE `tbl_system_settings` (
        `key_name`   VARCHAR(50) NOT NULL,
        `key_value`  VARCHAR(255) NOT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`key_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlSystemSettings);
    $step('Table `tbl_system_settings` created', true);

    // 19. tbl_community_posts
    $sqlCommunityPosts = "
    CREATE TABLE `tbl_community_posts` (
        `post_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `category`   ENUM('General', 'Booking Tips', 'Social Hangout', 'Safety Alert') NOT NULL DEFAULT 'General',
        `content`    TEXT NOT NULL,
        `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
        `status`     ENUM('active', 'flagged', 'hidden') NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`post_id`),
        CONSTRAINT `fk_posts_user_init` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlCommunityPosts);
    $step('Table `tbl_community_posts` created', true);

    // 20. tbl_post_likes
    $sqlPostLikes = "
    CREATE TABLE `tbl_post_likes` (
        `post_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`post_id`, `user_id`),
        CONSTRAINT `fk_likes_post_init` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_likes_user_init` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlPostLikes);
    $step('Table `tbl_post_likes` created', true);

    // 21. tbl_post_comments
    $sqlPostComments = "
    CREATE TABLE `tbl_post_comments` (
        `comment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `post_id`    INT UNSIGNED NOT NULL,
        `user_id`    INT UNSIGNED NOT NULL,
        `content`    TEXT NOT NULL,
        `status`     ENUM('active', 'flagged', 'hidden') NOT NULL DEFAULT 'active',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`comment_id`),
        CONSTRAINT `fk_comments_post_init` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_comments_user_init` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlPostComments);
    $step('Table `tbl_post_comments` created', true);

    // 22. tbl_community_reports
    $sqlCommunityReports = "
    CREATE TABLE `tbl_community_reports` (
        `report_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `post_id`     INT UNSIGNED NULL DEFAULT NULL,
        `comment_id`  INT UNSIGNED NULL DEFAULT NULL,
        `reporter_id` INT UNSIGNED NOT NULL,
        `reason`      VARCHAR(100) NOT NULL,
        `details`     TEXT NOT NULL,
        `status`      ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`report_id`),
        CONSTRAINT `fk_rep_post_init` FOREIGN KEY (`post_id`) REFERENCES `tbl_community_posts` (`post_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_rep_comment_init` FOREIGN KEY (`comment_id`) REFERENCES `tbl_post_comments` (`comment_id`) ON DELETE CASCADE,
        CONSTRAINT `fk_rep_user_init` FOREIGN KEY (`reporter_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlCommunityReports);
    $step('Table `tbl_community_reports` created', true);

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // ── Seed Reference Data ───────────────────────────────────────

    // Seed Booking Statuses
    $pdo->exec("
    INSERT INTO `tbl_booking_statuses` (`status_name`, `status_color`, `status_order`) VALUES
    ('Requested',    '#FFA500', 1),
    ('Accepted',     '#00d2ff', 2),
    ('Verification', '#fe6fbe', 3),
    ('Completed',    '#4CAF50', 4),
    ('Declined',     '#F44336', 5);
    ");
    $step('Seeded `tbl_booking_statuses`', true);

    // Seed Specialties
    $pdo->exec("
    INSERT INTO `tbl_specialties` (`specialty_id`, `specialty_name`, `specialty_icon`) VALUES
    (1, 'Intimidation / Muscle',   '🥊'),
    (2, 'Handyman / Construction', '🔨'),
    (3, 'Musical Arts',            '🎹'),
    (4, 'Digital Arts / Design',   '🎨'),
    (5, 'Acting / Roleplay',       '🎭'),
    (6, 'Tech Support / Hardware', '💻'),
    (7, 'Endurance / Pila-Sitter', '⏳'),
    (8, 'Entertainment / Dance',   '🎤'),
    (9, 'Gaming Carry',            '🎮');
    ");
    $step('Seeded `tbl_specialties`', true);

    // Seed Languages
    $pdo->exec("
    INSERT INTO `tbl_languages` (`language_name`) VALUES
    ('Filipino'),
    ('English'),
    ('Bisaya'),
    ('Spanish'),
    ('Japanese');
    ");
    $step('Seeded `tbl_languages`', true);

    // Seed Tiers
    $pdo->exec("
    INSERT INTO `tbl_user_tiers` (`tier_name`, `min_bookings`, `platform_fee_percent`, `discount_percent`) VALUES
    ('Bronze',   0,  5.00, 0.00),
    ('Silver',   3,  4.00, 2.00),
    ('Gold',     8,  3.00, 5.00),
    ('Platinum', 15, 1.50, 8.00);
    ");
    $step('Seeded `tbl_user_tiers`', true);

    // Seed Vouchers
    $pdo->exec("
    INSERT INTO `tbl_vouchers` (`code`, `discount_type`, `discount_value`, `min_spend`, `is_active`, `expiration_date`) VALUES
    ('WELCOME50', 'fixed',      50.00, 100.00, 1, NULL),
    ('BUDDY10',   'percentage', 10.00, 0.00,   1, NULL),
    ('SAVE20',    'percentage', 20.00, 200.00, 1, NULL);
    ");
    $step('Seeded `tbl_vouchers`', true);

    // Seed System Settings
    $pdo->exec("
    INSERT INTO `tbl_system_settings` (`key_name`, `key_value`) VALUES
    ('commission_rate', '10'),
    ('service_fee', '50'),
    ('maintenance_mode', '0');
    ");
    $step('Seeded `tbl_system_settings`', true);

    // ── Seed Demo Accounts (Password hash for 'password123') ──────
    $pwdHash = '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2';

    $seedUsers = [
        [3, 'Angelo', 'Maduro', 'angelo@anybuddy.ph', 'He/Him', 'images/Angelo_Maduro.jpg', 'buddy'],
        [4, 'Emmanuel', 'Creo', 'emmanuel@anybuddy.ph', 'He/Him', 'images/Emmanuel_Tristen.jpg', 'buddy'],
        [5, 'Liah Faith', 'Espineli', 'liah@anybuddy.ph', 'She/Her', 'images/Liah_Faith.jpg', 'buddy'],
        [6, 'Von Arvin', 'Apilado', 'von@anybuddy.ph', 'He/Him', 'images/buddies/von_arvin_1.jpg', 'buddy'],
        [7, 'Neil Andrei', 'Toledo', 'neil@anybuddy.ph', 'He/Him', 'images/buddies/neil_andrei_1.png', 'buddy'],
        [8, 'Toper', 'Claveria', 'toper@anybuddy.ph', 'He/Him', 'images/toper1.png', 'buddy'],
        [9, 'Julius', 'Rodil', 'julius@anybuddy.ph', 'He/Him', 'images/buddies/julius_rodil_1.jpg', 'buddy'],
        [10, 'Dominic', 'Berdonar', 'dominic@anybuddy.ph', 'He/Him', 'images/buddies/dominic_berdonar_1.jpg', 'buddy'],
        [11, 'Zachary Owen', 'Marayag', 'zachary@anybuddy.ph', 'He/Him', 'images/Zachary_Owen.jpg', 'buddy'],
        [12, 'Excell', 'Viray', 'excell@anybuddy.ph', 'He/Him', 'images/buddies/excell_viray_1.jpeg', 'buddy'],
        [13, 'John', 'Doe', 'client@anybuddy.ph', 'He/Him', 'images/user-light.png', 'client'],
        [14, 'Admin', 'AnyBuddy', 'admin@anybuddy.ph', 'They/Them', 'images/AnyBuddy LOGO.png', 'admin']
    ];

    $stmtUser = $pdo->prepare("
        INSERT INTO `tbl_users` (`user_id`, `first_name`, `last_name`, `email`, `password_hash`, `pronouns`, `profile_photo`, `role`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($seedUsers as $u) {
        $stmtUser->execute([
            $u[0],
            $u[1],
            $u[2],
            $u[3],
            $pwdHash,
            $u[4],
            $u[5],
            $u[6]
        ]);
    }
    $step('Seeded `tbl_users` table with 10 buddies, 1 client, and 1 admin', true);

    // Seed Buddy Profiles
    $buddySeeds = [
        [1, 3, 'Angelo Maduro', 'Personal Bodyguard & Intimidation Specialist', 'security', 'Need muscle? Intimidation? I am the Macho Man. I will stand behind you menacingly and flex to solve your problems.', 400.00, 'Tanza', 'Mon-Sun, 6AM-10PM', 25],
        [2, 4, 'Emmanuel Creo', 'Construction Worker & Carpenter', 'casual', 'Construction worker and carpenter. I can fix your roof, build a cabinet, or mix cement faster than anyone.', 350.00, 'Amadeo', 'Mon-Sat, 7AM-5PM', 42],
        [3, 5, 'Liah Faith Espineli', 'Professional Pianist & Music Instructor', 'arts', 'Professional piano player. I can perform at your events, teach you the basics, or accompany your singing.', 500.00, 'Maragondon', 'Mon-Fri, 10AM-8PM', 18],
        [4, 6, 'Von Arvin Apilado', 'Multimedia Designer & UI/UX Specialist', 'arts', 'Multimedia Designer. I specialize in video editing, motion graphics, and UI/UX design. Let us build something beautiful.', 450.00, 'Remote', 'Mon-Fri, 9AM-6PM', 55],
        [5, 7, 'Neil Andrei Toledo', '3D Artist & Brand Identity Designer', 'arts', 'Multimedia Designer. I handle complex 3D rendering, branding, and comprehensive graphic design solutions.', 450.00, 'Remote', 'Mon-Fri, 9AM-6PM', 30],
        [6, 8, 'Toper Claveria', 'Actor & Social Roleplay Specialist', 'event', 'Need someone to pretend to be your boyfriend to make your ex jealous? Need a dramatic reading? I am your guy.', 600.00, 'Silang', 'Weekends, 12PM-10PM', 12],
        [7, 9, 'Julius Rodil', 'PC Hardware Technician & Repair Specialist', 'casual', 'Hardware fixer. Is your PC blue-screening? Laptop overheating? I will diagnose and repair your rig.', 300.00, 'Trece', 'Mon-Sun, 8AM-11PM', 88],
        [8, 10, 'Dominic Berdonar', 'Queue & Errand Proxy Specialist', 'casual', 'Manggahan Bystander. I will stand in line for you at government offices, watch your stuff, or just hang around.', 150.00, 'Manggahan', 'Mon-Sun, 24/7', 120],
        [9, 11, 'Zachary Owen Marayag', 'Dancer, Singer & Party Entertainer', 'event', 'Dancer and Singer. Available for flash mobs, serenade services, and party entertainment.', 380.00, 'General Trias', 'Fri-Sun, 5PM-12AM', 22],
        [10, 12, 'Excell Viray', 'Professional Gaming Pilot & Account Booster', 'casual', 'Gaming Pilot. Stuck on the Abyss in Genshin Impact? Cant beat holograms in Wuthering Waves? I will pilot your account and clear it.', 250.00, 'Remote', 'Mon-Sun, 12PM-4AM', 300]
    ];

    $stmtBuddy = $pdo->prepare("
        INSERT INTO `tbl_buddy_profiles` (`profile_id`, `user_id`, `display_name`, `professional_title`, `category`, `bio`, `hourly_rate`, `location`, `availability`, `total_gigs`, `is_available`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    foreach ($buddySeeds as $b) {
        $stmtBuddy->execute($b);
    }
    $step('Seeded `tbl_buddy_profiles` table', true);

    // Seed specialties, languages, and verifications
    $pdo->exec("
    INSERT INTO `tbl_buddy_specialties` (`profile_id`, `specialty_id`) VALUES
    (1, 1), (2, 2), (3, 3), (4, 4), (5, 4), (6, 5), (7, 6), (8, 7), (9, 8), (10, 9);
    ");
    $pdo->exec("
    INSERT INTO `tbl_buddy_languages` (`profile_id`, `language_id`) VALUES
    (1, 1), (1, 2), (2, 1), (2, 3), (3, 1), (3, 2), (4, 1), (4, 2), (5, 1), (5, 2), (6, 1), (6, 2), (6, 4), (7, 1), (7, 2), (8, 1), (9, 1), (9, 2), (10, 1), (10, 2), (10, 5);
    ");
    $pdo->exec("
    INSERT INTO `tbl_buddy_verifications` (`profile_id`, `verification_type`, `id_photo_url`, `verification_status`) VALUES
    (1, 'id', 'uploads/verification/id_angelo.jpg', 'verified'),
    (2, 'id', 'uploads/verification/id_emmanuel.jpg', 'verified'),
    (3, 'id', 'uploads/verification/id_liah.jpg', 'verified'),
    (4, 'id', 'uploads/verification/id_von.jpg', 'verified'),
    (5, 'id', 'uploads/verification/id_neil.jpg', 'verified'),
    (6, 'id', 'uploads/verification/id_toper.jpg', 'verified'),
    (7, 'id', 'uploads/verification/id_julius.jpg', 'verified'),
    (8, 'id', 'uploads/verification/id_dominic.jpg', 'verified'),
    (9, 'id', 'uploads/verification/id_zachary.jpg', 'verified'),
    (10, 'id', 'uploads/verification/id_excell.jpg', 'verified');
    ");
    $step('Seeded specialties, languages, and verification links for all buddies', true);

    // Seed gallery images
    $gallerySeeds = [
        [1, 'images/Angelo_Maduro.jpg'],
        [1, 'images/buddies/angelo_maduro_1.jpg'],
        [1, 'images/buddies/angelo_maduro_2.jpg'],
        [1, 'images/buddies/angelo_maduro_3.jpg'],
        [1, 'images/buddies/angelo_maduro_4.jpg'],
        [2, 'images/Emmanuel_Tristen.jpg'],
        [2, 'images/buddies/emmanuel_creo_1.jpg'],
        [3, 'images/Liah_Faith.jpg'],
        [3, 'images/buddies/liah_faith_1.jpg'],
        [4, 'images/buddies/von_arvin_1.jpg'],
        [4, 'images/buddies/von_arvin_2.jpg'],
        [4, 'images/buddies/von_arvin_3.jpg'],
        [5, 'images/buddies/neil_andrei_1.png'],
        [6, 'images/toper1.png'],
        [6, 'images/toper2.png'],
        [7, 'images/buddies/julius_rodil_1.jpg'],
        [7, 'images/buddies/julius_rodil_2.jpg'],
        [8, 'images/buddies/dominic_berdonar_1.jpg'],
        [9, 'images/Zachary_Owen.jpg'],
        [9, 'images/buddies/zachary_marayag_1.jpg'],
        [9, 'images/buddies/zachary_marayag_2.jpg'],
        [10, 'images/buddies/excell_viray_1.jpeg'],
        [10, 'images/buddies/excell_viray_2.jpg']
    ];
    $stmtGallery = $pdo->prepare("INSERT INTO `tbl_buddy_gallery` (`profile_id`, `image_url`) VALUES (?, ?)");
    foreach ($gallerySeeds as $g) {
        $stmtGallery->execute($g);
    }
    $step('Seeded gallery images', true);

    // Seed Sample Community Posts
    $pdo->exec("
    INSERT INTO `tbl_community_posts` (`post_id`, `user_id`, `category`, `content`, `is_pinned`, `status`) VALUES
    (1, 14, 'Safety Alert', 'Welcome to the AnyBuddy Community Hub! Remember to keep your communications inside the platform and always meet in public settings for safety.', 1, 'active'),
    (2, 3, 'General', 'Hi guys! Angelo here. I have added new morning availability slots for this upcoming weekend. Feel free to book me if you need support or lifting helper!', 0, 'active'),
    (3, 5, 'Social Hangout', 'Had an awesome piano session with a client today. Music truly heals! Teaching is so fulfilling.', 0, 'active');
    ");

    // Seed Sample Post Likes
    $pdo->exec("
    INSERT INTO `tbl_post_likes` (`post_id`, `user_id`) VALUES
    (1, 3), (1, 13), (2, 13), (3, 14);
    ");

    // Seed Sample Post Comments
    $pdo->exec("
    INSERT INTO `tbl_post_comments` (`comment_id`, `post_id`, `user_id`, `content`, `status`) VALUES
    (1, 1, 3, 'Great reminder! Safety is always number one.', 'active'),
    (2, 3, 13, 'You are an amazing teacher, Liah! Highly recommended.', 'active');
    ");
    $step('Seeded sample community posts, likes, and comments', true);

    // Seed Initial System Audit Log
    $pdo->exec("
        INSERT INTO `tbl_audit_logs` (`performed_by`, `action`, `entity_type`, `entity_id`, `details`) VALUES
        (14, 'system_init', 'database', 0, 'Database 3NF schema initialized and seeded successfully.')
    ");
    $step('Seeded initial system audit log', true);

    if ($echoHtml) {
        echo "<hr><p style='font-family:sans-serif'><strong>3NF Schema Initialization Complete.</strong></p>"
           . "</body></html>\n";
    } else {
        echo "3NF Schema Initialization Complete.\n";
    }
}

// ── Entry point for initializing database via CLI argument ──
if (
    php_sapi_name() === 'cli' && isset($argv) && in_array('--init-db', $argv)
) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        ab_init_database($pdo, php_sapi_name() !== 'cli');
    } catch (PDOException $e) {
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h2>Database Connection Failed</h2><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        } else {
            echo "Database connection failed: " . $e->getMessage() . "\n";
        }
        exit(1);
    }
    exit;
}
