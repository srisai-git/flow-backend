<?php
// ask_groot.php - Autonomous In-App Agent & Conversational AI Proxy Bridge
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config/db.php';

$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$prompt = trim($input['prompt'] ?? $input['message'] ?? $input['query'] ?? '');
$userId = intval($input['user_id'] ?? $input['userId'] ?? 1);
$history = isset($input['history']) && is_array($input['history']) ? $input['history'] : [];

if (empty($prompt)) {
    echo json_encode([
        "status" => "error",
        "insight" => "Please ask a question or command Groot to perform an action.",
        "message" => "Empty prompt"
    ]);
    exit();
}

$lowerPrompt = strtolower($prompt);

// Guardrail check: strictly intercept physical/biological action queries
if (preg_match('/\b(sing|singing|song|eat|eating|food|sleep|sleeping|sleepy|run|running|dance|dancing|walk|walking|cook|cooking|drink|drinking)\b/i', $prompt)) {
    echo json_encode([
        "status" => "success",
        "insight" => "As a digital assistant in FocusFlow, I can't sing or eat, but I can help you focus, manage your habits, or set your next timer.",
        "message" => "As a digital assistant in FocusFlow, I can't sing or eat, but I can help you focus, manage your habits, or set your next timer.",
        "action" => null
    ]);
    exit();
}

// Guardrail check: immediately intercept off-topic or pop culture queries
if (preg_match('/\b(rajini|rajinikanth|actor|actress|movie|cinema|film|celebrity|hero|director|cricket|football|fifa|ipl|messi|ronaldo|politician|politics|president|minister|song|bollywood|hollywood|kollywood|tollywood)\b/i', $prompt)) {
    echo json_encode([
        "status" => "success",
        "insight" => "I'm dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?",
        "message" => "I'm dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?",
        "action" => null
    ]);
    exit();
}

// 1. Gather real user metrics if query is productivity-related
$isProductivityQuery = preg_match('/\b(focus|habit|habits|streak|streaks|task|tasks|stat|stats|metric|metrics|progress|routine|sessions?|today)\b/i', $lowerPrompt);

$productivityContext = "";
if ($isProductivityQuery && $userId > 0 && isset($conn)) {
    try {
        $stmtFocus = $conn->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN (DATE(created_at) = CURDATE() OR DATE(completed_at) = CURDATE()) THEN duration_minutes ELSE 0 END), 0) AS focus_today,
                COALESCE(SUM(CASE WHEN (created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) THEN duration_minutes ELSE 0 END), 0) AS focus_week
            FROM focus_sessions
            WHERE user_id = :uid AND status = 'completed'
        ");
        $stmtFocus->execute([':uid' => $userId]);
        $fMetrics = $stmtFocus->fetch();
        $focusToday = (int)($fMetrics['focus_today'] ?? 0);
        $focusWeek = (int)($fMetrics['focus_week'] ?? 0);

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

        $productivityContext = "User's verified metrics: {$focusToday} mins focused today ({$focusWeek} mins this week); {$tasksCompleted} tasks done, {$tasksPending} pending; Habit streak: {$maxStreak} days ({$habitsTodayDone}/{$totalHabits} completed today).";
    } catch (Exception $e) {
        error_log("Groot context query error: " . $e->getMessage());
    }
}

