<?php
// preferences/save_preferences.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/setup_table.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
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

// Support both JSON body and Form-Data/x-www-form-urlencoded
$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId = $input['user_id'] ?? $input['id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;

try {
    // 1. Verify user exists in `users` table
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $existingUser = $userStmt->fetch();

    if (!$existingUser) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found with id $userId."]);
        exit;
    }

    // 2. Optionally update name in `users` if provided
    $name = isset($input['name']) ? trim($input['name']) : '';
    if (!empty($name) && $name !== $existingUser['name']) {
        $updateNameStmt = $conn->prepare("UPDATE users SET name = :name WHERE id = :id");
        $updateNameStmt->execute([
            ':name' => $name,
            ':id'   => $userId
        ]);
        $existingUser['name'] = $name;
    }

    // 3. Parse and sanitize preference fields
    $primaryGoal            = !empty($input['primary_goal']) ? trim($input['primary_goal']) : 'Study';
    $preferredFocusDuration = (isset($input['preferred_focus_duration']) && is_numeric($input['preferred_focus_duration'])) 
                                ? (int)$input['preferred_focus_duration'] : 45;
    $dailyFocusGoalMinutes  = (isset($input['daily_focus_goal_minutes']) && is_numeric($input['daily_focus_goal_minutes'])) 
                                ? (int)$input['daily_focus_goal_minutes'] : 210;
    
    // Parse boolean / integer for reminders_enabled
    if (isset($input['reminders_enabled'])) {
        $remindersVal = $input['reminders_enabled'];
        $parsedBool   = filter_var($remindersVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $remindersEnabled = ($parsedBool !== null) ? ($parsedBool ? 1 : 0) : (((int)$remindersVal === 1) ? 1 : 0);
    } else {
        $remindersEnabled = 1;
    }

    $reminderTime = !empty($input['reminder_time']) ? trim($input['reminder_time']) : '09:00 AM';

    // 4. Upsert into `user_preferences`
    $upsertSql = "
        INSERT INTO user_preferences (
            user_id,
            primary_goal,
            preferred_focus_duration,
            daily_focus_goal_minutes,
            reminders_enabled,
            reminder_time
        ) VALUES (
            :user_id,
            :primary_goal,
            :preferred_focus_duration,
            :daily_focus_goal_minutes,
            :reminders_enabled,
            :reminder_time
        )
        ON DUPLICATE KEY UPDATE
            primary_goal             = VALUES(primary_goal),
            preferred_focus_duration = VALUES(preferred_focus_duration),
            daily_focus_goal_minutes = VALUES(daily_focus_goal_minutes),
            reminders_enabled        = VALUES(reminders_enabled),
            reminder_time            = VALUES(reminder_time),
            updated_at               = CURRENT_TIMESTAMP
    ";

    $stmt = $conn->prepare($upsertSql);
    $stmt->execute([
        ':user_id'                  => $userId,
        ':primary_goal'             => $primaryGoal,
        ':preferred_focus_duration' => $preferredFocusDuration,
        ':daily_focus_goal_minutes' => $dailyFocusGoalMinutes,
        ':reminders_enabled'        => $remindersEnabled,
        ':reminder_time'            => $reminderTime
    ]);

    // 5. Fetch and return updated preferences joined with user's name
    $fetchStmt = $conn->prepare("
        SELECT p.user_id, u.name, p.primary_goal, p.preferred_focus_duration,
               p.daily_focus_goal_minutes, p.reminders_enabled, p.reminder_time, p.updated_at
        FROM user_preferences p
        JOIN users u ON u.id = p.user_id
        WHERE p.user_id = :user_id
        LIMIT 1
    ");
    $fetchStmt->execute([':user_id' => $userId]);
    $saved = $fetchStmt->fetch();

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Preferences saved successfully.",
        "data"    => [
            "user_id"                  => (int)$saved['user_id'],
            "name"                     => $saved['name'] ?? '',
            "primary_goal"             => $saved['primary_goal'],
            "preferred_focus_duration" => (int)$saved['preferred_focus_duration'],
            "daily_focus_goal_minutes" => (int)$saved['daily_focus_goal_minutes'],
            "reminders_enabled"        => (int)$saved['reminders_enabled'],
            "reminder_time"            => $saved['reminder_time'],
            "updated_at"               => $saved['updated_at']
        ]
    ]);
} catch (PDOException $e) {
    error_log("Save Preferences Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Database error occurred while saving preferences."
    ]);
}

