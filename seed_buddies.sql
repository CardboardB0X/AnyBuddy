-- ==============================================================
--  AnyBuddy — Buddy Profile Seed Script
--  File    : seed_buddies.sql
--  Target  : anybuddy_db  (WAMP / MySQL 8.x)
--  Run via : MySQL CLI, phpMyAdmin, or HeidiSQL
--
--  WHAT THIS SCRIPT DOES
--  ─────────────────────
--  1. Ensures the database is selected.
--  2. Creates the four source-schema tables (tbl_*) that carry
--     the richer profile metadata not present in the core tables.
--  3. Cleans up existing conflicting records (resetting tables).
--  4. Inserts 10 buddy users and 1 client user into the `users` table.
--  5. Inserts matching records into `buddy_profiles` with actual names.
--  6. Creates and populates tbl_specialties, tbl_buddy_specialties, and tbl_buddy_gallery.
-- ==============================================================

USE `anybuddy_db`;

-- ──────────────────────────────────────────────────────────────
--  PART 1 ── Source-schema tables (tbl_*)
--  These extend the core schema with richer profile metadata.
-- ──────────────────────────────────────────────────────────────

-- 1-A. tbl_users  (mirrors `users` but adds `role` column)
CREATE TABLE IF NOT EXISTS `tbl_users` (
    `user_id`       INT UNSIGNED  NOT NULL,
    `first_name`    VARCHAR(80)   NOT NULL,
    `last_name`     VARCHAR(80)   NOT NULL,
    `email`         VARCHAR(255)  NOT NULL,
    `pronouns`      VARCHAR(50)   NULL DEFAULT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `role`          ENUM('client','buddy','admin') NOT NULL DEFAULT 'client',
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_tbl_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1-B. tbl_buddy_profiles  (extended metadata beyond buddy_profiles)
CREATE TABLE IF NOT EXISTS `tbl_buddy_profiles` (
    `profile_id`    INT UNSIGNED    NOT NULL,
    `user_id`       INT UNSIGNED    NOT NULL,
    `tagline`       VARCHAR(120)    NOT NULL,
    `description`   TEXT            NOT NULL,
    `hourly_rate`   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    `service_mode`  ENUM('Physical','Online','Flexible') NOT NULL DEFAULT 'Flexible',
    `location`      VARCHAR(150)    NOT NULL,
    `total_gigs`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `avg_rating`    DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    `is_verified`   TINYINT(1)      NOT NULL DEFAULT 0,
    `response_time` VARCHAR(60)     NULL DEFAULT NULL,
    `languages`     VARCHAR(200)    NULL DEFAULT NULL,
    `availability`  VARCHAR(255)    NOT NULL,
    `photo_url`     VARCHAR(500)    NULL DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`profile_id`),
    UNIQUE KEY `uq_tbl_bp_user` (`user_id`),
    CONSTRAINT `fk_tbl_bp_user`
        FOREIGN KEY (`user_id`) REFERENCES `tbl_users`(`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1-C. tbl_specialties  (lookup / reference table)
CREATE TABLE IF NOT EXISTS `tbl_specialties` (
    `specialty_id` TINYINT UNSIGNED NOT NULL,
    `name`         VARCHAR(100)     NOT NULL,
    `icon`         VARCHAR(10)      NOT NULL DEFAULT '',
    PRIMARY KEY (`specialty_id`),
    UNIQUE KEY `uq_specialty_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1-D. tbl_buddy_specialties  (junction: profile ↔ specialty)
CREATE TABLE IF NOT EXISTS `tbl_buddy_specialties` (
    `profile_id`   INT UNSIGNED     NOT NULL,
    `specialty_id` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`profile_id`, `specialty_id`),
    CONSTRAINT `fk_bs_profile`
        FOREIGN KEY (`profile_id`) REFERENCES `tbl_buddy_profiles`(`profile_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bs_specialty`
        FOREIGN KEY (`specialty_id`) REFERENCES `tbl_specialties`(`specialty_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1-E. tbl_buddy_gallery  (buddy images)
CREATE TABLE IF NOT EXISTS `tbl_buddy_gallery` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `profile_id`    INT UNSIGNED    NOT NULL,
    `image_url`     VARCHAR(500)    NOT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_gallery_profile`
        FOREIGN KEY (`profile_id`) REFERENCES `buddy_profiles`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
--  PART 2 ── CLEAN RESET & AUTO-INCREMENT SETTING
-- ──────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM `tbl_buddy_gallery` WHERE 1;
DELETE FROM `tbl_buddy_specialties` WHERE 1;
DELETE FROM `tbl_buddy_profiles` WHERE 1;
DELETE FROM `tbl_specialties` WHERE 1;
DELETE FROM `tbl_users` WHERE 1;
DELETE FROM `buddy_profiles` WHERE 1;
DELETE FROM `users` WHERE 1;
ALTER TABLE `users` AUTO_INCREMENT = 1;
ALTER TABLE `buddy_profiles` AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- ──────────────────────────────────────────────────────────────
--  PART 3 ── INSERT into `users` (core anybuddy_db table)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `users`
    (`id`, `first_name`, `last_name`, `email`, `password_hash`, `pronouns`, `theme_preference`)
VALUES
    (3,  'Angelo',      'Maduro',    'angelo@anybuddy.ph',    '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (4,  'Emmanuel',    'Creo',      'emmanuel@anybuddy.ph',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (5,  'Liah Faith',  'Espineli',  'liah@anybuddy.ph',      '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'She/Her',  'light'),
    (6,  'Von Arvin',   'Apilado',   'von@anybuddy.ph',       '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (7,  'Neil Andrei', 'Toledo',    'neil@anybuddy.ph',      '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (8,  'Toper',       'Claveria',  'toper@anybuddy.ph',     '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (9,  'Julius',      'Rodil',     'julius@anybuddy.ph',    '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (10, 'Dominic',     'Berdonar',  'dominic@anybuddy.ph',   '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (11, 'Zachary Owen','Marayag',   'zachary@anybuddy.ph',   '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (12, 'Excell',      'Viray',     'excell@anybuddy.ph',    '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light'),
    (13, 'John',        'Doe',       'client@anybuddy.ph',    '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'He/Him',   'light');

-- ──────────────────────────────────────────────────────────────
--  PART 4 ── INSERT into `buddy_profiles` (core anybuddy_db table)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `buddy_profiles`
    (`id`, `user_id`, `display_name`, `professional_title`, `category`,
     `bio`, `hourly_rate`, `location`, `availability`, `is_verified`, `avatar_url`)
VALUES
    (1,  3,  'Angelo Maduro',          'Personal Bodyguard & Intimidation Specialist', 'security', 'Need muscle? Intimidation? I am the Macho Man. I will stand behind you menacingly and flex to solve your problems.',           400.00, 'Tanza',         'Mon-Sun, 6AM-10PM', 1, 'images/Angelo_Maduro.jpg'),
    (2,  4,  'Emmanuel Creo',          'Construction Worker & Carpenter',              'casual',   'Construction worker and carpenter. I can fix your roof, build a cabinet, or mix cement faster than anyone.',                   350.00, 'Amadeo',        'Mon-Sat, 7AM-5PM',  1, 'images/Emmanuel_Tristen.jpg'),
    (3,  5,  'Liah Faith Espineli',    'Professional Pianist & Music Instructor',      'arts',     'Professional piano player. I can perform at your events, teach you the basics, or accompany your singing.',                    500.00, 'Maragondon',    'Mon-Fri, 10AM-8PM',  1, 'images/Liah_Faith.jpg'),
    (4,  6,  'Von Arvin Apilado',      'Multimedia Designer & UI/UX Specialist',       'arts',     'Multimedia Designer. I specialize in video editing, motion graphics, and UI/UX design. Let us build something beautiful.',     450.00, 'Remote',        'Mon-Fri, 9AM-6PM',  1, 'images/buddies/von_arvin_1.jpg'),
    (5,  7,  'Neil Andrei Toledo',     '3D Artist & Brand Identity Designer',          'arts',     'Multimedia Designer. I handle complex 3D rendering, branding, and comprehensive graphic design solutions.',                    450.00, 'Remote',        'Mon-Fri, 9AM-6PM',  1, 'images/buddies/neil_andrei_1.png'),
    (6,  8,  'Toper Claveria',         'Actor & Social Roleplay Specialist',           'event',    'Need someone to pretend to be your boyfriend to make your ex jealous? Need a dramatic reading? I am your guy.',                600.00, 'Silang',        'Weekends, 12PM-10PM',1, 'images/toper1.png'),
    (7,  9,  'Julius Rodil',           'PC Hardware Technician & Repair Specialist',    'casual',   'Hardware fixer. Is your PC blue-screening? Laptop overheating? I will diagnose and repair your rig.',                          300.00, 'Trece',         'Mon-Sun, 8AM-11PM', 1, 'images/buddies/julius_rodil_1.jpg'),
    (8,  10, 'Dominic Berdonar',       'Queue & Errand Proxy Specialist',              'casual',   'Manggahan Bystander. I will stand in line for you at government offices, watch your stuff, or just hang around.',              150.00, 'Manggahan',     'Mon-Sun, 24/7',     1, 'images/buddies/dominic_berdonar_1.jpg'),
    (9,  11, 'Zachary Owen Marayag',   'Dancer, Singer & Party Entertainer',           'event',    'Dancer and Singer. Available for flash mobs, serenade services, and party entertainment.',                                      380.00, 'General Trias', 'Fri-Sun, 5PM-12AM',  1, 'images/Zachary_Owen.jpg'),
    (10, 12, 'Excell Viray',           'Professional Gaming Pilot & Account Booster',  'casual',   'Gaming Pilot. Stuck on the Abyss in Genshin Impact? Cant beat holograms in Wuthering Waves? I will pilot your account and clear it.', 250.00, 'Remote',        'Mon-Sun, 12PM-4AM',  1, 'images/buddies/excell_viray_1.jpeg');

-- ──────────────────────────────────────────────────────────────
--  PART 5 ── INSERT into tbl_users (source-schema mirror)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `tbl_users`
    (`user_id`, `first_name`, `last_name`, `email`, `pronouns`, `password_hash`, `role`)
VALUES
    (3,  'Angelo',       'Maduro',   'angelo@anybuddy.ph',    'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (4,  'Emmanuel',     'Creo',     'emmanuel@anybuddy.ph',  'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (5,  'Liah Faith',   'Espineli', 'liah@anybuddy.ph',      'She/Her', '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (6,  'Von Arvin',    'Apilado',  'von@anybuddy.ph',       'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (7,  'Neil Andrei',  'Toledo',   'neil@anybuddy.ph',      'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (8,  'Toper',        'Claveria', 'toper@anybuddy.ph',     'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (9,  'Julius',       'Rodil',    'julius@anybuddy.ph',    'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (10, 'Dominic',      'Berdonar', 'dominic@anybuddy.ph',   'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (11, 'Zachary Owen', 'Marayag',  'zachary@anybuddy.ph',   'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (12, 'Excell',       'Viray',    'excell@anybuddy.ph',    'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'buddy'),
    (13, 'John',         'Doe',      'client@anybuddy.ph',    'He/Him',  '$2y$10$x6ezWDd2O3w7cutuXVThBuODR5AvuAvSvML8e7lKm.UnXe9Zu/I/2', 'client');

-- ──────────────────────────────────────────────────────────────
--  PART 6 ── INSERT into tbl_buddy_profiles (source-schema)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `tbl_buddy_profiles`
    (`profile_id`, `user_id`, `tagline`, `description`, `hourly_rate`,
     `service_mode`, `location`, `total_gigs`, `avg_rating`, `is_verified`,
     `response_time`, `languages`, `availability`, `photo_url`, `is_active`)
VALUES
    (1,  3,  'The Macho Man',          'Need muscle? Intimidation? I am the Macho Man. I will stand behind you menacingly and flex to solve your problems.',           400.00, 'Physical',  'Tanza',         25,  4.80, 1, 'Within 30 minutes',  'Filipino, English',              'Mon-Sun, 6AM-10PM',    'images/Angelo_Maduro.jpg',   1),
    (2,  4,  'Master Carpenter',       'Construction worker and carpenter. I can fix your roof, build a cabinet, or mix cement faster than anyone.',                   350.00, 'Physical',  'Amadeo',        42,  4.90, 1, 'Within 1 hour',      'Filipino, English, Bisaya',      'Mon-Sat, 7AM-5PM',     'images/Emmanuel_Tristen.jpg',   1),
    (3,  5,  'Classical Virtuoso',     'Professional piano player. I can perform at your events, teach you the basics, or accompany your singing.',                    500.00, 'Flexible',  'Maragondon',    18,  5.00, 1, 'Within 2 hours',     'Filipino, English',              'Mon-Fri, 10AM-8PM',    'images/Liah_Faith.jpg',      1),
    (4,  6,  'Pixel Perfect',          'Multimedia Designer. I specialize in video editing, motion graphics, and UI/UX design. Let us build something beautiful.',     450.00, 'Online',    'Remote',        55,  4.70, 1, 'Within 1 hour',      'Filipino, English',              'Mon-Fri, 9AM-6PM',     'images/buddies/von_arvin_1.jpg', 1),
    (5,  7,  'Creative Director',      'Multimedia Designer. I handle complex 3D rendering, branding, and comprehensive graphic design solutions.',                    450.00, 'Online',    'Remote',        30,  4.80, 1, 'Within 1 hour',      'Filipino, English',              'Mon-Fri, 9AM-6PM',     'images/buddies/neil_andrei_1.png',   1),
    (6,  8,  'Method Actor',           'Need someone to pretend to be your boyfriend to make your ex jealous? Need a dramatic reading? I am your guy.',                600.00, 'Flexible',  'Silang',        12,  4.50, 1, 'Within 3 hours',     'Filipino, English, Spanish',     'Weekends, 12PM-10PM',  'images/toper1.png',1),
    (7,  9,  'The Tech Wizard',        'Hardware fixer. Is your PC blue-screening? Laptop overheating? I will diagnose and repair your rig.',                          300.00, 'Physical',  'Trece',         88,  4.90, 1, 'Within 15 minutes',  'Filipino, English',              'Mon-Sun, 8AM-11PM',    'images/buddies/julius_rodil_1.jpg',  1),
    (8,  10, 'Professional Bystander', 'Manggahan Bystander. I will stand in line for you at government offices, watch your stuff, or just hang around.',              150.00, 'Physical',  'Manggahan',    120,  4.60, 1, 'Within 5 minutes',   'Filipino',                       'Mon-Sun, 24/7',        'images/buddies/dominic_berdonar_1.jpg', 1),
    (9,  11, 'Triple Threat',          'Dancer and Singer. Available for flash mobs, serenade services, and party entertainment.',                                      380.00, 'Flexible',  'General Trias', 22,  4.70, 1, 'Within 2 hours',     'Filipino, English',              'Fri-Sun, 5PM-12AM',    'images/Zachary_Owen.jpg',    1),
    (10, 12, 'The Carry',              'Gaming Pilot. Stuck on the Abyss in Genshin Impact? Cant beat holograms in Wuthering Waves? I will pilot your account and clear it.', 250.00, 'Online', 'Remote', 300, 5.00, 1, 'Within 10 minutes',  'Filipino, English, Japanese',    'Mon-Sun, 12PM-4AM',    'images/buddies/excell_viray_1.jpeg',  1);

-- ──────────────────────────────────────────────────────────────
--  PART 7 ── INSERT into tbl_specialties (reference / lookup)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `tbl_specialties` (`specialty_id`, `name`, `icon`) VALUES
    (1, 'Intimidation / Muscle',     '🥊'),
    (2, 'Handyman / Construction',   '🔨'),
    (3, 'Musical Arts',              '🎹'),
    (4, 'Digital Arts / Design',     '🎨'),
    (5, 'Acting / Roleplay',         '🎭'),
    (6, 'Tech Support / Hardware',   '💻'),
    (7, 'Endurance / Pila-Sitter',   '⏳'),
    (8, 'Entertainment / Dance',     '🎤'),
    (9, 'Gaming Carry',              '🎮');

-- ──────────────────────────────────────────────────────────────
--  PART 8 ── INSERT into tbl_buddy_specialties (junction)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `tbl_buddy_specialties` (`profile_id`, `specialty_id`) VALUES
    (1,  1),   -- Angelo Maduro        → Intimidation / Muscle
    (2,  2),   -- Emmanuel Creo        → Handyman / Construction
    (3,  3),   -- Liah Faith Espineli  → Musical Arts
    (4,  4),   -- Von Arvin Apilado    → Digital Arts / Design
    (5,  4),   -- Neil Andrei Toledo   → Digital Arts / Design
    (6,  5),   -- Toper Claveria       → Acting / Roleplay
    (7,  6),   -- Julius Rodil         → Tech Support / Hardware
    (8,  7),   -- Dominic Berdonar     → Endurance / Pila-Sitter
    (9,  8),   -- Zachary Owen Marayag → Entertainment / Dance
    (10, 9);   -- Excell Viray         → Gaming Carry

-- ──────────────────────────────────────────────────────────────
--  PART 9 ── INSERT into tbl_buddy_gallery (images)
-- ──────────────────────────────────────────────────────────────

INSERT INTO `tbl_buddy_gallery` (`profile_id`, `image_url`) VALUES
    (1, 'images/Angelo_Maduro.jpg'),
    (1, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (1, 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&h=600&fit=crop'),
    (1, 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&h=600&fit=crop'),
    (2, 'images/Emmanuel_Tristen.jpg'),
    (2, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (2, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (2, 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&h=600&fit=crop'),
    (3, 'images/Liah_Faith.jpg'),
    (3, 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=500&h=600&fit=crop'),
    (3, 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=500&h=600&fit=crop'),
    (3, 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=500&h=600&fit=crop'),
    (4, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (4, 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&h=600&fit=crop'),
    (4, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (4, 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=500&h=600&fit=crop'),
    (5, 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=500&h=600&fit=crop'),
    (5, 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&h=600&fit=crop'),
    (5, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (5, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (6, 'images/toper1.png'),
    (6, 'images/toper2.png'),
    (7, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (7, 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&h=600&fit=crop'),
    (7, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (7, 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=500&h=600&fit=crop'),
    (8, 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&h=600&fit=crop'),
    (8, 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&h=600&fit=crop'),
    (8, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (8, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (9, 'images/Zachary_Owen.jpg'),
    (9, 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=500&h=600&fit=crop'),
    (9, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (9, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop'),
    (10, 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=500&h=600&fit=crop'),
    (10, 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=500&h=600&fit=crop'),
    (10, 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=500&h=600&fit=crop'),
    (10, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=500&h=600&fit=crop');