// 2. Base System Prompt with In-App Capabilities & Knowledge Base
$systemInstruction = "You are Groot, the dedicated in-app productivity assistant for FocusFlow. "
                   . "RULES & CAPABILITIES: "
                   . "- You are a digital AI assistant embedded in the FocusFlow Android application. "
                   . "- You CANNOT perform physical actions such as singing, eating, sleeping, running, dancing, or cooking. If asked, state clearly: "
                   . "\"As a digital assistant in FocusFlow, I can't sing or eat, but I can help you focus, manage your habits, or set your next timer.\" "
                   . "- You possess comprehensive knowledge of FocusFlow: Pomodoro timers, Deep Work intervals, habit streaks, distraction analysis, and daily productivity trends. "
                   . "- Strictly reject general knowledge, pop culture, movies, celebrities, and non-productivity questions. "
                   . "- If asked anything outside FocusFlow or core productivity/focus advice, politely decline and steer the user back: "
                   . "\"I'm dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?\" "
                   . "- Never invent facts or hallucinate answers. "
                   . "FOCUSFLOW APP KNOWLEDGE: "
                   . "- Focus Timer: Pomodoro countdown with custom duration (5-90 min), center interval selector, ongoing foreground notification, and session history tracking. "
                   . "- Habits: Daily habits with consecutive day streaks, 7-day consistency graphs, and streak details modal. "
                   . "- Tasks: Priority tags (High, Medium, Low), category filtering, and progress tracking. "
                   . "- Insights: Daily and weekly focus statistics, completion rates, and historical charts. "
                   . "- Data Export: Structured .csv and .txt/.md export of focus sessions, habits, and tasks with native file sharing. "
                   . "- Themes: Clean Light (default) and Default Dark (#0F0F14). "
                   . "ACTION CAPABILITY: "
                   . "If the user asks you to perform an in-app action (such as starting a timer, adding a task, creating a habit, navigating, or setting goals), "
                   . "append an action block at the very end of your response in this exact format: "
                   . "[ACTION: {\"type\": \"START_TIMER\", \"duration\": 25, \"mode\": \"FOCUS\"}] "
                   . "or [ACTION: {\"type\": \"CREATE_TASK\", \"title\": \"...\", \"priority\": \"medium\", \"category\": \"General\"}] "
                   . "or [ACTION: {\"type\": \"CREATE_HABIT\", \"title\": \"...\", \"frequency\": \"daily\", \"icon\": \"bolt\"}] "
                   . "or [ACTION: {\"type\": \"NAVIGATE\", \"route\": \"timer|insights|habits|tasks|profile|export_data\"}] "
                   . "or [ACTION: {\"type\": \"SET_GOAL\", \"daily_minutes\": 240}]. "
                   . "Answer concisely, directly, and helpfully. "
                   . "Never use emojis.";

if (!empty($productivityContext)) {
    $systemInstruction .= "\n\nVerified user productivity context: " . $productivityContext;
}

// 3. Assemble Conversation Messages with History
$messages = [
    ["role" => "system", "content" => $systemInstruction]
];

if (!empty($history)) {
    $trimmedHistory = array_slice($history, -10);
    foreach ($trimmedHistory as $item) {
        $role = $item['role'] ?? ($item['isFromUser'] ?? false ? 'user' : 'assistant');
        $content = trim($item['content'] ?? $item['text'] ?? '');
        if ($content !== '') {
            $messages[] = [
                "role" => in_array($role, ['user', 'assistant', 'system']) ? $role : 'user',
                "content" => $content
            ];
        }
    }
}

$messages[] = [
    "role" => "user",
    "content" => $prompt
];

$detectedAction = null;

// 4. Forward to local fine-tuned model running on port 8000 if not already handled
if (empty($aiResponseText)) {
    $payload = [
        "model" => "groot",
        "messages" => $messages,
        "temperature" => 0.6,
        "max_tokens" => 600,
        "top_p" => 0.9
    ];

    $ch = curl_init("http://127.0.0.1:8000/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!$curlError && $httpCode === 200 && !empty($response)) {
        $decoded = json_decode($response, true);
        if (!empty($decoded['choices'][0]['message']['content'])) {
            $aiResponseText = trim($decoded['choices'][0]['message']['content']);
        } elseif (!empty($decoded['reply'])) {
            $aiResponseText = trim($decoded['reply']);
        } elseif (!empty($decoded['insight'])) {
            $aiResponseText = trim($decoded['insight']);
        }
    }
}

