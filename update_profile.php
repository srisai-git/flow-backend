<?php
// update_profile.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';
require_once __DIR__ . '/preferences/setup_table.php';

$migrationFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow_migrated_v3';
if (!file_exists($migrationFlag)) {
    try {
        runAllMigrations($conn);
        ensurePreferencesTableExists($conn);
        @touch($migrationFlag);
    } catch (PDOException $e) {
        error_log("Update Profile Migration Notice: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$input = getJsonInput();

$userId = (int)($_POST['user_id'] ?? $input['user_id'] ?? 0);
$name = trim($_POST['name'] ?? $input['name'] ?? '');
$email = trim($_POST['email'] ?? $input['email'] ?? '');
$bio = trim($_POST['bio'] ?? $input['bio'] ?? '');
$avatar = trim($_POST['avatar'] ?? $input['avatar'] ?? $_POST['avatar_url'] ?? $input['avatar_url'] ?? '');

// Process avatar upload or Base64 payload if present
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    $tmpName = $_FILES['avatar']['tmp_name'];
    $fileName = 'user_' . $userId . '_' . time() . '.jpg';
    $targetPath = $uploadDir . '/' . $fileName;
    if (move_uploaded_file($tmpName, $targetPath)) {
        @copy($targetPath, $uploadDir . '/user_' . $userId . '.jpg');
        $serverProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $serverHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $avatar = $serverProtocol . $serverHost . '/flow_backend/uploads/avatars/' . $fileName;
    }
} elseif (!empty($avatar) && (strpos($avatar, 'data:image') === 0 || (strlen($avatar) > 200 && strpos($avatar, 'avatar:') !== 0 && strpos($avatar, '/') === false))) {
    $uploadDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    $base64Data = $avatar;
    $ext = 'jpg';
    if (preg_match('/^data:image\/(\w+);base64,/', $avatar, $typeMatch)) {
        $base64Data = substr($avatar, strpos($avatar, ',') + 1);
        $ext = strtolower($typeMatch[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
    }
    $decoded = base64_decode($base64Data);
    if ($decoded !== false && strlen($decoded) > 0) {
        $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $fileName;
        file_put_contents($targetPath, $decoded);
        @file_put_contents($uploadDir . '/user_' . $userId . '.jpg', $decoded);
        $serverProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $serverHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $avatar = $serverProtocol . $serverHost . '/flow_backend/uploads/avatars/' . $fileName;
    }
}

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Valid user_id is required."]);
    exit;
}

try {
    $fields = [];
    $params = [':id' => $userId];

    if (!empty($name)) {
        $fields[] = "name = :name";
        $params[':name'] = $name;
    }

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== 'user@focusflow.app' && $email !== 'email@example.com') {
        // Only validate if email is actually being changed from current user's email
        $currEmailStmt = $conn->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
        $currEmailStmt->execute([':id' => $userId]);
        $currUser = $currEmailStmt->fetch();
        $currEmail = strtolower(trim($currUser['email'] ?? ''));

        if (strtolower(trim($email)) !== $currEmail) {
            // Check if email already exists for another user
            $emailStmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $emailStmt->execute([':email' => $email, ':id' => $userId]);
            if ($emailStmt->fetch()) {
                http_response_code(409);
                echo json_encode(["status" => false, "message" => "This email address is already in use by another account."]);
                exit;
            }
            $fields[] = "email = :email";
            $params[':email'] = $email;
        }
    }

    if ($bio !== '') {
        $fields[] = "bio = :bio";
        $params[':bio'] = $bio;
    }

    if ($avatar !== '') {
        $fields[] = "avatar = :avatar";
        $params[':avatar'] = $avatar;
        $fields[] = "avatar_url = :avatar_url";
        $params[':avatar_url'] = $avatar;
    }

    if (!empty($fields)) {
        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = :id";
        $updateStmt = $conn->prepare($sql);
        $updateStmt->execute($params);
    }

    $prefsInput = $input['preferences'] ?? $_POST['preferences'] ?? null;
    if (is_string($prefsInput)) {
        $decoded = json_decode($prefsInput, true);
        if (is_array($decoded)) {
            $prefsInput = $decoded;
        }
    }

    $dailyGoal = null;
    if (isset($input['daily_focus_goal_minutes'])) $dailyGoal = (int)$input['daily_focus_goal_minutes'];
    elseif (isset($input['daily_focus_goal'])) $dailyGoal = (int)$input['daily_focus_goal'];
    elseif (isset($_POST['daily_focus_goal_minutes'])) $dailyGoal = (int)$_POST['daily_focus_goal_minutes'];
    elseif (isset($_POST['daily_focus_goal'])) $dailyGoal = (int)$_POST['daily_focus_goal'];
    elseif (is_array($prefsInput) && isset($prefsInput['daily_focus_goal_minutes'])) $dailyGoal = (int)$prefsInput['daily_focus_goal_minutes'];
    elseif (is_array($prefsInput) && isset($prefsInput['daily_focus_goal'])) $dailyGoal = (int)$prefsInput['daily_focus_goal'];

    $focusDuration = null;
    if (isset($input['preferred_focus_duration'])) $focusDuration = (int)$input['preferred_focus_duration'];
    elseif (isset($_POST['preferred_focus_duration'])) $focusDuration = (int)$_POST['preferred_focus_duration'];
    elseif (is_array($prefsInput) && isset($prefsInput['preferred_focus_duration'])) $focusDuration = (int)$prefsInput['preferred_focus_duration'];

    $reminders = null;
    if (isset($input['notifications_enabled'])) $reminders = (int)filter_var($input['notifications_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($input['reminders_enabled'])) $reminders = (int)filter_var($input['reminders_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['notifications_enabled'])) $reminders = (int)filter_var($_POST['notifications_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['reminders_enabled'])) $reminders = (int)filter_var($_POST['reminders_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['notifications_enabled'])) $reminders = (int)filter_var($prefsInput['notifications_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['reminders_enabled'])) $reminders = (int)filter_var($prefsInput['reminders_enabled'], FILTER_VALIDATE_BOOLEAN);

    $dailyReminder = null;
    if (isset($input['daily_reminder_enabled'])) $dailyReminder = (int)filter_var($input['daily_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['daily_reminder_enabled'])) $dailyReminder = (int)filter_var($_POST['daily_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['daily_reminder_enabled'])) $dailyReminder = (int)filter_var($prefsInput['daily_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);

    $deadlineReminder = null;
    if (isset($input['deadline_reminder_enabled'])) $deadlineReminder = (int)filter_var($input['deadline_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['deadline_reminder_enabled'])) $deadlineReminder = (int)filter_var($_POST['deadline_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['deadline_reminder_enabled'])) $deadlineReminder = (int)filter_var($prefsInput['deadline_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);

    $breakReminder = null;
    if (isset($input['break_reminder_enabled'])) $breakReminder = (int)filter_var($input['break_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['break_reminder_enabled'])) $breakReminder = (int)filter_var($_POST['break_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['break_reminder_enabled'])) $breakReminder = (int)filter_var($prefsInput['break_reminder_enabled'], FILTER_VALIDATE_BOOLEAN);

    $strictMode = null;
    if (isset($input['strict_mode_default'])) $strictMode = (int)filter_var($input['strict_mode_default'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($input['strict_mode_enabled'])) $strictMode = (int)filter_var($input['strict_mode_enabled'], FILTER_VALIDATE_BOOLEAN);
    elseif (isset($_POST['strict_mode_default'])) $strictMode = (int)filter_var($_POST['strict_mode_default'], FILTER_VALIDATE_BOOLEAN);
    elseif (is_array($prefsInput) && isset($prefsInput['strict_mode_default'])) $strictMode = (int)filter_var($prefsInput['strict_mode_default'], FILTER_VALIDATE_BOOLEAN);

    $breakDuration = null;
    if (isset($input['break_duration'])) $breakDuration = (int)$input['break_duration'];
    elseif (isset($input['short_break_duration'])) $breakDuration = (int)$input['short_break_duration'];
    elseif (isset($_POST['break_duration'])) $breakDuration = (int)$_POST['break_duration'];
    elseif (isset($_POST['short_break_duration'])) $breakDuration = (int)$_POST['short_break_duration'];
    elseif (is_array($prefsInput) && isset($prefsInput['break_duration'])) $breakDuration = (int)$prefsInput['break_duration'];
    elseif (is_array($prefsInput) && isset($prefsInput['short_break_duration'])) $breakDuration = (int)$prefsInput['short_break_duration'];

    $reminderTime = null;
    if (isset($input['reminder_time'])) $reminderTime = trim($input['reminder_time']);
    elseif (isset($_POST['reminder_time'])) $reminderTime = trim($_POST['reminder_time']);
    elseif (is_array($prefsInput) && isset($prefsInput['reminder_time'])) $reminderTime = trim($prefsInput['reminder_time']);

    $existingPrefStmt = $conn->prepare("SELECT * FROM user_preferences WHERE user_id = :uid LIMIT 1");
    $existingPrefStmt->execute([':uid' => $userId]);
    $currPrefs = $existingPrefStmt->fetch();

    $finalDailyGoal = ($dailyGoal !== null) ? max(1, min(720, $dailyGoal)) : ($currPrefs ? (int)$currPrefs['daily_focus_goal_minutes'] : 240);
    $finalFocusDuration = ($focusDuration !== null) ? max(5, min(180, $focusDuration)) : ($currPrefs ? (int)$currPrefs['preferred_focus_duration'] : 45);
    $finalReminders = ($reminders !== null) ? $reminders : ($currPrefs ? (int)$currPrefs['reminders_enabled'] : 1);
    $finalDailyReminder = ($dailyReminder !== null) ? $dailyReminder : ($currPrefs && isset($currPrefs['daily_reminder_enabled']) ? (int)$currPrefs['daily_reminder_enabled'] : 1);
    $finalDeadlineReminder = ($deadlineReminder !== null) ? $deadlineReminder : ($currPrefs && isset($currPrefs['deadline_reminder_enabled']) ? (int)$currPrefs['deadline_reminder_enabled'] : 1);
    $finalBreakReminder = ($breakReminder !== null) ? $breakReminder : ($currPrefs && isset($currPrefs['break_reminder_enabled']) ? (int)$currPrefs['break_reminder_enabled'] : 1);
    $finalStrictMode = ($strictMode !== null) ? $strictMode : ($currPrefs && isset($currPrefs['strict_mode_default']) ? (int)$currPrefs['strict_mode_default'] : 0);
    $finalBreakDuration = ($breakDuration !== null) ? max(1, min(60, $breakDuration)) : ($currPrefs && isset($currPrefs['break_duration']) ? (int)$currPrefs['break_duration'] : 5);
    $finalReminderTime = ($reminderTime !== null && strlen($reminderTime) >= 4) ? $reminderTime : ($currPrefs['reminder_time'] ?? '09:00 AM');

    $upsertPrefSql = "
        INSERT INTO user_preferences (
            user_id, preferred_focus_duration, daily_focus_goal_minutes,
            reminders_enabled, notifications_enabled, daily_reminder_enabled,
            deadline_reminder_enabled, break_reminder_enabled,
            strict_mode_default, break_duration, reminder_time
        ) VALUES (
            :uid, :dur, :goal, :rem, :notif, :daily_rem, :dead_rem, :brk_rem, :strict, :brk_dur, :rem_time
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
    $upsertPrefStmt = $conn->prepare($upsertPrefSql);
    $upsertPrefStmt->execute([
        ':uid'       => $userId,
        ':dur'       => $finalFocusDuration,
        ':goal'      => $finalDailyGoal,
        ':rem'       => $finalReminders,
        ':notif'     => $finalReminders,
        ':daily_rem' => $finalDailyReminder,
        ':dead_rem'  => $finalDeadlineReminder,
        ':brk_rem'   => $finalBreakReminder,
        ':strict'    => $finalStrictMode,
        ':brk_dur'   => $finalBreakDuration,
        ':rem_time'  => $finalReminderTime
    ]);

    $fetchStmt = $conn->prepare("SELECT id, name, email, avatar, bio, created_at FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $userId]);
    $updatedUser = $fetchStmt->fetch();
    $savedAvatar = $updatedUser['avatar'] ?? $avatar;

    $preferencesData = [
        "daily_focus_goal_minutes" => $finalDailyGoal,
        "daily_focus_goal"         => $finalDailyGoal,
        "preferred_focus_duration" => $finalFocusDuration,
        "notifications_enabled"    => $finalReminders,
        "daily_reminder_enabled"   => $finalDailyReminder,
        "deadline_reminder_enabled"=> $finalDeadlineReminder,
        "break_reminder_enabled"   => $finalBreakReminder,
        "strict_mode_default"      => $finalStrictMode,
        "short_break_duration"     => $finalBreakDuration,
        "break_duration"           => $finalBreakDuration,
        "long_break_duration"      => 15,
        "reminder_time"            => $finalReminderTime
    ];

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Profile updated successfully.",
        "data" => [
            "id" => (int)($updatedUser['id'] ?? $userId),
            "name" => $updatedUser['name'] ?? $name,
            "email" => $updatedUser['email'] ?? $email,
            "avatar" => $savedAvatar,
            "avatar_url" => $savedAvatar,
            "bio" => $updatedUser['bio'] ?? $bio,
            "daily_focus_goal" => $finalDailyGoal,
            "daily_focus_goal_minutes" => $finalDailyGoal,
            "preferences" => $preferencesData,
            "created_at" => $updatedUser['created_at'] ?? date("Y-m-d H:i:s")
        ],
        "preferences" => $preferencesData,
        "daily_focus_goal" => $finalDailyGoal,
        "daily_focus_goal_minutes" => $finalDailyGoal,
        "avatar" => $savedAvatar,
        "avatar_url" => $savedAvatar
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
