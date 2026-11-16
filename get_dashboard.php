<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {}

$userId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 1);

try {
    $uStmt = $conn->prepare("SELECT name, avatar FROM users WHERE id = :id LIMIT 1");
    $uStmt->execute([':id' => $userId]);
    $user = $uStmt->fetch();
    $userName = $user ? $user['name'] : "Sri Sai";
    $userAvatar = $user ? ($user['avatar'] ?? '') : "";

    $focusStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :uid AND status = 'completed' AND created_at >= CURDATE()
    ");
    $focusStmt->execute([':uid' => $userId]);
    $focusMinutesToday = (int)$focusStmt->fetchColumn();

    $prefStmt = $conn->prepare("SELECT daily_focus_goal_minutes FROM user_preferences WHERE user_id = :uid LIMIT 1");
    $prefStmt->execute([':uid' => $userId]);
    $goalMinutes = (int)($prefStmt->fetchColumn() ?: 240);

    $taskCountsStmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_count
        FROM tasks
        WHERE user_id = :uid
    ");
    $taskCountsStmt->execute([':uid' => $userId]);
    $taskCounts = $taskCountsStmt->fetch(PDO::FETCH_ASSOC);
    $pendingTaskCount = (int)($taskCounts['pending_count'] ?? 0);
    $completedTaskCount = (int)($taskCounts['completed_count'] ?? 0);

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

    $streakStmt = $conn->prepare("
        SELECT COUNT(DISTINCT DATE(created_at))
        FROM focus_sessions
        WHERE user_id = :uid AND status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $streakStmt->execute([':uid' => $userId]);
    $streakDays = (int)$streakStmt->fetchColumn();

    $longestStreak = max(1, $streakDays);
    foreach ($habits as $h) {
        if ($h['streak_count'] > $longestStreak) {
            $longestStreak = $h['streak_count'];
        }
    }

    $totHabitsStmt = $conn->prepare("SELECT COUNT(*) FROM habit_logs WHERE user_id = :uid");
    $totHabitsStmt->execute([':uid' => $userId]);
    $totalHabitsCompleted = (int)$totHabitsStmt->fetchColumn();

    $totFocusStmt = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed'");
    $totFocusStmt->execute([':uid' => $userId]);
    $totalFocusMinutes = (int)$totFocusStmt->fetchColumn();

    sendJson([
        "status" => true,
        "data" => [
            "user_name" => $userName,
            "user_avatar" => $userAvatar,
            "focus_minutes_today" => $focusMinutesToday,
            "focus_goal_minutes" => $goalMinutes,
            "pending_task_count" => $pendingTaskCount,
            "completed_task_count" => $completedTaskCount,
            "streak_days" => max(1, $streakDays),
            "longest_streak" => $longestStreak,
            "total_habits_completed" => $totalHabitsCompleted,
            "total_focus_minutes" => $totalFocusMinutes,
            "priority_tasks" => $priorityTasks,
            "habits" => $habits
        ]
    ]);
} catch (PDOException $e) {
    sendJson([
        "status" => false,
        "message" => "Database error: " . $e->getMessage()
    ], 500);
}