// 5. Check if output has an embedded action tag: [ACTION: {...}]
if (!empty($aiResponseText) && preg_match('/\[ACTION:\s*(\{.*?\})\]/s', $aiResponseText, $actionMatch)) {
    $actionDecoded = json_decode($actionMatch[1], true);
    if (is_array($actionDecoded) && !empty($actionDecoded['type'])) {
        $detectedAction = $actionDecoded;
        // Clean out action tag from user-facing text
        $aiResponseText = trim(str_replace($actionMatch[0], '', $aiResponseText));
    }
}

// 6. Autonomous Action Detection Engine (Rule-based parsing if model didn't emit tag or is offline)
if ($detectedAction === null) {
    // 6a. Timer Action Detection
    if (preg_match('/(?:start|set|run|begin|launch|activate)\s+(?:a\s+)?(\d+)?\s*(?:min|minute|minutes|m)?\s*(focus|timer|session|break|pomodoro)?/i', $prompt, $mTimer) ||
        preg_match('/(\d+)\s*(?:min|minute|minutes)\s*(focus|timer|session|break)/i', $prompt, $mTimer2)) {
        
        $duration = 25;
        $mode = "FOCUS";
        
        if (!empty($mTimer[1])) {
            $duration = (int)$mTimer[1];
        } elseif (!empty($mTimer2[1])) {
            $duration = (int)$mTimer2[1];
        }
        
        if (preg_match('/break/i', $prompt)) {
            $mode = ($duration >= 15) ? "LONG_BREAK" : "SHORT_BREAK";
            if ($duration === 25) $duration = 5;
        }

        $duration = max(5, min(90, $duration));

        $detectedAction = [
            "type" => "START_TIMER",
            "duration" => $duration,
            "mode" => $mode
        ];

        if (empty($aiResponseText)) {
            $label = ($mode === 'FOCUS') ? 'focus session' : 'break';
            $aiResponseText = "I have configured and started a {$duration}-minute {$label} for you. Immerse yourself in deep flow.";
        }
    }
    // 6b. Task Creation Action Detection
    elseif (preg_match('/(?:add|create|new|schedule)\s+(?:a\s+)?task\s+(?:named|to|called)?\s*[:"\']?(.+?)[\'"]?$/i', $prompt, $mTask)) {
        $taskTitle = trim($mTask[1]);
        $priority = "medium";
        if (preg_match('/\b(high|urgent|critical|important)\b/i', $prompt)) {
            $priority = "high";
        } elseif (preg_match('/\b(low|minor)\b/i', $prompt)) {
            $priority = "low";
        }

        $detectedAction = [
            "type" => "CREATE_TASK",
            "title" => $taskTitle,
            "priority" => $priority,
            "category" => "General"
        ];

        if (empty($aiResponseText)) {
            $aiResponseText = "I have added '{$taskTitle}' to your task list with {$priority} priority.";
        }
    }
    // 6c. Habit Creation Action Detection
    elseif (preg_match('/(?:add|create|new|track|build)\s+(?:a\s+)?habit\s+(?:named|to|called)?\s*[:"\']?(.+?)[\'"]?$/i', $prompt, $mHabit)) {
        $habitTitle = trim($mHabit[1]);
        $detectedAction = [
            "type" => "CREATE_HABIT",
            "title" => $habitTitle,
            "frequency" => "daily",
            "icon" => "bolt"
        ];

        if (empty($aiResponseText)) {
            $aiResponseText = "I have created the daily habit '{$habitTitle}' for you. Start compounding your streak today!";
        }
    }
    // 6d. Navigation Action Detection
    elseif (preg_match('/(?:open|show|go to|take me to|navigate to|view)\s+(?:my\s+)?(timer|insights|analytics|habits|tasks|task manager|profile|export|data export|settings)/i', $prompt, $mNav)) {
        $target = strtolower($mNav[1]);
        $route = "timer";
        $screenName = "Timer";

        if (strpos($target, "insight") !== false || strpos($target, "analytics") !== false) {
            $route = "insights";
            $screenName = "Insights & Analytics";
        } elseif (strpos($target, "habit") !== false) {
            $route = "habits";
            $screenName = "Habit Tracker";
        } elseif (strpos($target, "task") !== false) {
            $route = "tasks";
            $screenName = "Task Manager";
        } elseif (strpos($target, "profile") !== false || strpos($target, "settings") !== false) {
            $route = "profile";
            $screenName = "Profile & Preferences";
        } elseif (strpos($target, "export") !== false) {
            $route = "export_data";
            $screenName = "Export Data";
        }

        $detectedAction = [
            "type" => "NAVIGATE",
            "route" => $route,
            "screen_name" => $screenName
        ];

        if (empty($aiResponseText)) {
            $aiResponseText = "Navigating to {$screenName}.";
        }
    }
    // 6e. Goal Action Detection
    elseif (preg_match('/(?:set|change|update)\s+(?:my\s+)?(?:daily\s+)?(?:goal|target)\s+(?:to\s+)?(\d+)\s*(hours?|hrs?|mins?|minutes?)/i', $prompt, $mGoal)) {
        $amount = (int)$mGoal[1];
        $isHours = preg_match('/hours?|hrs?/i', $mGoal[2]);
        $minutes = $isHours ? ($amount * 60) : $amount;
        $minutes = max(30, min(720, $minutes));
        $hoursDisplay = round($minutes / 60.0, 1);

        $detectedAction = [
            "type" => "SET_GOAL",
            "daily_minutes" => $minutes
        ];

        if (empty($aiResponseText)) {
            $aiResponseText = "I have updated your daily focus target to {$hoursDisplay} hours ({$minutes} minutes).";
        }
    }
}

