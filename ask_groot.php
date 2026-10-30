<?php
// ask_groot.php - Resilient Full-Stack AI Proxy Bridge for FocusFlow
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
        "insight" => "Please ask a question or enter a topic.",
        "message" => "Empty prompt"
    ]);
    exit();
}

// 1. Detect if the user explicitly asks about their focus, habits, or productivity stats
$lowerPrompt = strtolower($prompt);
$isProductivityQuery = preg_match('/\b(focus|habit|habits|streak|streaks|task|tasks|stat|stats|metric|metrics|progress|routine|sessions?|today)\b/i', $lowerPrompt);

$productivityContext = "";
if ($isProductivityQuery && $userId > 0 && isset($conn)) {
    try {
        // Total focus minutes today
        $stmtToday = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (DATE(created_at) = CURDATE() OR DATE(completed_at) = CURDATE())");
        $stmtToday->execute([':uid' => $userId]);
        $focusToday = (int)$stmtToday->fetchColumn();

        // Total focus minutes past 7 days
        $stmtWeek = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed' AND (created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))");
        $stmtWeek->execute([':uid' => $userId]);
        $focusWeek = (int)$stmtWeek->fetchColumn();

        // Tasks counts
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

        $productivityContext = "User's verified metrics: {$focusToday} mins focused today ({$focusWeek} mins this week); {$tasksCompleted} tasks done, {$tasksPending} pending; Habit streak: {$maxStreak} days ({$habitsTodayDone}/{$totalHabits} completed today).";
    } catch (Exception $e) {
        error_log("Groot context query error: " . $e->getMessage());
    }
}

// 2. Base System Prompt
$systemInstruction = "You are Groot, an intelligent, articulate, adaptive AI collaborator and focus coach within the FocusFlow application. "
                   . "Your goal is to provide insightful, clear, and direct answers to any query—including coding, software engineering, math, "
                   . "writing, general questions, and productivity guidance. If you do not know the answer or lack verified context, "
                   . "clearly state that you do not know rather than guessing. Maintain a grounded, helpful tone. Never use emojis.";

if (!empty($productivityContext)) {
    $systemInstruction .= "\n\nVerified user productivity context: " . $productivityContext;
}

// 3. Assemble Conversation Messages with History
$messages = [
    ["role" => "system", "content" => $systemInstruction]
];

// Append multi-turn history (last 10 turns to avoid excessive context)
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

// Append current user message
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

// 5. Resilient Offline Fallback
if (empty($aiResponseText)) {
    // Conversational & Greeting Handlers when offline
    if (preg_match('/^(hi|hello|hey|greetings|good\s*(morning|afternoon|evening|day))[\s!.]*$/i', $lowerPrompt)) {
        $aiResponseText = "Hello! I am Groot, your versatile AI assistant in FocusFlow. How can I help you today?";
    } elseif (preg_match('/(who are you|what are you|introduce yourself)/i', $lowerPrompt)) {
        $aiResponseText = "I am Groot, an intelligent, versatile AI assistant integrated into FocusFlow. I can help with coding, explain concepts, assist in writing, and guide your daily focus sessions and habit consistency.";
    } elseif (preg_match('/(what can you do|help me with|features)/i', $lowerPrompt)) {
        $aiResponseText = "I can assist across multiple domains: answering technical and academic questions, debugging code, breaking down tasks, and giving personalized insights into your focus sessions.";
    } elseif ($isProductivityQuery && !empty($productivityContext)) {
        $aiResponseText = "Here is your current productivity status: " . $productivityContext . " Launching an uninterrupted focus block is a great next step.";
    } else {
        $aiResponseText = "I am Groot, your FocusFlow assistant. I am ready to help, but my local deep-reasoning engine (port 8000) is currently offline. Please ensure 'python server.py' is running in the groot_ai directory.";
    }
}

// 6. Strictly purge any emojis
$aiResponseText = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $aiResponseText);
$aiResponseText = trim($aiResponseText);

echo json_encode([
    "status" => "success",
    "success" => true,
    "insight" => $aiResponseText,
    "message" => $aiResponseText,
    "timestamp" => date('Y-m-d H:i:s')
]);
exit();
