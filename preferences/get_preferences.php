<?php
// preferences/get_preferences.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/setup_table.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use GET or POST."]);
    exit;
}

// Self-healing migration to ensure table exists
try {
    ensurePreferencesTableExists($conn);
} catch (PDOException $e) {
    error_log("Preferences Migration Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database table verification failed."]);
    exit;
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
    // 1. Verify user exists in `users`
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found with id $userId."]);
        exit;
    }

    // 2. Fetch preferences
    $prefStmt = $conn->prepare("
        SELECT user_id, primary_goal, preferred_focus_duration,
               daily_focus_goal_minutes, reminders_enabled, reminder_time, updated_at
        FROM user_preferences
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $prefStmt->execute([':user_id' => $userId]);
    $pref = $prefStmt->fetch();

    if ($pref) {
        http_response_code(200);
        echo json_encode([
            "status"  => true,
            "message" => "Preferences retrieved successfully.",
            "data"    => [
                "user_id"                  => (int)$pref['user_id'],
                "name"                     => $user['name'] ?? '',
                "primary_goal"             => $pref['primary_goal'],
                "preferred_focus_duration" => (int)$pref['preferred_focus_duration'],
                "daily_focus_goal_minutes" => (int)$pref['daily_focus_goal_minutes'],
                "reminders_enabled"        => (int)$pref['reminders_enabled'],
                "reminder_time"            => $pref['reminder_time'],
                "is_default"               => false,
                "updated_at"               => $pref['updated_at']
            ]
        ]);
    } else {
        // Return default routine preferences if user hasn't saved preferences yet
        http_response_code(200);
        echo json_encode([
            "status"  => true,
            "message" => "Default routine preferences returned.",
            "data"    => [
                "user_id"                  => $userId,
                "name"                     => $user['name'] ?? '',
                "primary_goal"             => "Study",
                "preferred_focus_duration" => 45,
                "daily_focus_goal_minutes" => 210,
                "reminders_enabled"        => 1,
                "reminder_time"            => "09:00 AM",
                "is_default"               => true,
                "updated_at"               => null
            ]
        ]);
    }
} catch (PDOException $e) {
    error_log("Get Preferences Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Database error occurred while fetching preferences."
    ]);
}

