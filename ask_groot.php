<?php
// ask_groot.php - Backend AI Bridge for FocusFlow Groot AI
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Groot DB Error: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = (int)($input['user_id'] ?? $input['userId'] ?? 1);
$queryType = trim($input['query_type'] ?? $input['queryType'] ?? 'general');
$userPrompt = trim($input['prompt'] ?? $input['message'] ?? '');

// 1. Query MySQL for the user's authentic metrics
// Total focus minutes today
$stmtToday = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (DATE(created_at) = CURDATE() OR DATE(completed_at) = CURDATE())");
$stmtToday->execute([':uid' => $userId]);
$focusToday = (int)$stmtToday->fetchColumn();

// Total focus minutes this week (last 7 days)
$stmtWeek = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))");
$stmtWeek->execute([':uid' => $userId]);
$focusWeek = (int)$stmtWeek->fetchColumn();

// Completed vs Pending tasks count
$stmtTasks = $conn->prepare("SELECT 
    COUNT(CASE WHEN is_completed = 1 THEN 1 END) AS completed_count,
    COUNT(CASE WHEN is_completed = 0 THEN 1 END) AS pending_count
FROM tasks WHERE user_id = :uid");
$stmtTasks->execute([':uid' => $userId]);
$taskMetrics = $stmtTasks->fetch();
$tasksCompleted = (int)($taskMetrics['completed_count'] ?? 0);
$tasksPending = (int)($taskMetrics['pending_count'] ?? 0);

// Active habits & streaks
$stmtHabits = $conn->prepare("SELECT COUNT(*) AS total_habits, COALESCE(MAX(streak_count), 0) AS max_streak FROM habits WHERE user_id = :uid");
$stmtHabits->execute([':uid' => $userId]);
$habitMetrics = $stmtHabits->fetch();
$totalHabits = (int)($habitMetrics['total_habits'] ?? 0);
$maxStreak = (int)($habitMetrics['max_streak'] ?? 0);

// Habits completed today
$stmtHabitsToday = $conn->prepare("SELECT COUNT(DISTINCT habit_id) FROM habit_logs WHERE user_id = :uid AND completed_date = CURDATE()");
$stmtHabitsToday->execute([':uid' => $userId]);
$habitsTodayDone = (int)$stmtHabitsToday->fetchColumn();

// Metrics summary string for AI
$metricsSummary = "Focus time today: {$focusToday} mins; Focus time past 7 days: {$focusWeek} mins; Tasks completed: {$tasksCompleted}; Pending tasks backlog: {$tasksPending}; Active habit streak: {$maxStreak} days; Today's habits finished: {$habitsTodayDone}/{$totalHabits}.";

// 2. Construct context-aware system & user prompt
$systemInstruction = "You are Groot, an intelligent, minimalist productivity coach for the FocusFlow app. Analyze the user's real productivity metrics: $metricsSummary. Give concise, actionable, and encouraging feedback in 2-3 sentences. Do not use emojis.";

$fullMessage = $systemInstruction;
if (!empty($userPrompt)) {
    $fullMessage .= "\nUser query: " . $userPrompt;
} else if ($queryType === 'habit_insights') {
    $fullMessage .= "\nAnalyze my habit consistency and provide 2-3 actionable sentences on compounding my daily routines.";
} else if ($queryType === 'daily_summary') {
    $fullMessage .= "\nProvide a brief daily summary of my focus time and tasks progress today.";
} else if ($queryType === 'focus_advice') {
    $fullMessage .= "\nProvide advice on optimizing my focus blocks and session rhythm based on my logged minutes.";
}

// 3. Attempt cURL forward to local fine-tuned model running on port 8000
$endpoints = [
    "http://127.0.0.1:8000/ask_groot" => json_encode(["message" => $fullMessage]),
    "http://127.0.0.1:8000/v1/chat/completions" => json_encode([
        "messages" => [
            ["role" => "system", "content" => $systemInstruction],
            ["role" => "user", "content" => !empty($userPrompt) ? $userPrompt : "Analyze my productivity metrics."]
        ]
    ]),
    "http://127.0.0.1:8000/generate" => json_encode(["prompt" => $fullMessage])
];

$aiResponseText = null;
foreach ($endpoints as $url => $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($raw)) {
        $jsonDec = json_decode($raw, true);
        if (!empty($jsonDec['reply'])) {
            $aiResponseText = trim($jsonDec['reply']);
            break;
        } elseif (!empty($jsonDec['insight'])) {
            $aiResponseText = trim($jsonDec['insight']);
            break;
        } elseif (!empty($jsonDec['choices'][0]['message']['content'])) {
            $aiResponseText = trim($jsonDec['choices'][0]['message']['content']);
            break;
        }
    } else {
        // If connection refused on first endpoint, port 8000 is offline; skip the rest
        if ($httpCode === 0) {
            break;
        }
    }
}

// 4. If local model server on port 8000 is unreachable or returned empty,
// generate an authentic rule-based coaching insight based directly on the user's real DB stats!
if (empty($aiResponseText)) {
    if ($queryType === 'habit_insights') {
        if ($maxStreak > 0) {
            $aiResponseText = "You have maintained an active habit streak of {$maxStreak} days with {$habitsTodayDone} of {$totalHabits} daily routines completed today. Keep compounding this consistency to lock in your long-term focus.";
        } else {
            $aiResponseText = "You have {$totalHabits} active habits set up with {$habitsTodayDone} completed today. Checking off your primary routine today will start your streak and build lasting momentum.";
        }
    } elseif ($queryType === 'focus_advice') {
        if ($focusToday >= 120) {
            $aiResponseText = "You have logged {$focusToday} minutes of deep focus today across your sessions. Consider a short 10-minute breather to recharge cognitive stamina before tackling your next task.";
        } elseif ($focusToday > 0) {
            $aiResponseText = "You have logged {$focusToday} focus minutes today towards your goal ({$focusWeek} minutes over the past week). Launching a 25-minute Pomodoro session will push you into deep flow.";
        } else {
            $aiResponseText = "No focus sessions recorded yet today ({$focusWeek} minutes logged this week). Starting with a single 25-minute deep focus block will break procrastination and build flow.";
        }
    } else {
        // General / daily summary
        if ($tasksPending > 0) {
            $aiResponseText = "You have logged {$focusToday} minutes of focus today and completed {$tasksCompleted} tasks, with {$tasksPending} pending in your backlog. Focus on knocking out your highest priority task next.";
        } else {
            $aiResponseText = "All your current tasks are completed with {$focusToday} focus minutes logged today and {$maxStreak} days on your habit streak. Excellent execution on maintaining clear focus.";
        }
    }
}

// Strictly purge any emojis from AI output
$aiResponseText = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $aiResponseText);
$aiResponseText = trim($aiResponseText);

// Return response matching required JSON schema
echo json_encode([
    "status" => "success",
    "success" => true,
    "insight" => $aiResponseText,
    "message" => $aiResponseText,
    "metrics" => [
        "focus_today_mins" => $focusToday,
        "focus_week_mins" => $focusWeek,
        "tasks_completed" => $tasksCompleted,
        "tasks_pending" => $tasksPending,
        "habit_streak_days" => $maxStreak,
        "habits_today_done" => $habitsTodayDone,
        "total_habits" => $totalHabits
    ],
    "timestamp" => date('Y-m-d H:i:s')
]);
exit;
