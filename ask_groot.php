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
$stmtToday = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (DATE(created_at) = CURDATE() OR DATE(completed_at) = CURDATE())");
$stmtToday->execute([':uid' => $userId]);
$focusToday = (int)$stmtToday->fetchColumn();

$stmtWeek = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))");
$stmtWeek->execute([':uid' => $userId]);
$focusWeek = (int)$stmtWeek->fetchColumn();

$stmtTasks = $conn->prepare("SELECT 
    COUNT(CASE WHEN is_completed = 1 THEN 1 END) AS completed_count,
    COUNT(CASE WHEN is_completed = 0 THEN 1 END) AS pending_count
FROM tasks WHERE user_id = :uid");
$stmtTasks->execute([':uid' => $userId]);
$taskMetrics = $stmtTasks->fetch();
$tasksCompleted = (int)($taskMetrics['completed_count'] ?? 0);
$tasksPending = (int)($taskMetrics['pending_count'] ?? 0);

$stmtHabits = $conn->prepare("SELECT COUNT(*) AS total_habits, COALESCE(MAX(streak_count), 0) AS max_streak FROM habits WHERE user_id = :uid");
$stmtHabits->execute([':uid' => $userId]);
$habitMetrics = $stmtHabits->fetch();
$totalHabits = (int)($habitMetrics['total_habits'] ?? 0);
$maxStreak = (int)($habitMetrics['max_streak'] ?? 0);

$stmtHabitsToday = $conn->prepare("SELECT COUNT(DISTINCT habit_id) FROM habit_logs WHERE user_id = :uid AND completed_date = CURDATE()");
$stmtHabitsToday->execute([':uid' => $userId]);
$habitsTodayDone = (int)$stmtHabitsToday->fetchColumn();

$metricsSummary = "Focus time today: {$focusToday} mins; Focus time past 7 days: {$focusWeek} mins; Tasks completed: {$tasksCompleted}; Pending tasks backlog: {$tasksPending}; Active habit streak: {$maxStreak} days; Today's habits finished: {$habitsTodayDone}/{$totalHabits}.";

// 2. Official System Prompt
$systemInstruction = "You are Groot, an intelligent, versatile, and thoughtful AI assistant integrated into FocusFlow. You can engage naturally in conversation, assist with coding, answer technical and academic questions, and provide productivity coaching. Answer clearly, concisely, and insightfully. If you are uncertain or do not know the answer to a question, clearly and honestly state that you do not know rather than fabricating information. Never use emojis.";
$metricsContext = "User background focus metrics: $metricsSummary";

$fullMessage = $systemInstruction . "\n\n" . $metricsContext;
if (!empty($userPrompt)) {
    $fullMessage .= "\n\nUser Question/Message: " . $userPrompt;
} elseif ($queryType === 'habit_insights') {
    $fullMessage .= "\n\nUser Question/Message: Analyze my habit consistency and provide 2-3 actionable sentences on compounding my daily routines.";
} elseif ($queryType === 'daily_summary') {
    $fullMessage .= "\n\nUser Question/Message: Provide a brief daily summary of my focus time and tasks progress today.";
} elseif ($queryType === 'focus_advice') {
    $fullMessage .= "\n\nUser Question/Message: Provide advice on optimizing my focus blocks and session rhythm based on my logged minutes.";
} else {
    $fullMessage .= "\n\nUser Question/Message: Hello Groot, provide a brief focus check-in.";
}

// 3. Attempt cURL forward to local fine-tuned model running on port 8000
$effectivePrompt = !empty($userPrompt) ? $userPrompt : "Analyze my productivity metrics.";
$endpoints = [
    "http://127.0.0.1:8000/ask_groot" => json_encode(["message" => $fullMessage, "prompt" => $fullMessage]),
    "http://127.0.0.1:8000/v1/chat/completions" => json_encode([
        "messages" => [
            ["role" => "system", "content" => $systemInstruction . "\n\n" . $metricsContext],
            ["role" => "user", "content" => $effectivePrompt]
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
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
        } elseif (!empty($jsonDec['response'])) {
            $aiResponseText = trim($jsonDec['response']);
            break;
        } elseif (!empty($jsonDec['choices'][0]['message']['content'])) {
            $aiResponseText = trim($jsonDec['choices'][0]['message']['content']);
            break;
        }
    } else {
        if ($httpCode === 0) {
            break;
        }
    }
}

// 4. Intelligent, grounded fallback if local port 8000 is unavailable
if (empty($aiResponseText)) {
    $lowerPrompt = strtolower($userPrompt);

    // Conversational & Greeting Handlers
    if (preg_match('/^(hi|hello|hey|greetings|good\s*(morning|afternoon|evening|day))[\s!.]*$/i', $lowerPrompt)) {
        $aiResponseText = "Hello. I am Groot, your AI assistant in FocusFlow. How can I assist you with your focus, tasks, or inquiries today?";
    } elseif (preg_match('/(who are you|what are you|what is your name|introduce yourself)/i', $lowerPrompt)) {
        $aiResponseText = "I am Groot, an intelligent, versatile assistant integrated into FocusFlow. I can assist with problem solving, coding, technical and academic concepts, or analyze your focus sessions and habit consistency.";
    } elseif (preg_match('/(what can you do|help me with|features)/i', $lowerPrompt)) {
        $aiResponseText = "I can assist across a variety of disciplines: answering technical and academic questions, debugging code, breaking down projects into actionable tasks, and analyzing your FocusFlow sessions and habit streaks.";
    } elseif ($queryType === 'habit_insights') {
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
    } elseif ($queryType === 'daily_summary') {
        if ($tasksPending > 0) {
            $aiResponseText = "You have logged {$focusToday} minutes of focus today and completed {$tasksCompleted} tasks, with {$tasksPending} pending in your backlog. Focus on knocking out your highest priority task next.";
        } else {
            $aiResponseText = "All your current tasks are completed with {$focusToday} focus minutes logged today and {$maxStreak} days on your habit streak. Excellent execution on maintaining clear focus.";
        }
    } else {
        // Natural response for custom queries
        if (!empty($userPrompt)) {
            $aiResponseText = "Regarding \"{$userPrompt}\": Break the objective into concrete, verifiable steps and eliminate context switching. To tackle this effectively, consider initiating a 25-minute uninterrupted focus block.";
        } else {
            $aiResponseText = "I am ready. Tell me what topic, task, or question you would like to work on.";
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
    "response" => $aiResponseText,
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
