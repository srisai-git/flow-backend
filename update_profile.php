<?php
// update_profile.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

// Fast migration check
$migrationFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow_migrated_v3';
if (!file_exists($migrationFlag)) {
    try {
        runAllMigrations($conn);
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

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

    // Handle user_preferences parameters (reminders_enabled, daily_focus_goal_minutes, preferred_focus_duration)
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

    // Fetch updated user
    $fetchStmt = $conn->prepare("SELECT id, name, email, avatar, bio, created_at FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $userId]);
    $updatedUser = $fetchStmt->fetch();
    $savedAvatar = $updatedUser['avatar'] ?? $avatar;

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