// 6f. Confirmation for action if model produced only the action tag
if ($detectedAction !== null && empty($aiResponseText)) {
    $type = strtoupper($detectedAction['type'] ?? '');
    switch ($type) {
        case 'START_TIMER':
            $duration = (int)($detectedAction['duration'] ?? 25);
            $mode = ($detectedAction['mode'] ?? 'FOCUS') === 'FOCUS' ? 'focus session' : 'break';
            $aiResponseText = "I have configured and started a {$duration}-minute {$mode} for you. Immerse yourself in deep flow.";
            break;
        case 'CREATE_TASK':
            $taskTitle = htmlspecialchars($detectedAction['title'] ?? 'Task');
            $priority = htmlspecialchars($detectedAction['priority'] ?? 'medium');
            $aiResponseText = "I have added '{$taskTitle}' to your task list with {$priority} priority.";
            break;
        case 'CREATE_HABIT':
            $habitTitle = htmlspecialchars($detectedAction['title'] ?? 'Habit');
            $aiResponseText = "I have created the daily habit '{$habitTitle}' for you. Start compounding your streak today.";
            break;
        case 'NAVIGATE':
            $screenName = htmlspecialchars($detectedAction['screen_name'] ?? $detectedAction['route'] ?? 'screen');
            $aiResponseText = "Navigating to {$screenName}.";
            break;
        case 'SET_GOAL':
            $minutes = (int)($detectedAction['daily_minutes'] ?? 240);
            $hoursDisplay = round($minutes / 60.0, 1);
            $aiResponseText = "I have updated your daily focus target to {$hoursDisplay} hours ({$minutes} minutes).";
            break;
    }
}

// 7. Fallback ONLY when AI reasoning engine is unreachable
if (empty($aiResponseText)) {
    if (!empty($curlError) || $httpCode !== 200) {
        $aiResponseText = "I am Groot, your AI collaborator inside FocusFlow. I am currently unable to reach my reasoning engine (port 8000). Please ensure the AI service is active.";
    } else {
        $aiResponseText = "I am Groot, your AI collaborator inside FocusFlow. I am here to assist with your productivity, tasks, and questions.";
    }
}

// Strictly purge emojis
$aiResponseText = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $aiResponseText);
$aiResponseText = trim($aiResponseText);

echo json_encode([
    "status" => "success",
    "success" => true,
    "insight" => $aiResponseText,
    "message" => $aiResponseText,
    "action" => $detectedAction,
    "timestamp" => date('Y-m-d H:i:s')
]);
exit();
