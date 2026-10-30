<?php
// get_dashboard.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

// Fast migration cache check to eliminate schema catalog locking on every API hit
$migrationFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow_migrated_v3';
if (!file_exists($migrationFlag)) {
    try {
        runAllMigrations($conn);
        @touch($migrationFlag);
    } catch (PDOException $e) {
        error_log("Dashboard Migration Error: " . $e->getMessage());
    }
}

$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 1);

try {
    // 1. Get user name & avatar
    $uStmt = $conn->prepare("SELECT name, avatar FROM users WHERE id = :id LIMIT 1");
    $uStmt->execute([':id' => $userId]);
    $user = $uStmt->fetch();
    $userName = $user ? $user['name'] : "Sri Sai";
    $userAvatar = $user ? ($user['avatar'] ?? '') : "";

    // 2. Get today's total focus minutes
    $focusStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :uid AND DATE(created_at) = CURDATE()
    ");
    $focusStmt->execute([':uid' => $userId]);
    $focusMinutesToday = (int)$focusStmt->fetchColumn();

    // 3. Get user's daily goal minutes from preferences
    $prefStmt = $conn->prepare("SELECT daily_focus_goal_minutes FROM user_preferences WHERE user_id = :uid LIMIT 1");
    $prefStmt->execute([':uid' => $userId]);
    $goalMinutes = (int)($prefStmt->fetchColumn() ?: 240); // default 4 hrs = 240 mins

    // 4. Pending task count & completed task count
    $pendingStmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = :uid AND is_completed = 0");
    $pendingStmt->execute([':uid' => $userId]);
    $pendingTaskCount = (int)$pendingStmt->fetchColumn();

    $completedStmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = :uid AND is_completed = 1");
    $completedStmt->execute([':uid' => $userId]);
    $completedTaskCount = (int)$completedStmt->fetchColumn();

    // 5. Top 3 priority tasks
    $tasksStmt = $conn->prepare("
        SELECT id, user_id, title, description, category, priority, due_date, is_completed
        FROM tasks
        WHERE user_id = :uid AND is_completed = 0
        ORDER BY FIELD(priority, 'high', 'medium', 'low'), id DESC
        LIMIT 3
    ");
    $tasksStmt->execute([':uid' => $userId]);
    $priorityTasks = array_map(function($t) {
        return [
            "id" => (int)$t['id'],
            "user_id" => (int)$t['user_id'],
            "title" => $t['title'],
            "description" => $t['description'] ?: '',
            "category" => $t['category'] ?: 'General',
            "priority" => $t['priority'] ?: 'medium',
            "due_date" => $t['due_date'] ?: date('Y-m-d'),
            "is_completed" => (bool)$t['is_completed']
        ];
    }, $tasksStmt->fetchAll());

    // 6. Habits & today's status
    $habitsStmt = $conn->prepare("
        SELECT h.id, h.user_id, h.title, h.icon, h.frequency, h.streak_count,
               CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END AS is_completed_today
        FROM habits h
        LEFT JOIN habit_logs l ON h.id = l.habit_id AND l.completed_date = CURDATE()
        WHERE h.user_id = :uid
        ORDER BY h.id ASC
    ");
    $habitsStmt->execute([':uid' => $userId]);
    $habits = array_map(function($h) {
        return [
            "id" => (int)$h['id'],
            "user_id" => (int)$h['user_id'],
            "title" => $h['title'],
            "icon" => $h['icon'] ?: 'bolt',
            "frequency" => $h['frequency'],
            "streak_count" => (int)$h['streak_count'],
            "is_completed_today" => (bool)$h['is_completed_today']
        ];
    }, $habitsStmt->fetchAll());

    // 7. Calculate focus streak (consecutive days with focus sessions)
    $streakStmt = $conn->prepare("
        SELECT COUNT(DISTINCT DATE(created_at))
        FROM focus_sessions
        WHERE user_id = :uid AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $streakStmt->execute([':uid' => $userId]);
    $streakDays = max(1, (int)$streakStmt->fetchColumn());

    // 8. Longest streak, total habits completed, total focus minutes for streak modal
    $longestStreakStmt = $conn->prepare("SELECT COALESCE(MAX(streak_count), 0) FROM habits WHERE user_id = :uid");
    $longestStreakStmt->execute([':uid' => $userId]);
    $longestStreak = max($streakDays, (int)$longestStreakStmt->fetchColumn());

    $habitsCompletedStmt = $conn->prepare("SELECT COUNT(*) FROM habit_logs WHERE user_id = :uid");
    $habitsCompletedStmt->execute([':uid' => $userId]);
    $totalHabitsCompleted = (int)$habitsCompletedStmt->fetchColumn();

    $totalFocusStmt = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid");
    $totalFocusStmt->execute([':uid' => $userId]);
    $totalFocusMinutes = (int)$totalFocusStmt->fetchColumn();

    echo json_encode([
        "status" => true,
        "data" => [
            "user_name" => $userName,
            "user_avatar" => $userAvatar,
            "focus_minutes_today" => $focusMinutesToday,
            "focus_goal_minutes" => $goalMinutes,
            "pending_task_count" => $pendingTaskCount,
            "completed_task_count" => $completedTaskCount,
            "streak_days" => $streakDays,
            "longest_streak" => $longestStreak,
            "total_habits_completed" => $totalHabitsCompleted,
            "total_focus_minutes" => $totalFocusMinutes,
            "priority_tasks" => $priorityTasks,
            "habits" => $habits
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
