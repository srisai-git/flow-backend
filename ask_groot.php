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
$systemInstruction = "You are Groot, an intelligent, articulate, adaptive AI collaborator and focus coach within the FocusFlow application. "
                   . "You can answer any question across coding, computer science, math, literature, and general life, while also acting as an autonomous in-app assistant. "
                   . "FOCUSFLOW APP KNOWLEDGE: "
                   . "- Focus Timer: Pomodoro countdown with custom duration (5-90 min), ongoing foreground notification, and session history tracking. "
                   . "- Habits: Daily habits with consecutive day streaks, 7-day consistency graphs, and streak details modal. "
                   . "- Tasks: Priority tags (High, Medium, Low), category filtering, and progress tracking. "
                   . "- Insights: Daily and weekly focus statistics, completion rates, and historical charts. "
                   . "- Data Export: Structured .csv and .txt/.md export of focus sessions, habits, and tasks with native file sharing. "
                   . "- Themes: Default Dark (#0F0F14) and Clean Light (#F8F9FA). "
                   . "ACTION CAPABILITY: "
                   . "If the user asks you to perform an in-app action (such as starting a timer, adding a task, creating a habit, navigating, or setting goals), "
                   . "append an action block at the very end of your response in this exact format: "
                   . "[ACTION: {\"type\": \"START_TIMER\", \"duration\": 25, \"mode\": \"FOCUS\"}] "
                   . "or [ACTION: {\"type\": \"CREATE_TASK\", \"title\": \"...\", \"priority\": \"medium\", \"category\": \"General\"}] "
                   . "or [ACTION: {\"type\": \"CREATE_HABIT\", \"title\": \"...\", \"frequency\": \"daily\", \"icon\": \"bolt\"}] "
                   . "or [ACTION: {\"type\": \"NAVIGATE\", \"route\": \"timer|insights|habits|tasks|profile|export_data\"}] "
                   . "or [ACTION: {\"type\": \"SET_GOAL\", \"daily_minutes\": 240}]. "
                   . "Answer clearly, concisely, and helpfully. If uncertain, state you do not know. Never use emojis.";

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

// 4. Forward to local fine-tuned model running on port 8000
$payload = [
    "model" => "groot",
    "messages" => $messages,
    "temperature" => 0.7,
    "max_tokens" => 512,
    "top_p" => 0.9
];

$ch = curl_init("http://127.0.0.1:8000/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$aiResponseText = null;
$detectedAction = null;

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

// 7. General Knowledge / App Q&A Fallback if AI server is offline
if (empty($aiResponseText)) {
    if (preg_match('/^(hi|hello|hey|greetings|good\s*(morning|afternoon|evening|day))[\s!.]*$/i', $lowerPrompt)) {
        $aiResponseText = "Hello! I am Groot, your AI assistant and productivity collaborator in FocusFlow. How can I assist you with your focus, tasks, or inquiries today?";
    } elseif (preg_match('/(who are you|what are you|introduce yourself)/i', $lowerPrompt)) {
        $aiResponseText = "I am Groot, your versatile in-app AI collaborator. I can answer questions across coding, math, and general topics, as well as take action directly inside FocusFlow—such as starting timers, scheduling tasks, building habits, and analyzing your focus stats.";
    } elseif (preg_match('/(what can you do|features|help me with)/i', $lowerPrompt)) {
        $aiResponseText = "I can do both conversational reasoning and autonomous in-app actions:\n- Start focus timers (e.g. 'Start a 25 min timer')\n- Add priority tasks (e.g. 'Add task Finish project draft')\n- Create daily habits (e.g. 'Create habit Read 20 pages')\n- Navigate screens (e.g. 'Open insights', 'Export data')\n- Explain software, algorithms, academic concepts, or analyze your streak consistency.";
    } elseif (preg_match('/(streak|compounding|consistency)/i', $lowerPrompt)) {
        $aiResponseText = "In FocusFlow, streaks represent consecutive days where you complete your active habits. Maintaining a streak compounds daily discipline into permanent habit momentum. Check your Habit Tracker or tap the streak badge on the top bar for streak details!";
    } elseif (preg_match('/(export|backup|csv)/i', $lowerPrompt)) {
        $aiResponseText = "FocusFlow allows you to export your verified focus session logs, habit consistency tables, and completed tasks into both structured .csv and .txt/.md files. You can open the Export Data screen by asking me 'Export my data'.";
    } elseif ($isProductivityQuery && !empty($productivityContext)) {
        $aiResponseText = "Here is your verified FocusFlow activity: " . $productivityContext . " Starting a 25-minute Pomodoro session will keep your momentum strong.";
    } else {
        $aiResponseText = "I am Groot, your FocusFlow assistant. I am ready to answer your questions and perform actions like starting timers, scheduling tasks, and creating habits. My local reasoning server (port 8000) is currently offline, but all in-app actions and assistance are fully operational!";
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
