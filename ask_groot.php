<?php
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
$input = array_merge($_GET, $_POST, $json);

$rawPrompt = trim($input['prompt'] ?? $input['message'] ?? $input['query'] ?? $input['text'] ?? '');
$prompt = html_entity_decode($rawPrompt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$userId = intval($input['user_id'] ?? $input['userId'] ?? 1);
$history = isset($input['history']) && is_array($input['history']) ? $input['history'] : [];

if (empty($prompt)) {
    echo json_encode([
        "status" => "error",
        "reply" => "Please ask a question or command Groot to perform an action.",
        "insight" => "Please ask a question or command Groot to perform an action.",
        "message" => "Empty prompt",
        "actions" => [],
        "action" => null
    ]);
    exit();
}

$lowerPrompt = strtolower($prompt);

if (preg_match('/\b(sing|singing|song|eat|eating|food|sleep|sleeping|sleepy|run|running|dance|dancing|walk|walking|cook|cooking|drink|drinking)\b/i', $prompt)) {
    $decline = "As an AI companion inside FocusFlow, I cannot sing or eat, but I can help you focus, start a timer, or track your habits.";
    echo json_encode([
        "status" => "success",
        "success" => true,
        "reply" => $decline,
        "insight" => $decline,
        "response" => $decline,
        "message" => $decline,
        "actions" => [],
        "action" => null
    ]);
    exit();
}

if (preg_match('/\b(rajini|rajinikanth|actor|actress|movie|cinema|film|celebrity|hero|director|cricket|football|fifa|ipl|messi|ronaldo|politician|politics|president|minister|song|bollywood|hollywood|kollywood|tollywood|gossip)\b/i', $prompt)) {
    $decline = "I am dedicated to your productivity and learning inside FocusFlow. I can help you plan study sessions, write code, solve problems, or track your habits. What would you like to accomplish today?";
    echo json_encode([
        "status" => "success",
        "success" => true,
        "reply" => $decline,
        "insight" => $decline,
        "response" => $decline,
        "message" => $decline,
        "actions" => [],
        "action" => null
    ]);
    exit();
}

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

$detectedActions = [];
$aiResponseText = "";

$ch = curl_init("http://127.0.0.1:8000/chat");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "message" => $prompt,
    "prompt" => $prompt,
    "user_id" => $userId,
    "history" => $history
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (!$curlError && $httpCode === 200 && !empty($response)) {
    $decoded = json_decode($response, true);
    if (!empty($decoded['reply'])) {
        $aiResponseText = trim($decoded['reply']);
    } elseif (!empty($decoded['response'])) {
        $aiResponseText = trim($decoded['response']);
    } elseif (!empty($decoded['insight'])) {
        $aiResponseText = trim($decoded['insight']);
    }
    if (!empty($decoded['actions']) && is_array($decoded['actions'])) {
        $detectedActions = $decoded['actions'];
    } elseif (!empty($decoded['action'])) {
        $detectedActions = [$decoded['action']];
    }
}

if (empty($detectedActions)) {
    $subClauses = preg_split('/\s+(?:and|then)\s+|;|\n/i', $prompt);
    $actionReplies = [];

    foreach ($subClauses as $clause) {
        $c = trim($clause);
        if (empty($c)) continue;
        $cLower = strtolower($c);

        if (preg_match('/(?:start|set|run|begin|launch|activate)\s+(?:a\s+)?(\d+)?\s*(?:min|minute|minutes|m)?\s*(focus|timer|session|break|pomodoro)?/i', $c, $mTimer) ||
            preg_match('/(\d+)\s*(?:min|minute|minutes|m)\s*(focus|timer|session|break|pomodoro)/i', $c, $mTimer2)) {
            $duration = 25;
            if (!empty($mTimer[1])) $duration = (int)$mTimer[1];
            elseif (!empty($mTimer2[1])) $duration = (int)$mTimer2[1];
            $mode = "FOCUS";
            if (strpos($cLower, 'break') !== false) {
                $mode = ($duration >= 15) ? "LONG_BREAK" : "SHORT_BREAK";
                if ($duration === 25) $duration = 5;
            }
            $duration = max(1, min(180, $duration));
            $detectedActions[] = [
                "type" => "START_FOCUS_TIMER",
                "payload" => ["duration" => $duration, "mode" => $mode]
            ];
            $label = ($mode === 'FOCUS') ? 'focus session' : 'break';
            $actionReplies[] = "Started a {$duration}-minute {$label} for you.";
            continue;
        }

        if (preg_match('/\b(pause|hold)\s+(?:the\s+)?(timer|focus|session)\b/i', $c)) {
            $detectedActions[] = ["type" => "PAUSE_TIMER", "payload" => (object)[]];
            $actionReplies[] = "Paused the active focus timer.";
            continue;
        }

        if (preg_match('/\b(resume|continue|unpause)\s+(?:the\s+)?(timer|focus|session)\b/i', $c)) {
            $detectedActions[] = ["type" => "RESUME_TIMER", "payload" => (object)[]];
            $actionReplies[] = "Resumed your focus timer.";
            continue;
        }

        if (preg_match('/\b(stop|end|cancel|reset)\s+(?:the\s+)?(timer|focus|session)\b/i', $c)) {
            $detectedActions[] = ["type" => "STOP_TIMER", "payload" => (object)[]];
            $actionReplies[] = "Stopped the timer.";
            continue;
        }

        if (preg_match('/\b(enable|turn on|activate|start)\s+strict(?:\s+focus)?(?:\s+mode)?\b/i', $c)) {
            $detectedActions[] = ["type" => "ENABLE_STRICT_MODE", "payload" => (object)[]];
            $actionReplies[] = "Enabled Strict Focus Mode. Distracting apps are now locked.";
            continue;
        }

        if (preg_match('/\b(disable|turn off|deactivate|stop)\s+strict(?:\s+focus)?(?:\s+mode)?\b/i', $c)) {
            $detectedActions[] = ["type" => "DISABLE_STRICT_MODE", "payload" => (object)[]];
            $actionReplies[] = "Disabled Strict Focus Mode.";
            continue;
        }

        if (preg_match('/\b(clear|delete|remove)\s+(?:all\s+)?completed\s+tasks?\b/i', $c)) {
            $detectedActions[] = ["type" => "CLEAR_COMPLETED_TASKS", "payload" => (object)[]];
            $actionReplies[] = "Cleared all completed tasks.";
            continue;
        }

        if (preg_match('/\b(complete|finish|done|mark\s+complete|check\s+off)\s+(?:task\s+)?[\'"]?([^\'"]+?)[\'"]?$/i', $c, $mComp)) {
            if (strpos($cLower, 'habit') === false && strpos($cLower, 'timer') === false) {
                $t = trim($mComp[1], " \t\n\r\0\x0B.'\"");
                $detectedActions[] = ["type" => "COMPLETE_TASK", "payload" => ["title" => $t]];
                $actionReplies[] = "Marked task '{$t}' as completed.";
                continue;
            }
        }

        if (preg_match('/\b(delete|remove)\s+task\s+[\'"]?([^\'"]+?)[\'"]?$/i', $c, $mDelTask)) {
            $t = trim($mDelTask[1], " \t\n\r\0\x0B.'\"");
            $detectedActions[] = ["type" => "DELETE_TASK", "payload" => ["title" => $t]];
            $actionReplies[] = "Deleted task '{$t}'.";
            continue;
        }

        if (preg_match('/\b(?:add|create|new|schedule)\s+(?:a\s+)?task\s+(?:named|to|called)?\s*[:"\']?(.+?)[\'"]?$/i', $c, $mTask)) {
            $taskTitle = trim($mTask[1], " \t\n\r\0\x0B.'\"");
            $priority = "medium";
            if (preg_match('/\b(high|urgent|critical|important)\b/i', $c)) {
                $priority = "high";
            } elseif (preg_match('/\b(low|minor)\b/i', $c)) {
                $priority = "low";
            }
            $detectedActions[] = [
                "type" => "CREATE_TASK",
                "payload" => ["title" => $taskTitle, "priority" => $priority, "category" => "General"]
            ];
            $actionReplies[] = "Created task '{$taskTitle}' with {$priority} priority.";
            continue;
        }

        if (preg_match('/\b(?:list|show|view|get|what are)\s+(?:my\s+)?(?:pending|overdue|completed|all)?\s*tasks\b/i', $c)) {
            $filter = "all";
            if (strpos($cLower, 'pending') !== false) $filter = "pending";
            elseif (strpos($cLower, 'completed') !== false) $filter = "completed";
            elseif (strpos($cLower, 'overdue') !== false) $filter = "overdue";
            elseif (strpos($cLower, 'high') !== false) $filter = "high_priority";
            $detectedActions[] = ["type" => "LIST_TASKS", "payload" => ["filter" => $filter]];
            $actionReplies[] = "Here is your {$filter} tasks overview.";
            continue;
        }

        if (preg_match('/\b(?:add|create|new|track|build)\s+(?:a\s+)?habit\s+(?:named|to|called)?\s*[:"\']?(.+?)[\'"]?$/i', $c, $mHabit)) {
            $hTitle = trim($mHabit[1], " \t\n\r\0\x0B.'\"");
            $detectedActions[] = [
                "type" => "CREATE_HABIT",
                "payload" => ["title" => $hTitle, "frequency" => "daily", "icon" => "bolt"]
            ];
            $actionReplies[] = "Created the daily habit '{$hTitle}'.";
            continue;
        }

        if (preg_match('/\b(check\s+off|log|complete|done)\s+(?:habit\s+)?[\'"]?([^\'"]+?)[\'"]?$/i', $c, $mChkHabit)) {
            if (strpos($cLower, 'task') === false && strpos($cLower, 'timer') === false) {
                $hTitle = trim($mChkHabit[1], " \t\n\r\0\x0B.'\"");
                $detectedActions[] = ["type" => "CHECK_OFF_HABIT", "payload" => ["title" => $hTitle]];
                $actionReplies[] = "Checked off habit '{$hTitle}' for today.";
                continue;
            }
        }

        if (preg_match('/\b(delete|remove)\s+habit\s+[\'"]?([^\'"]+?)[\'"]?$/i', $c, $mDelHabit)) {
            $hTitle = trim($mDelHabit[1], " \t\n\r\0\x0B.'\"");
            $detectedActions[] = ["type" => "DELETE_HABIT", "payload" => ["title" => $hTitle]];
            $actionReplies[] = "Deleted habit '{$hTitle}'.";
            continue;
        }

        if (preg_match('/\b(habit\s+status|check\s+habits|my\s+habits\s+today|how\s+are\s+my\s+habits)\b/i', $c)) {
            $detectedActions[] = ["type" => "HABIT_STATUS", "payload" => (object)[]];
            $actionReplies[] = "Here is your habit completion status for today.";
            continue;
        }

        if (preg_match('/\b(focus\s+summary|my\s+stats|my\s+analytics|how\s+much\s+did\s+i\s+focus|today\'?s?\s+stats|focus\s+minutes)\b/i', $c)) {
            $detectedActions[] = ["type" => "GET_FOCUS_SUMMARY", "payload" => (object)[]];
            $actionReplies[] = "Here is your focus and productivity breakdown.";
            continue;
        }

        if (preg_match('/(?:open|show|go to|take me to|navigate to|view)\s+(?:my\s+)?(timer|focus timer|insights|analytics|habits|tasks|task manager|profile|settings|dashboard|home)\b/i', $c, $mNav)) {
            $dest = strtolower($mNav[1]);
            $tab = "tasks";
            if (strpos($dest, 'timer') !== false || strpos($dest, 'focus') !== false) $tab = "focus_timer";
            elseif (strpos($dest, 'habit') !== false) $tab = "habits";
            elseif (strpos($dest, 'insight') !== false || strpos($dest, 'analytic') !== false) $tab = "analytics";
            elseif (strpos($dest, 'profile') !== false || strpos($dest, 'setting') !== false) $tab = "profile";
            elseif (strpos($dest, 'home') !== false || strpos($dest, 'dash') !== false) $tab = "home";

            $detectedActions[] = ["type" => "NAVIGATE_TAB", "payload" => ["tab" => $tab, "route" => $tab]];
            $actionReplies[] = "Navigating to {$tab}.";
            continue;
        }

        if (preg_match('/\b(export|share|download|save)\s+(?:this\s+)?(?:chat|transcript|conversation)\b/i', $c)) {
            $detectedActions[] = ["type" => "EXPORT_CHAT", "payload" => (object)[]];
            $actionReplies[] = "Exporting chat transcript.";
            continue;
        }

        if (preg_match('/\b(clear|delete|reset)\s+(?:this\s+)?(?:chat|history|conversation)\b/i', $c)) {
            $detectedActions[] = ["type" => "CLEAR_CHAT", "payload" => (object)[]];
            $actionReplies[] = "Cleared chat history.";
            continue;
        }
    }

    if (!empty($actionReplies) && empty($aiResponseText)) {
        $aiResponseText = implode(" ", $actionReplies);
    }
}

if (empty($aiResponseText)) {
    $aiResponseText = "I am Groot, your AI collaborator inside FocusFlow. I am here to assist with your productivity, tasks, and questions.";
}

$aiResponseText = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $aiResponseText);
$aiResponseText = trim(html_entity_decode($aiResponseText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

echo json_encode([
    "status" => "success",
    "success" => true,
    "reply" => $aiResponseText,
    "insight" => $aiResponseText,
    "response" => $aiResponseText,
    "message" => $aiResponseText,
    "actions" => $detectedActions,
    "action" => !empty($detectedActions) ? $detectedActions[0] : null,
    "timestamp" => date('Y-m-d H:i:s')
]);
exit();
