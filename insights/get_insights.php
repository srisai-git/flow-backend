<?php
// insights/get_insights.php
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
    error_log("Insights Migration Error: " . $e->getMessage());
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
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Fetch daily goal from user_preferences (fallback default: 210 mins)
    $prefStmt = $conn->prepare("SELECT daily_focus_goal_minutes FROM user_preferences WHERE user_id = :user_id LIMIT 1");
    $prefStmt->execute([':user_id' => $userId]);
    $prefRow = $prefStmt->fetch();
    $dailyGoalMinutes = ($prefRow && !empty($prefRow['daily_focus_goal_minutes'])) ? (int)$prefRow['daily_focus_goal_minutes'] : 210;

    // 3. Calculate Today's Focus Minutes
    $todayStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0) AS today_minutes
        FROM focus_sessions
        WHERE user_id = :user_id
          AND status = 'completed'
          AND DATE(started_at) = CURRENT_DATE()
    ");
    $todayStmt->execute([':user_id' => $userId]);
    $todayFocusMinutes = (int)$todayStmt->fetchColumn();

    // 4. Calculate Week's Focus Minutes (Rolling last 7 days)
    $weekStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0) AS week_minutes
        FROM focus_sessions
        WHERE user_id = :user_id
          AND status = 'completed'
          AND started_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
    ");
    $weekStmt->execute([':user_id' => $userId]);
    $weekFocusMinutes = (int)$weekStmt->fetchColumn();

    // 5. Daily Goal Progress Percent
    $progressPercent = ($dailyGoalMinutes > 0)
        ? round(min(100.0, ($todayFocusMinutes / $dailyGoalMinutes) * 100), 1)
        : 0.0;

    // 6. Calculate Consecutive Streak Days
    $streakStmt = $conn->prepare("
        SELECT DISTINCT DATE(started_at) AS session_date
        FROM focus_sessions
        WHERE user_id = :user_id AND status = 'completed'
        ORDER BY session_date DESC
    ");
    $streakStmt->execute([':user_id' => $userId]);
    $sessionDates = $streakStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $dateLookup = array_flip($sessionDates);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $streakDays = 0;
    $pointer = null;

    if (isset($dateLookup[$today])) {
        $pointer = strtotime($today);
    } elseif (isset($dateLookup[$yesterday])) {
        $pointer = strtotime($yesterday);
    }

    if ($pointer !== null) {
        while (true) {
            $formatted = date('Y-m-d', $pointer);
            if (isset($dateLookup[$formatted])) {
                $streakDays++;
                $pointer = strtotime('-1 day', $pointer);
            } else {
                break;
            }
        }
    }

    // 7. Tasks Summary
    $tasksCountStmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_tasks,
            COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_tasks,
            COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS pending_tasks
        FROM tasks
        WHERE user_id = :user_id
    ");
    $tasksCountStmt->execute([':user_id' => $userId]);
    $taskCounts = $tasksCountStmt->fetch();

    $totalTasks     = (int)$taskCounts['total_tasks'];
    $completedTasks = (int)$taskCounts['completed_tasks'];
    $pendingTasks   = (int)$taskCounts['pending_tasks'];
    $completionRate = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

    // 8. Daily Chart Data (Last 7 days, zero-filled)
    $chartStmt = $conn->prepare("
        SELECT DATE(started_at) AS day_date, SUM(duration_minutes) AS total_min
        FROM focus_sessions
        WHERE user_id = :user_id 
          AND status = 'completed'
          AND started_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
        GROUP BY DATE(started_at)
    ");
    $chartStmt->execute([':user_id' => $userId]);
    $chartRows = $chartStmt->fetchAll();

    $dayTotals = [];
    foreach ($chartRows as $cr) {
        $dayTotals[$cr['day_date']] = (int)$cr['total_min'];
    }

    $dailyChartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));
        $dailyChartData[] = [
            "date"          => $d,
            "day"           => $dayName,
            "focus_minutes" => $dayTotals[$d] ?? 0
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Insights aggregated successfully.",
        "data"    => [
            "today_focus_minutes"         => $todayFocusMinutes,
            "week_focus_minutes"          => $weekFocusMinutes,
            "daily_focus_goal_minutes"    => $dailyGoalMinutes,
            "daily_goal_progress_percent" => $progressPercent,
            "current_streak_days"         => $streakDays,
            "tasks_summary"               => [
                "total_tasks"             => $totalTasks,
                "pending_tasks"           => $pendingTasks,
                "completed_tasks"         => $completedTasks,
                "completion_rate_percent" => $completionRate
            ],
            "daily_chart_data"            => $dailyChartData
        ]
    ]);
} catch (PDOException $e) {
    error_log("Get Insights Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to aggregate insights."]);
}

