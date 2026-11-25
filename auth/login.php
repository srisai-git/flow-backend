<?php
// auth/login.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$data     = getJsonInput();
$email    = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Email and password are required."]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, name, email, avatar, bio, password FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $avatarVal = $user['avatar'] ?? '';
        if (!empty($avatarVal) && strpos($avatarVal, 'avatar:') !== 0 && strpos($avatarVal, 'http') !== 0 && strpos($avatarVal, 'data:') !== 0) {
            if (strpos($avatarVal, 'uploads/') === 0 || strpos($avatarVal, '/uploads/') === 0) {
                $cleanPath = ltrim($avatarVal, '/');
                $serverProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $serverHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
                $avatarVal = $serverProtocol . $serverHost . '/flow_backend/' . $cleanPath;
            }
        }
        $prefStmt = $conn->prepare("SELECT * FROM user_preferences WHERE user_id = :uid LIMIT 1");
        $prefStmt->execute([':uid' => (int)$user['id']]);
        $pref = $prefStmt->fetch();

        $dailyGoal = $pref ? (int)$pref['daily_focus_goal_minutes'] : 240;
        $preferencesData = [
            "daily_focus_goal_minutes" => $dailyGoal,
            "daily_focus_goal"         => $dailyGoal,
            "preferred_focus_duration" => $pref ? (int)$pref['preferred_focus_duration'] : 45,
            "notifications_enabled"    => $pref ? (int)$pref['reminders_enabled'] : 1,
            "daily_reminder_enabled"   => $pref && isset($pref['daily_reminder_enabled']) ? (int)$pref['daily_reminder_enabled'] : 1,
            "deadline_reminder_enabled"=> $pref && isset($pref['deadline_reminder_enabled']) ? (int)$pref['deadline_reminder_enabled'] : 1,
            "break_reminder_enabled"   => $pref && isset($pref['break_reminder_enabled']) ? (int)$pref['break_reminder_enabled'] : 1,
            "strict_mode_default"      => $pref && isset($pref['strict_mode_default']) ? (int)$pref['strict_mode_default'] : 0,
            "short_break_duration"     => $pref && isset($pref['break_duration']) ? (int)$pref['break_duration'] : 5,
            "break_duration"           => $pref && isset($pref['break_duration']) ? (int)$pref['break_duration'] : 5,
            "long_break_duration"      => 15,
            "reminder_time"            => $pref['reminder_time'] ?? '09:00 AM'
        ];

        http_response_code(200);
        echo json_encode([
            "status"      => true,
            "message"     => "Login successful.",
            "user"        => [
                "id"         => (int)$user['id'],
                "name"       => $user['name'],
                "email"      => $user['email'],
                "avatar"     => $avatarVal,
                "avatar_url" => $avatarVal,
                "bio"        => $user['bio'] ?? '',
                "daily_focus_goal" => $dailyGoal,
                "daily_focus_goal_minutes" => $dailyGoal,
                "preferences" => $preferencesData
            ],
            "preferences" => $preferencesData,
            "daily_focus_goal" => $dailyGoal,
            "daily_focus_goal_minutes" => $dailyGoal,
            "avatar"      => $avatarVal,
            "avatar_url"  => $avatarVal
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => false, "message" => "Invalid email or password."]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
