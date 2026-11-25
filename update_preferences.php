<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/preferences/setup_table.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

try {
    ensurePreferencesTableExists($conn);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database table verification failed."]);
    exit;
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId = $input['user_id'] ?? $input['id'] ?? null;
$email = trim($input['email'] ?? '');

if ((empty($userId) || !is_numeric($userId) || (int)$userId <= 0) && !empty($email)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $foundUser = $stmt->fetch();
    if ($foundUser) {
        $userId = (int)$foundUser['id'];
    }
}

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id or valid email is required."]);
    exit;
}

$userId = (int)$userId;

try {
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $existingUser = $userStmt->fetch();

    if (!$existingUser) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found with id $userId."]);
        exit;
    }

    $dailyGoal = $input['daily_focus_goal_minutes'] ?? $input['daily_focus_goal'] ?? null;
    $focusDuration = $input['preferred_focus_duration'] ?? null;
    $remindersEnabled = isset($input['reminders_enabled']) ? (int)filter_var($input['reminders_enabled'], FILTER_VALIDATE_BOOLEAN) : (isset($input['notifications_enabled']) ? (int)filter_var($input['notifications_enabled'], FILTER_VALIDATE_BOOLEAN) : null);
    $dailyReminder = isset($input['daily_reminder_enabled']) ? (int)filter_var($input['daily_reminder_enabled'], FILTER_VALIDATE_BOOLEAN) : null;
    $deadlineReminder = isset($input['deadline_reminder_enabled']) ? (int)filter_var($input['deadline_reminder_enabled'], FILTER_VALIDATE_BOOLEAN) : null;
    $breakReminder = isset($input['break_reminder_enabled']) ? (int)filter_var($input['break_reminder_enabled'], FILTER_VALIDATE_BOOLEAN) : null;
    $strictMode = isset($input['strict_mode_default']) ? (int)filter_var($input['strict_mode_default'], FILTER_VALIDATE_BOOLEAN) : (isset($input['strict_mode_enabled']) ? (int)filter_var($input['strict_mode_enabled'], FILTER_VALIDATE_BOOLEAN) : null);
    $breakDuration = $input['break_duration'] ?? $input['short_break_duration'] ?? null;
    $reminderTime = !empty($input['reminder_time']) ? trim($input['reminder_time']) : null;

    $fetchCurrent = $conn->prepare("SELECT * FROM user_preferences WHERE user_id = :uid LIMIT 1");
    $fetchCurrent->execute([':uid' => $userId]);
    $currentPrefs = $fetchCurrent->fetch();

    $finalDailyGoal = ($dailyGoal !== null && is_numeric($dailyGoal)) ? (int)$dailyGoal : ($currentPrefs ? (int)$currentPrefs['daily_focus_goal_minutes'] : 240);
    $finalFocusDuration = ($focusDuration !== null && is_numeric($focusDuration)) ? (int)$focusDuration : ($currentPrefs ? (int)$currentPrefs['preferred_focus_duration'] : 45);
    $finalReminders = ($remindersEnabled !== null) ? $remindersEnabled : ($currentPrefs ? (int)$currentPrefs['reminders_enabled'] : 1);
    $finalDailyReminder = ($dailyReminder !== null) ? $dailyReminder : ($currentPrefs && isset($currentPrefs['daily_reminder_enabled']) ? (int)$currentPrefs['daily_reminder_enabled'] : 1);
    $finalDeadlineReminder = ($deadlineReminder !== null) ? $deadlineReminder : ($currentPrefs && isset($currentPrefs['deadline_reminder_enabled']) ? (int)$currentPrefs['deadline_reminder_enabled'] : 1);
    $finalBreakReminder = ($breakReminder !== null) ? $breakReminder : ($currentPrefs && isset($currentPrefs['break_reminder_enabled']) ? (int)$currentPrefs['break_reminder_enabled'] : 1);
    $finalStrictMode = ($strictMode !== null) ? $strictMode : ($currentPrefs && isset($currentPrefs['strict_mode_default']) ? (int)$currentPrefs['strict_mode_default'] : 0);
    $finalBreakDuration = ($breakDuration !== null && is_numeric($breakDuration)) ? (int)$breakDuration : ($currentPrefs && isset($currentPrefs['break_duration']) ? (int)$currentPrefs['break_duration'] : 5);
    $finalReminderTime = ($reminderTime !== null) ? $reminderTime : ($currentPrefs ? $currentPrefs['reminder_time'] : '09:00 AM');

    $upsertSql = "
        INSERT INTO user_preferences (
            user_id,
            primary_goal,
            preferred_focus_duration,
            daily_focus_goal_minutes,
            reminders_enabled,
            notifications_enabled,
            daily_reminder_enabled,
            deadline_reminder_enabled,
            break_reminder_enabled,
            strict_mode_default,
            break_duration,
            reminder_time
        ) VALUES (
            :user_id,
            'Study',
            :preferred_focus_duration,
            :daily_focus_goal_minutes,
            :reminders_enabled,
            :notifications_enabled,
            :daily_reminder_enabled,
            :deadline_reminder_enabled,
            :break_reminder_enabled,
            :strict_mode_default,
            :break_duration,
            :reminder_time
        )
        ON DUPLICATE KEY UPDATE
            preferred_focus_duration  = VALUES(preferred_focus_duration),
            daily_focus_goal_minutes  = VALUES(daily_focus_goal_minutes),
            reminders_enabled         = VALUES(reminders_enabled),
            notifications_enabled     = VALUES(notifications_enabled),
            daily_reminder_enabled    = VALUES(daily_reminder_enabled),
            deadline_reminder_enabled = VALUES(deadline_reminder_enabled),
            break_reminder_enabled    = VALUES(break_reminder_enabled),
            strict_mode_default       = VALUES(strict_mode_default),
            break_duration            = VALUES(break_duration),
            reminder_time             = VALUES(reminder_time),
            updated_at                = CURRENT_TIMESTAMP
    ";

    $stmt = $conn->prepare($upsertSql);
    $stmt->execute([
        ':user_id'                  => $userId,
        ':preferred_focus_duration' => $finalFocusDuration,
        ':daily_focus_goal_minutes' => $finalDailyGoal,
        ':reminders_enabled'        => $finalReminders,
        ':notifications_enabled'    => $finalReminders,
        ':daily_reminder_enabled'   => $finalDailyReminder,
        ':deadline_reminder_enabled'=> $finalDeadlineReminder,
        ':break_reminder_enabled'   => $finalBreakReminder,
        ':strict_mode_default'      => $finalStrictMode,
        ':break_duration'           => $finalBreakDuration,
        ':reminder_time'            => $finalReminderTime
    ]);

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Preferences saved successfully.",
        "data"    => [
            "user_id"                  => $userId,
            "daily_focus_goal_minutes" => $finalDailyGoal,
            "preferred_focus_duration" => $finalFocusDuration,
            "notifications_enabled"    => $finalReminders,
            "daily_reminder_enabled"   => $finalDailyReminder,
            "deadline_reminder_enabled"=> $finalDeadlineReminder,
            "break_reminder_enabled"   => $finalBreakReminder,
            "strict_mode_default"      => $finalStrictMode,
            "break_duration"           => $finalBreakDuration,
            "reminder_time"            => $finalReminderTime
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
