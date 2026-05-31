<?php
/**
 * AnyBuddy — Presentation Demo Panel Control Panel
 * File   : demo.php
 * Usage  : Standalone page for school presentation demo controls
 */

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

$pdo = ab_pdo();
$message = '';
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $role = $_POST['role'] ?? '';
        $email = '';
        if ($role === 'client') {
            $email = 'client@anybuddy.ph';
        } elseif ($role === 'buddy') {
            $email = 'angelo@anybuddy.ph';
        } elseif ($role === 'admin') {
            $email = 'admin@anybuddy.ph';
        }
        
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT * FROM `tbl_users` WHERE `email` = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id']    = (int) $user['user_id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role']       = $user['role'];
                
                // Fetch buddy profile ID if any
                $bp = $pdo->prepare("SELECT `profile_id` FROM `tbl_buddy_profiles` WHERE `user_id` = ?");
                $bp->execute([$user['user_id']]);
                $buddyProfileId = $bp->fetchColumn();
                
                $userJson = json_encode([
                    'id'               => (int) $user['user_id'],
                    'user_id'          => (int) $user['user_id'],
                    'first_name'       => $user['first_name'],
                    'last_name'        => $user['last_name'],
                    'email'            => $user['email'],
                    'pronouns'         => $user['pronouns'],
                    'theme_preference' => $user['theme_preference'],
                    'avatar_url'       => $user['profile_photo'],
                    'profile_photo'    => $user['profile_photo'],
                    'role'             => $user['role'],
                    'is_buddy'         => !empty($buddyProfileId),
                    'buddy_profile_id' => $buddyProfileId ? (int)$buddyProfileId : null
                ]);
                
                echo "
                <!DOCTYPE html>
                <html>
                <head>
                    <link rel='stylesheet' href='theme.css'>
                    <script src='theme.js'></script>
                    <script src='app_ajax.js'></script>
                </head>
                <body style='background: radial-gradient(circle, rgba(13,15,18,0.97) 0%, rgba(5,6,8,0.99) 100%); display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;'>
                    <script>
                        localStorage.setItem('ab_user', '" . addslashes($userJson) . "');
                        triggerPortalTransition('Logging in as " . $user['first_name'] . " (" . ucfirst($user['role']) . ")... 👋', 'homepage.html');
                    </script>
                </body>
                </html>
                ";
                exit;
            }
        }
    }
    
    if ($action === 'reset_db') {
        try {
            ab_init_database($pdo, false);
            $message = "Database successfully reinitialized and seeded to default state!";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Database reset failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
    
    if ($action === 'inject_booking') {
        try {
            // Find client John Doe (13) and buddy Angelo (profile_id 1)
            $clientId = 13;
            $buddyProfileId = 1;
            
            // Check if there is an availability slot for today/tomorrow
            $date = date('Y-m-d', strtotime('+1 day'));
            
            // Delete potential conflict booking
            $pdo->prepare("DELETE FROM `tbl_bookings` WHERE `client_id` = ? AND `buddy_profile_id` = ? AND `booking_date` = ?")->execute([$clientId, $buddyProfileId, $date]);
            
            // Insert slot if not exists
            $slotCheck = $pdo->prepare("SELECT `id` FROM `tbl_buddy_availability` WHERE `buddy_profile_id` = ? AND `available_date` = ? AND `start_time` = '14:00:00'");
            $slotCheck->execute([$buddyProfileId, $date]);
            $slotId = $slotCheck->fetchColumn();
            if ($slotId === false) {
                $pdo->prepare("INSERT INTO `tbl_buddy_availability` (`buddy_profile_id`, `available_date`, `start_time`, `end_time`, `is_booked`) VALUES (?, ?, '14:00:00', '16:00:00', 1)")->execute([$buddyProfileId, $date]);
            } else {
                $pdo->prepare("UPDATE `tbl_buddy_availability` SET `is_booked` = 1 WHERE `id` = ?")->execute([$slotId]);
            }
            
            // Insert Booking
            $ins = $pdo->prepare("
                INSERT INTO `tbl_bookings` 
                (`client_id`, `buddy_profile_id`, `booking_date`, `start_time`, `hours_duration`, `base_price`, `platform_fee`, `total_price`, `status_id`, `payment_method`, `message`)
                VALUES (?, ?, ?, '14:00:00', 2, 800.00, 40.00, 840.00, 1, 'Card', 'Hi! I need personal bodyguard assistance for my school presentation tomorrow. Please protect me!')
            ");
            $ins->execute([$clientId, $buddyProfileId, $date]);
            $newBookingId = $pdo->lastInsertId();
            
            // Add notification for buddy
            ab_add_notification($pdo, 3, "New Booking Request", "You have a new booking request from John Doe on $date.", "bookings.html");
            
            $message = "Mock Booking successfully injected! Booking ID: $newBookingId (John Doe → Angelo Maduro)";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Injected booking failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
    
    if ($action === 'inject_chat') {
        try {
            // Find or create a dummy booking between John Doe (13) and Angelo (profile_id 1)
            $clientId = 13;
            $buddyProfileId = 1;
            $date = date('Y-m-d');
            
            $bookingStmt = $pdo->prepare("SELECT `booking_id` FROM `tbl_bookings` WHERE `client_id` = ? AND `buddy_profile_id` = ? ORDER BY `booking_id` DESC LIMIT 1");
            $bookingStmt->execute([$clientId, $buddyProfileId]);
            $bookingId = $bookingStmt->fetchColumn();
            
            if ($bookingId === false) {
                // Create a booking
                $ins = $pdo->prepare("
                    INSERT INTO `tbl_bookings` 
                    (`client_id`, `buddy_profile_id`, `booking_date`, `start_time`, `hours_duration`, `base_price`, `platform_fee`, `total_price`, `status_id`, `payment_method`, `message`)
                    VALUES (?, ?, ?, '14:00:00', 2, 800.00, 40.00, 840.00, 2, 'Card', 'Chat initialization booking')
                ");
                $ins->execute([$clientId, $buddyProfileId, $date]);
                $bookingId = $pdo->lastInsertId();
            }
            
            $bookingId = (int) $bookingId;
            
            // Delete old messages for clean test
            $pdo->prepare("DELETE FROM `tbl_messages` WHERE `booking_id` = ?")->execute([$bookingId]);
            
            // Inject conversation
            $msgs = [
                [13, "Hi Angelo! I just requested a booking. Are you available to join my presentation group tomorrow?"],
                [3, "Hey John! Absolutely. I'll act as your bodyguard. I'm reading through the presentation slides right now."],
                [13, "Awesome! I'll make sure to introduce you as our security buddy. See you tomorrow!"],
                [3, "Got it. I'll wear my formal suit. Ready to protect your grades! 🥊"]
            ];
            
            $insMsg = $pdo->prepare("INSERT INTO `tbl_messages` (`booking_id`, `sender_id`, `message_text`, `is_read`, `created_at`) VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)");
            foreach ($msgs as $m) {
                $insMsg->execute([$bookingId, $m[0], $m[1]]);
            }
            
            $message = "Mock Chat conversation successfully injected for Booking ID: $bookingId!";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Injected chat failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
    
    if ($action === 'inject_notif') {
        try {
            $userId = $_SESSION['user_id'] ?? 13;
            ab_add_notification($pdo, (int)$userId, "Demo Alert", "This is a demo notification created to showcase the dynamic dropdown panel! 🔔", "bookings.html");
            ab_add_notification($pdo, (int)$userId, "System Promotion", "Congratulations! You have been upgraded to the Gold Loyalty Tier. Enjoy 5% discounts!", "profile.html");
            
            $message = "Mock Notifications successfully injected for current logged-in user!";
            $messageType = "success";
        } catch (Exception $e) {
            $message = "Injected notifications failed: " . $e->getMessage();
            $messageType = "error";
        }
    }
    
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        
        echo "
        <!DOCTYPE html>
        <html>
        <head>
            <link rel='stylesheet' href='theme.css'>
            <script src='theme.js'></script>
            <script src='app_ajax.js'></script>
        </head>
        <body style='background: radial-gradient(circle, rgba(13,15,18,0.97) 0%, rgba(5,6,8,0.99) 100%); display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;'>
            <script>
                localStorage.removeItem('ab_user');
                triggerPortalTransition('Logging out...', 'demo.php');
            </script>
        </body>
        </html>
        ";
        exit;
    }
}

// Check current user state
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM `tbl_users` WHERE `user_id` = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AnyBuddy — Presenter Demo Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0d10;
            --accent: #fe6fbe;
            --cyan: #00d2ff;
            --card-bg: rgba(20, 24, 33, 0.65);
            --border: rgba(255, 255, 255, 0.08);
            --text: #e2e8f0;
            --text-muted: #8a99ad;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background: radial-gradient(circle at top right, #1a1525 0%, var(--bg) 60%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            width: 100%;
            max-width: 750px;
            padding: 2rem;
            box-sizing: border-box;
        }

        .panel-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header img {
            width: 60px;
            height: 60px;
            margin-bottom: 0.75rem;
        }

        .header h1 {
            margin: 0;
            font-size: 2.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent) 0%, var(--cyan) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .header p {
            margin: 0.5rem 0 0;
            color: var(--text-muted);
            font-size: 1rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            line-height: 1.4;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #34d399;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .state-banner {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .state-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            background: #181d28;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: var(--accent);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #22c55e;
            box-shadow: 0 0 8px #22c55e;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text);
            margin: 1.5rem 0 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.5rem;
        }

        .grid-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .btn {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-client {
            background: rgba(254, 111, 190, 0.1);
            border: 1px solid rgba(254, 111, 190, 0.25);
            color: var(--accent);
        }
        .btn-client:hover {
            background: var(--accent);
            color: #000;
            box-shadow: 0 0 15px rgba(254, 111, 190, 0.4);
        }

        .btn-buddy {
            background: rgba(0, 210, 255, 0.1);
            border: 1px solid rgba(0, 210, 255, 0.25);
            color: var(--cyan);
        }
        .btn-buddy:hover {
            background: var(--cyan);
            color: #000;
            box-shadow: 0 0 15px rgba(0, 210, 255, 0.4);
        }

        .btn-admin {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-admin:hover {
            background: #fff;
            color: #000;
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.4);
        }

        .btn-action {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            color: var(--text);
            width: 100%;
            justify-content: flex-start;
            padding: 1rem 1.25rem;
            border-radius: 14px;
        }
        .btn-action:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateX(4px);
        }

        .btn-reset {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #f87171;
            width: 100%;
        }
        .btn-reset:hover {
            background: #ef4444;
            color: #fff;
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.4);
        }

        .btn-logout {
            background: none;
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border-radius: 10px;
        }
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.15);
        }

        .action-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        .footer {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-top: 2rem;
        }
        .footer a {
            color: var(--accent);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel-card">
            <div class="header">
                <img src="images/AnyBuddy LOGO.png" alt="AnyBuddy Logo">
                <h1>AnyBuddy Presenter Panel</h1>
                <p>Quick presentation shortcuts & demo data injectors</p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <span><?php echo ($messageType === 'success' ? '✨' : '⚠️'); ?></span>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>

            <div class="state-banner">
                <div class="state-info">
                    <?php if ($currentUser): ?>
                        <?php if ($currentUser['profile_photo']): ?>
                            <img class="avatar" src="<?php echo $currentUser['profile_photo']; ?>" alt="Avatar" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="avatar"><?php echo substr($currentUser['first_name'], 0, 1); ?></div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                <?php echo htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?>
                                <span class="status-dot"></span>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.1rem;">
                                Active Role: <strong><?php echo ucfirst($currentUser['role']); ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="avatar" style="border-color: var(--text-muted); color: var(--text-muted);">?</div>
                        <div>
                            <div style="font-weight: 600; font-size: 1.05rem;">Guest Presenter</div>
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.1rem;">No session active. Click below to login.</div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($currentUser): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-logout">Logout</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="section-title">One-Click Presenter Logins</div>
            <div class="grid-buttons">
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="role" value="client">
                    <button type="submit" class="btn btn-client" style="width: 100%;">
                        👤 Login Client (John)
                    </button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="role" value="buddy">
                    <button type="submit" class="btn btn-buddy" style="width: 100%;">
                        🥊 Login Buddy (Angelo)
                    </button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="role" value="admin">
                    <button type="submit" class="btn btn-admin" style="width: 100%;">
                        🛡️ Login Admin (AnyBuddy)
                    </button>
                </form>
            </div>

            <div class="section-title">Instant Presentation Data Injectors</div>
            <div class="action-list">
                <form method="POST">
                    <input type="hidden" name="action" value="inject_booking">
                    <button type="submit" class="btn btn-action">
                        📅 <strong>Inject Booking:</strong> John requests Angelo Maduro's services for tomorrow.
                    </button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="inject_chat">
                    <button type="submit" class="btn btn-action">
                        💬 <strong>Inject Chat Conversation:</strong> Setup 4 test messages between John and Angelo.
                    </button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="inject_notif">
                    <button type="submit" class="btn btn-action" <?php echo !$currentUser ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                        🔔 <strong>Inject Notifications:</strong> Add unread notifications for current presenter.
                    </button>
                </form>
            </div>

            <div class="section-title">Danger Zone</div>
            <form method="POST" onsubmit="return confirm('WARNING: This will drop ALL tables, clear all bookings, chats, and custom profiles, and restore the database to seed settings. Proceed?');">
                <input type="hidden" name="action" value="reset_db">
                <button type="submit" class="btn btn-reset">
                    ♻️ Reset Database & Seed Defaults
                </button>
            </form>
            
            <div style="margin-top: 1.5rem; text-align: center;">
                <a href="homepage.html" style="color: var(--cyan); text-decoration: none; font-size: 0.9rem; font-weight: 600;">
                    ← Back to AnyBuddy Website
                </a>
            </div>
        </div>

        <div class="footer">
            AnyBuddy School Project Presentation Companion • Built for Presenters
        </div>
    </div>
</body>
</html>
