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
        // Auth is required to view the feed
        $currentUserId = ab_require_auth();

        // Fetch posts
        // We select the author info by joining with tbl_users
        $postsStmt = $pdo->prepare("
            SELECT 
                p.`post_id`, p.`user_id`, p.`category`, p.`content`, p.`is_pinned`, p.`status`, p.`created_at`,
                u.`first_name`, u.`last_name`, u.`profile_photo` AS `avatar_url`, u.`role` AS `user_role`,
                (SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = p.`post_id`) AS `likes_count`,
                (SELECT COUNT(*) FROM `tbl_post_likes` WHERE `post_id` = p.`post_id` AND `user_id` = ?) AS `user_has_liked`
            FROM `tbl_community_posts` p
            INNER JOIN `tbl_users` u ON u.`user_id` = p.`user_id`
            WHERE p.`status` = 'active'
            ORDER BY p.`is_pinned` DESC, p.`created_at` DESC
        ");
        $postsStmt->execute([$currentUserId]);
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
        $currentUserRole = $_SESSION['role'] ?? 'client';

        foreach ($posts as $p) {
            $postId = (int)$p['post_id'];
            $feed[] = [
                'post_id' => $postId,
                'user_id' => (int)$p['user_id'],
                'author_name' => $p['first_name'] . ' ' . $p['last_name'],
                'user_role' => $p['user_role'],
                'avatar_url' => $p['avatar_url'],
                'category' => $p['category'],
                'content' => $p['content'],
                'is_pinned' => (int)$p['is_pinned'] === 1,
                'likes_count' => (int)$p['likes_count'],
                'user_has_liked' => (int)$p['user_has_liked'] === 1,
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

            if ($content === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Post content cannot be empty.']);
                exit;
            }

            $allowedCategories = ['General', 'Booking Tips', 'Social Hangout', 'Safety Alert'];
            if (!in_array($category, $allowedCategories)) {
                $category = 'General';
            }

            $stmt = $pdo->prepare("
                INSERT INTO `tbl_community_posts` (`user_id`, `category`, `content`)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $category, $content]);
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

        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.', 'detail' => $e->getMessage()]);
}
