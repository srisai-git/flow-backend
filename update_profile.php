<?php
// update_profile.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Update Profile Migration Notice: " . $e->getMessage());
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
$avatar = trim($_POST['avatar'] ?? $input['avatar'] ?? '');

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Valid user_id is required."]);
    exit;
}

if (empty($name)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Name cannot be empty."]);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid email address is required."]);
    exit;
}

try {
    // Check if email already exists for another user
    $emailStmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
    $emailStmt->execute([':email' => $email, ':id' => $userId]);
    if ($emailStmt->fetch()) {
        http_response_code(409);
        echo json_encode(["status" => false, "message" => "This email address is already in use by another account."]);
        exit;
    }

    // Build update query dynamically
    $fields = ["name = :name", "email = :email"];
    $params = [
        ':name' => $name,
        ':email' => $email,
        ':id' => $userId
    ];

    if (!empty($bio)) {
        $fields[] = "bio = :bio";
        $params[':bio'] = $bio;
    }
    if (!empty($avatar)) {
        $fields[] = "avatar = :avatar";
        $params[':avatar'] = $avatar;
    }

    $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = :id";
    $updateStmt = $conn->prepare($sql);
    $updateStmt->execute($params);

    // If user_preferences parameters were sent (e.g. reminders_enabled or daily_focus_goal_minutes)
    if (isset($input['reminders_enabled']) || isset($_POST['reminders_enabled'])) {
        $reminders = (int)($input['reminders_enabled'] ?? $_POST['reminders_enabled']);
        $prefStmt = $conn->prepare("
            INSERT INTO user_preferences (user_id, reminders_enabled) 
            VALUES (:uid, :reminders)
            ON DUPLICATE KEY UPDATE reminders_enabled = VALUES(reminders_enabled)
        ");
        $prefStmt->execute([':uid' => $userId, ':reminders' => $reminders]);
    }

    if (isset($input['daily_focus_goal_minutes']) || isset($_POST['daily_focus_goal_minutes'])) {
        $goalMins = (int)($input['daily_focus_goal_minutes'] ?? $_POST['daily_focus_goal_minutes']);
        $prefStmt = $conn->prepare("
            INSERT INTO user_preferences (user_id, daily_focus_goal_minutes) 
            VALUES (:uid, :goal)
            ON DUPLICATE KEY UPDATE daily_focus_goal_minutes = VALUES(daily_focus_goal_minutes)
        ");
        $prefStmt->execute([':uid' => $userId, ':goal' => $goalMins]);
    }

    if (isset($input['preferred_focus_duration']) || isset($_POST['preferred_focus_duration'])) {
        $blockMins = (int)($input['preferred_focus_duration'] ?? $_POST['preferred_focus_duration']);
        $prefStmt = $conn->prepare("
            INSERT INTO user_preferences (user_id, preferred_focus_duration) 
            VALUES (:uid, :block)
            ON DUPLICATE KEY UPDATE preferred_focus_duration = VALUES(preferred_focus_duration)
        ");
        $prefStmt->execute([':uid' => $userId, ':block' => $blockMins]);
    }

    // Fetch updated user to return
    $fetchStmt = $conn->prepare("SELECT id, name, email, avatar, bio, created_at FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $userId]);
    $updatedUser = $fetchStmt->fetch();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Profile updated successfully.",
        "data" => [
            "id" => (int)($updatedUser['id'] ?? $userId),
            "name" => $updatedUser['name'] ?? $name,
            "email" => $updatedUser['email'] ?? $email,
            "avatar" => $updatedUser['avatar'] ?? $avatar,
            "bio" => $updatedUser['bio'] ?? $bio,
            "created_at" => $updatedUser['created_at'] ?? date("Y-m-d H:i:s")
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>

