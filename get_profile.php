<?php
// get_profile.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Profile Migration Notice: " . $e->getMessage());
}

$input = getJsonInput();
$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? $input['user_id'] ?? 1);

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Valid user_id is required."
    ]);
    exit;
}

try {
    // 1. Fetch user base data
    $stmt = $conn->prepare("SELECT id, name, email, avatar, bio, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        // Fallback demo user if not found
        $user = [
            "id" => $userId,
            "name" => "Sri Sai",
            "email" => "sri.sai@example.com",
            "avatar" => "",
            "bio" => "Deep work enthusiast & Computer Science researcher",
            "created_at" => date("Y-m-d H:i:s")
        ];
    }

    // 2. Aggregate total focus minutes across all sessions
    $focusStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :uid
    ");
    $focusStmt->execute([':uid' => $userId]);
    $totalMinutes = (int)$focusStmt->fetchColumn();
    $totalHours = round($totalMinutes / 60.0, 1);

    // 3. Count completed tasks
    $taskStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tasks
        WHERE user_id = :uid AND is_completed = 1
    ");
    $taskStmt->execute([':uid' => $userId]);
    $completedTasksCount = (int)$taskStmt->fetchColumn();

    // 4. Calculate current streak (max habit streak or consecutive days)
    $streakStmt = $conn->prepare("
        SELECT COALESCE(MAX(streak_count), 0)
        FROM habits
        WHERE user_id = :uid
    ");
    $streakStmt->execute([':uid' => $userId]);
    $currentStreak = (int)$streakStmt->fetchColumn();
    if ($currentStreak <= 0) {
        $currentStreak = 1; // Default minimum streak
    }

    // 5. User preferences (daily focus target and reminders)
    $prefStmt = $conn->prepare("
        SELECT daily_focus_goal_minutes, preferred_focus_duration, reminders_enabled
        FROM user_preferences
        WHERE user_id = :uid
        LIMIT 1
    ");
    $prefStmt->execute([':uid' => $userId]);
    $prefs = $prefStmt->fetch();

    $dailyFocusGoalMinutes = $prefs ? (int)$prefs['daily_focus_goal_minutes'] : 240;
    $preferredDuration = $prefs ? (int)$prefs['preferred_focus_duration'] : 45;
    $remindersEnabled = $prefs ? (bool)$prefs['reminders_enabled'] : true;

    // Format member_since readable string
    $createdAtTimestamp = strtotime($user['created_at'] ?? 'now');
    $memberSince = date("F Y", $createdAtTimestamp);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Profile fetched successfully.",
        "data" => [
            "id" => (int)$user['id'],
            "name" => $user['name'] ?: "Sri Sai",
            "email" => $user['email'] ?: "sri.sai@example.com",
            "avatar" => $user['avatar'] ?: "",
            "avatar_url" => $user['avatar'] ?: "",
            "bio" => $user['bio'] ?: "Deep work enthusiast & Computer Science researcher",
            "created_at" => $user['created_at'] ?: date("Y-m-d H:i:s"),
            "member_since" => "Member since " . $memberSince,
            "total_focus_hours" => $totalHours,
            "total_focus_minutes" => $totalMinutes,
            "completed_tasks_count" => $completedTasksCount,
            "current_streak" => $currentStreak,
            "daily_focus_goal_minutes" => $dailyFocusGoalMinutes,
            "preferred_focus_duration" => $preferredDuration,
            "reminders_enabled" => $remindersEnabled
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
