<?php
// workspace/get_workspace.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/migrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use GET or POST."]);
    exit;
}

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Workspace Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_POST['id'] ?? $jsonInput['user_id'] ?? $jsonInput['id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;

try {
    // 1. Fetch User Profile
    $userStmt = $conn->prepare("SELECT id, name, email, avatar, bio FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Fetch Routine Preferences (with defaults fallback)
    $prefStmt = $conn->prepare("
        SELECT primary_goal, preferred_focus_duration, daily_focus_goal_minutes, reminders_enabled, reminder_time, updated_at
        FROM user_preferences
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $prefStmt->execute([':user_id' => $userId]);
    $pref = $prefStmt->fetch();

    $routinePreferences = [
        "primary_goal"             => $pref['primary_goal'] ?? "Study",
        "preferred_focus_duration" => isset($pref['preferred_focus_duration']) ? (int)$pref['preferred_focus_duration'] : 45,
        "daily_focus_goal_minutes" => isset($pref['daily_focus_goal_minutes']) ? (int)$pref['daily_focus_goal_minutes'] : 210,
        "reminders_enabled"        => isset($pref['reminders_enabled']) ? (int)$pref['reminders_enabled'] : 1,
        "reminder_time"            => $pref['reminder_time'] ?? "09:00 AM",
        "is_default"               => ($pref === false)
    ];

    // 3. Fetch App Settings (with defaults fallback)
    $settingsStmt = $conn->prepare("
        SELECT theme, sound_enabled, vibration_enabled, groot_suggestions_enabled, updated_at
        FROM app_settings
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $settingsStmt->execute([':user_id' => $userId]);
    $settings = $settingsStmt->fetch();

    $appSettings = [
        "theme"                     => $settings['theme'] ?? "system",
        "sound_enabled"             => isset($settings['sound_enabled']) ? (int)$settings['sound_enabled'] : 1,
        "vibration_enabled"         => isset($settings['vibration_enabled']) ? (int)$settings['vibration_enabled'] : 1,
        "groot_suggestions_enabled" => isset($settings['groot_suggestions_enabled']) ? (int)$settings['groot_suggestions_enabled'] : 1
    ];

    // 4. Calculate Today's Summary
    $todayFocusStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :user_id AND status = 'completed' AND DATE(started_at) = CURRENT_DATE()
    ");
    $todayFocusStmt->execute([':user_id' => $userId]);
    $todayFocusMin = (int)$todayFocusStmt->fetchColumn();

    $taskCountsStmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_count,
            COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_count
        FROM tasks
        WHERE user_id = :user_id
    ");
    $taskCountsStmt->execute([':user_id' => $userId]);
    $taskCounts = $taskCountsStmt->fetch();

    $dailyGoal = $routinePreferences['daily_focus_goal_minutes'];
    $progressPercent = ($dailyGoal > 0) ? round(min(100.0, ($todayFocusMin / $dailyGoal) * 100), 1) : 0.0;

    $todaySummary = [
        "today_focus_minutes"      => $todayFocusMin,
        "daily_focus_goal_minutes" => $dailyGoal,
        "progress_percent"         => $progressPercent,
        "pending_tasks_count"      => (int)$taskCounts['pending_count'],
        "completed_tasks_count"    => (int)$taskCounts['completed_count'],
        "total_tasks_count"        => (int)$taskCounts['total_count']
    ];

    // 5. Recent Tasks (Top 5 pending / upcoming)
    $tasksStmt = $conn->prepare("
        SELECT id, user_id, title, description, category, priority, due_date, is_completed, created_at
        FROM tasks
        WHERE user_id = :user_id AND is_completed = 0
        ORDER BY due_date IS NULL ASC, due_date ASC, created_at DESC
        LIMIT 5
    ");
    $tasksStmt->execute([':user_id' => $userId]);
    $recentTasks = [];
    foreach ($tasksStmt->fetchAll() as $t) {
        $recentTasks[] = [
            "id"           => (int)$t['id'],
            "user_id"      => (int)$t['user_id'],
            "title"        => $t['title'],
            "description"  => $t['description'] ?? '',
            "category"     => $t['category'],
            "priority"     => $t['priority'],
            "due_date"     => $t['due_date'],
            "is_completed" => (int)$t['is_completed'],
            "created_at"   => $t['created_at']
        ];
    }

    // 6. Recent Focus Sessions (Top 3)
    $sessionsStmt = $conn->prepare("
        SELECT f.id, f.user_id, f.task_id, t.title AS task_title, f.duration_minutes,
               f.session_type, f.status, f.started_at, f.completed_at
        FROM focus_sessions f
        LEFT JOIN tasks t ON t.id = f.task_id
        WHERE f.user_id = :user_id
        ORDER BY f.started_at DESC
        LIMIT 3
    ");
    $sessionsStmt->execute([':user_id' => $userId]);
    $recentSessions = [];
    foreach ($sessionsStmt->fetchAll() as $s) {
        $recentSessions[] = [
            "id"               => (int)$s['id'],
            "user_id"          => (int)$s['user_id'],
            "task_id"          => $s['task_id'] !== null ? (int)$s['task_id'] : null,
            "task_title"       => $s['task_title'] ?? null,
            "duration_minutes" => (int)$s['duration_minutes'],
            "session_type"     => $s['session_type'],
            "status"           => $s['status'],
            "started_at"       => $s['started_at'],
            "completed_at"     => $s['completed_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Workspace dashboard loaded successfully.",
        "data"    => [
            "user"                => [
                "id"     => (int)$user['id'],
                "name"   => $user['name'] ?? '',
                "email"  => $user['email'] ?? '',
                "avatar" => $user['avatar'] ?? '',
                "bio"    => $user['bio'] ?? ''
            ],
            "routine_preferences" => $routinePreferences,
            "app_settings"        => $appSettings,
            "today_summary"       => $todaySummary,
            "recent_tasks"        => $recentTasks,
            "recent_sessions"     => $recentSessions
        ]
    ]);
} catch (PDOException $e) {
    error_log("Get Workspace Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to load workspace dashboard."]);
}

