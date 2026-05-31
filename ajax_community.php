<?php
/**
 * ============================================================
 *  AnyBuddy — Community Social Hub AJAX Endpoint
 *  File   : ajax_community.php
 *  Method : GET (fetch feed) / POST (create/moderate feed items)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true) ?? [];

if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed.']);
    exit;
}

try {
    $pdo = ab_pdo();

    // ── ACTION 1: GET COMMUNITY FEED ──────────────────────────────
    if ($method === 'GET') {
        // Safe auth check: allow guests (unauthenticated users)
        $currentUserId = 0;
        if (!empty($_SESSION['user_id'])) {
            $currentUserId = (int)$_SESSION['user_id'];
            try {
                $chkUser = $pdo->prepare("SELECT `status`, `is_active` FROM `tbl_users` WHERE `user_id` = ? LIMIT 1");
                $chkUser->execute([$currentUserId]);
                $user = $chkUser->fetch();
                if (!$user || $user['status'] === 'suspended' || $user['status'] === 'banned' || (int)$user['is_active'] === 0) {
                    session_unset();
                    session_destroy();
                    $currentUserId = 0;
                }
            } catch (Exception $e) {
                $currentUserId = 0;
            }
        }

        // Fetch posts
        // We select the author info by joining with tbl_users and self join for reposts
        $postsStmt = $pdo->prepare("
            SELECT 
                p.`post_id`, p.`user_id`, p.`category`, p.`content`, p.`image_url`, p.`repost_of_id`, p.`is_pinned`, p.`status`, p.`created_at`,
                u.`first_name`, u.`last_name`, u.`profile_photo` AS `avatar_url`, u.`role` AS `user_role`,
                (SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = p.`post_id`) AS `likes_count`,
                (SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = p.`post_id` AND `user_id` = :current_user_1) AS `user_has_liked`,
                (SELECT COUNT(*) FROM `tbl_user_follows` WHERE `followed_id` = p.`user_id`) AS `followers_count`,
                (SELECT COUNT(*) FROM `tbl_user_follows` WHERE `followed_id` = p.`user_id` AND `follower_id` = :current_user_2) AS `user_is_following`,
                orig.`content` AS `orig_content`,
                orig.`category` AS `orig_category`,
                orig.`image_url` AS `orig_image_url`,
                orig.`status` AS `orig_status`,
                orig.`created_at` AS `orig_created_at`,
                orig_u.`first_name` AS `orig_first_name`,
                orig_u.`last_name` AS `orig_last_name`,
                orig_u.`profile_photo` AS `orig_avatar_url`,
                orig_u.`role` AS `orig_user_role`,
                orig_u.`user_id` AS `orig_user_id`
            FROM `tbl_community_posts` p
            INNER JOIN `tbl_users` u ON u.`user_id` = p.`user_id`
            LEFT JOIN `tbl_community_posts` orig ON orig.`post_id` = p.`repost_of_id`
            LEFT JOIN `tbl_users` orig_u ON orig_u.`user_id` = orig.`user_id`
            WHERE p.`status` = 'active'
            ORDER BY p.`is_pinned` DESC, p.`created_at` DESC
        ");
        $postsStmt->execute([
            'current_user_1' => $currentUserId,
            'current_user_2' => $currentUserId
        ]);
        $posts = $postsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch active comments for all posts
        $commentsStmt = $pdo->prepare("
            SELECT 
                c.`comment_id`, c.`post_id`, c.`user_id`, c.`content`, c.`created_at`,
                u.`first_name`, u.`last_name`, u.`profile_photo` AS `avatar_url`, u.`role` AS `user_role`
            FROM `tbl_post_comments` c
            INNER JOIN `tbl_users` u ON u.`user_id` = c.`user_id`
            WHERE c.`status` = 'active'
            ORDER BY c.`created_at` ASC
        ");
        $commentsStmt->execute();
        $allComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group comments by post_id
        $commentsByPost = [];
        foreach ($allComments as $comment) {
            $commentsByPost[(int)$comment['post_id']][] = [
                'comment_id' => (int)$comment['comment_id'],
                'user_id' => (int)$comment['user_id'],
                'author_name' => $comment['first_name'] . ' ' . $comment['last_name'],
                'user_role' => $comment['user_role'],
                'avatar_url' => $comment['avatar_url'],
                'content' => $comment['content'],
                'created_at' => $comment['created_at']
            ];
        }

        // Combine data
        $feed = [];
        $currentUserRole = $_SESSION['role'] ?? 'guest';

        foreach ($posts as $p) {
            $postId = (int)$p['post_id'];
            
            // Format repost details if set
            $repostDetails = null;
            if ($p['repost_of_id'] !== null) {
                if ($p['orig_status'] === 'active') {
                    $repostDetails = [
                        'post_id' => (int)$p['repost_of_id'],
                        'user_id' => (int)$p['orig_user_id'],
                        'author_name' => $p['orig_first_name'] . ' ' . $p['orig_last_name'],
                        'user_role' => $p['orig_user_role'],
                        'avatar_url' => $p['orig_avatar_url'],
                        'category' => $p['orig_category'],
                        'content' => $p['orig_content'],
                        'image_url' => $p['orig_image_url'],
                        'created_at' => $p['orig_created_at']
                    ];
                } else {
                    $repostDetails = [
                        'post_id' => (int)$p['repost_of_id'],
                        'deleted' => true
                    ];
                }
            }

            $feed[] = [
                'post_id' => $postId,
                'user_id' => (int)$p['user_id'],
                'author_name' => $p['first_name'] . ' ' . $p['last_name'],
                'user_role' => $p['user_role'],
                'avatar_url' => $p['avatar_url'],
                'category' => $p['category'],
                'content' => $p['content'],
                'image_url' => $p['image_url'],
                'is_pinned' => (int)$p['is_pinned'] === 1,
                'likes_count' => (int)$p['likes_count'],
                'user_has_liked' => (int)$p['user_has_liked'] === 1,
                'followers_count' => (int)$p['followers_count'],
                'user_is_following' => (int)$p['user_is_following'] === 1,
                'repost_details' => $repostDetails,
                'comments' => $commentsByPost[$postId] ?? [],
                'created_at' => $p['created_at']
            ];
        }

        echo json_encode([
            'status' => 'success',
            'current_user_id' => $currentUserId,
            'current_user_role' => $currentUserRole,
            'feed' => $feed
        ]);
        exit;
    }

    // ── ACTION 2: POST MODIFICATION ENDPOINTS ───────────────────────
    if ($method === 'POST') {
        $userId = ab_require_auth();
        $userRole = $_SESSION['role'] ?? 'client';
        $action = trim($input['action'] ?? $_POST['action'] ?? '');

        if ($action === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing action parameter.']);
            exit;
        }

        // 1. Create Post
        if ($action === 'create_post') {
            $content = trim($input['content'] ?? $_POST['content'] ?? '');
            $category = trim($input['category'] ?? $_POST['category'] ?? 'General');

            if ($content === '' && !isset($_FILES['image'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post content or image is required.']);
                exit;
            }

            $allowedCategories = ['General', 'Booking Tips', 'Social Hangout', 'Safety Alert'];
            if (!in_array($category, $allowedCategories)) {
                $category = 'General';
            }

            $imageUrl = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['image']['tmp_name'];
                $fileName = $_FILES['image']['name'];
                $fileSize = $_FILES['image']['size'];
                
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));
                
                $allowedfileExtensions = ['jpg', 'gif', 'png', 'jpeg', 'webp'];
                if (in_array($fileExtension, $allowedfileExtensions)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $fileTmpPath);
                    finfo_close($finfo);
                    
                    if (str_starts_with($mime, 'image/')) {
                        if ($fileSize <= 5 * 1024 * 1024) {
                            $uploadFileDir = __DIR__ . '/images/posts/';
                            if (!is_dir($uploadFileDir)) {
                                mkdir($uploadFileDir, 0755, true);
                            }
                            $newFileName = md5(uniqid((string)rand(), true)) . '.' . $fileExtension;
                            $dest_path = $uploadFileDir . $newFileName;
                            
                            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                                $imageUrl = 'images/posts/' . $newFileName;
                            } else {
                                http_response_code(500);
                                echo json_encode(['status' => 'error', 'message' => 'Error moving uploaded image.']);
                                exit;
                            }
                        } else {
                            http_response_code(400);
                            echo json_encode(['status' => 'error', 'message' => 'Image size exceeds maximum limit of 5MB.']);
                            exit;
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Uploaded file is not a valid image.']);
                        exit;
                    }
                } else {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Unsupported image format. Allowed: JPG, PNG, GIF, WEBP.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_community_posts` (`user_id`, `category`, `content`, `image_url`)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $category, $content, $imageUrl]);
            $newPostId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_post', 'community_posts', $newPostId, "User created community post ID {$newPostId} in category {$category}.");

            echo json_encode([
                'status' => 'success',
                'message' => 'Post created successfully!',
                'post_id' => $newPostId
            ]);
            exit;
        }

        // 2. Like/Unlike Post
        elseif ($action === 'like_post') {
            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing post ID.']);
                exit;
            }

            // Check if already liked
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = ? AND `user_id` = ?");
            $chk->execute([$postId, $userId]);
            $alreadyLiked = ((int)$chk->fetchColumn() > 0);

            if ($alreadyLiked) {
                // Unlike
                $del = $pdo->prepare("DELETE FROM `tbl_post_likes` WHERE `post_id` = ? AND `user_id` = ?");
                $del->execute([$postId, $userId]);
                $state = 'unliked';
            } else {
                // Like
                $ins = $pdo->prepare("INSERT INTO `tbl_post_likes` (`post_id`, `user_id`) VALUES (?, ?)");
                $ins->execute([$postId, $userId]);
                $state = 'liked';
            }

            // Get updated count
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = ?");
            $cnt->execute([$postId]);
            $likesCount = (int)$cnt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'state' => $state,
                'likes_count' => $likesCount
            ]);
            exit;
        }

        // 3. Create Comment
        elseif ($action === 'create_comment') {
            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            $content = trim($input['content'] ?? $_POST['content'] ?? '');

            if ($postId <= 0 || $content === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post ID and comment content are required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_post_comments` (`post_id`, `user_id`, `content`)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$postId, $userId, $content]);
            $newCommentId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'create_comment', 'post_comments', $newCommentId, "User commented on post ID {$postId}.");

            echo json_encode([
                'status' => 'success',
                'message' => 'Comment posted successfully!',
                'comment_id' => $newCommentId
            ]);
            exit;
        }

        // 4. Report Post or Comment (Flagging)
        elseif ($action === 'report_item') {
            $postId = isset($input['post_id']) ? (int)$input['post_id'] : (isset($_POST['post_id']) ? (int)$_POST['post_id'] : null);
            $commentId = isset($input['comment_id']) ? (int)$input['comment_id'] : (isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : null);
            $reason = trim($input['reason'] ?? $_POST['reason'] ?? '');
            $details = trim($input['details'] ?? $_POST['details'] ?? '');

            if (!$postId && !$commentId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Must report a post or comment.']);
                exit;
            }

            if ($reason === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Reason for report is required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_community_reports` (`post_id`, `comment_id`, `reporter_id`, `reason`, `details`)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$postId ?: null, $commentId ?: null, $userId, $reason, $details]);
            $reportId = (int)$pdo->lastInsertId();

            // Set item status to flagged in DB
            if ($postId) {
                $up = $pdo->prepare("UPDATE `tbl_community_posts` SET `status` = 'flagged' WHERE `post_id` = ?");
                $up->execute([$postId]);
            } elseif ($commentId) {
                $up = $pdo->prepare("UPDATE `tbl_post_comments` SET `status` = 'flagged' WHERE `comment_id` = ?");
                $up->execute([$commentId]);
            }

            ab_add_audit_log($pdo, $userId, 'report_community_item', 'community_reports', $reportId, "User reported community item (Report ID: {$reportId})");

            echo json_encode([
                'status' => 'success',
                'message' => 'Item reported to moderators. Thank you!'
            ]);
            exit;
        }

        // ── ADMIN ONLY MODERATION ACTIONS ──────────────────────────
        
        // 5. Pin Post
        elseif ($action === 'pin_post') {
            if ($userRole !== 'admin') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin role required.']);
                exit;
            }

            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE `tbl_community_posts` SET `is_pinned` = 1 WHERE `post_id` = ?");
            $stmt->execute([$postId]);

            ab_add_audit_log($pdo, $userId, 'pin_post', 'community_posts', $postId, "Admin pinned community post ID {$postId}.");

            echo json_encode(['status' => 'success', 'message' => 'Post pinned to top successfully!']);
            exit;
        }

        // 6. Unpin Post
        elseif ($action === 'unpin_post') {
            if ($userRole !== 'admin') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin role required.']);
                exit;
            }

            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE `tbl_community_posts` SET `is_pinned` = 0 WHERE `post_id` = ?");
            $stmt->execute([$postId]);

            ab_add_audit_log($pdo, $userId, 'unpin_post', 'community_posts', $postId, "Admin unpinned community post ID {$postId}.");

            echo json_encode(['status' => 'success', 'message' => 'Post unpinned successfully!']);
            exit;
        }

        // 7. Delete Post (Allowed for Author or Admin)
        elseif ($action === 'delete_post') {
            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            if ($postId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post ID is required.']);
                exit;
            }

            // Fetch post author
            $chk = $pdo->prepare("SELECT `user_id` FROM `tbl_community_posts` WHERE `post_id` = ?");
            $chk->execute([$postId]);
            $authorId = $chk->fetchColumn();

            if ($authorId === false) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Post not found.']);
                exit;
            }

            if ($userRole !== 'admin' && (int)$authorId !== $userId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized. You can only delete your own posts.']);
                exit;
            }

            // Perform logical or physical deletion. For school projects, physical is clean.
            $del = $pdo->prepare("DELETE FROM `tbl_community_posts` WHERE `post_id` = ?");
            $del->execute([$postId]);

            ab_add_audit_log($pdo, $userId, 'delete_post', 'community_posts', $postId, "Community post ID {$postId} was deleted by user ID {$userId}.");

            echo json_encode(['status' => 'success', 'message' => 'Post deleted successfully!']);
            exit;
        }

        // 8. Delete Comment (Allowed for Author or Admin)
        elseif ($action === 'delete_comment') {
            $commentId = (int)($input['comment_id'] ?? $_POST['comment_id'] ?? 0);
            if ($commentId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Comment ID is required.']);
                exit;
            }

            // Fetch comment author
            $chk = $pdo->prepare("SELECT `user_id` FROM `tbl_post_comments` WHERE `comment_id` = ?");
            $chk->execute([$commentId]);
            $authorId = $chk->fetchColumn();

            if ($authorId === false) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Comment not found.']);
                exit;
            }

            if ($userRole !== 'admin' && (int)$authorId !== $userId) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized. You can only delete your own comments.']);
                exit;
            }

            $del = $pdo->prepare("DELETE FROM `tbl_post_comments` WHERE `comment_id` = ?");
            $del->execute([$commentId]);

            ab_add_audit_log($pdo, $userId, 'delete_comment', 'post_comments', $commentId, "Comment ID {$commentId} was deleted by user ID {$userId}.");

            echo json_encode(['status' => 'success', 'message' => 'Comment deleted successfully!']);
            exit;
        }

        // 9. Repost Post
        elseif ($action === 'repost_post') {
            $postId = (int)($input['post_id'] ?? $_POST['post_id'] ?? 0);
            $content = trim($input['content'] ?? $_POST['content'] ?? '');

            if ($postId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing original post ID.']);
                exit;
            }

            // Verify the original post exists and is active
            $chk = $pdo->prepare("SELECT `category` FROM `tbl_community_posts` WHERE `post_id` = ? AND `status` = 'active'");
            $chk->execute([$postId]);
            $category = $chk->fetchColumn();

            if ($category === false) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Original post not found or deleted.']);
                exit;
            }

            // Insert repost post (points to original post)
            $stmt = $pdo->prepare("
                INSERT INTO `tbl_community_posts` (`user_id`, `category`, `content`, `repost_of_id`)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $category, $content, $postId]);
            $newPostId = (int)$pdo->lastInsertId();

            ab_add_audit_log($pdo, $userId, 'repost_post', 'community_posts', $newPostId, "User reposted community post ID {$postId} via post ID {$newPostId}.");

            echo json_encode([
                'status' => 'success',
                'message' => 'Post reposted successfully!',
                'post_id' => $newPostId
            ]);
            exit;
        }

        // 10. Follow / Unfollow User
        elseif ($action === 'follow_user') {
            $targetUserId = (int)($input['target_user_id'] ?? $_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid or missing target user ID.']);
                exit;
            }

            if ($targetUserId === $userId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'You cannot follow yourself.']);
                exit;
            }

            // Check if target user exists and is active
            $chkUser = $pdo->prepare("SELECT COUNT(*) FROM `tbl_users` WHERE `user_id` = ? AND `is_active` = 1");
            $chkUser->execute([$targetUserId]);
            if ((int)$chkUser->fetchColumn() === 0) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Target user not found or inactive.']);
                exit;
            }

            // Check follow state
            $chk = $pdo->prepare("SELECT COUNT(*) FROM `tbl_user_follows` WHERE `follower_id` = ? AND `followed_id` = ?");
            $chk->execute([$userId, $targetUserId]);
            $alreadyFollowing = ((int)$chk->fetchColumn() > 0);

            if ($alreadyFollowing) {
                // Unfollow
                $del = $pdo->prepare("DELETE FROM `tbl_user_follows` WHERE `follower_id` = ? AND `followed_id` = ?");
                $del->execute([$userId, $targetUserId]);
                $state = 'unfollowed';
            } else {
                // Follow
                $ins = $pdo->prepare("INSERT INTO `tbl_user_follows` (`follower_id`, `followed_id`) VALUES (?, ?)");
                $ins->execute([$userId, $targetUserId]);
                $state = 'followed';
                
                // Add notification
                $userStmt = $pdo->prepare("SELECT `first_name`, `last_name` FROM `tbl_users` WHERE `user_id` = ?");
                $userStmt->execute([$userId]);
                $followerName = $userStmt->fetch();
                $followerFullName = $followerName ? ($followerName['first_name'] . ' ' . $followerName['last_name']) : 'A user';
                ab_add_notification($pdo, $targetUserId, 'New Follower! 👤', "{$followerFullName} started following you.", 'community.html');
            }

            // Get updated count
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM `tbl_user_follows` WHERE `followed_id` = ?");
            $cnt->execute([$targetUserId]);
            $followersCount = (int)$cnt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'state' => $state,
                'followers_count' => $followersCount
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.', 'detail' => $e->getMessage()]);
}
