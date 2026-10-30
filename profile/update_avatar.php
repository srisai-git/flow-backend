<?php
// profile/update_avatar.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$json = getJsonInput();
$input = !empty($json) ? $json : $_POST;

$userId = (int)($input['user_id'] ?? $input['id'] ?? 0);
$avatar = trim($input['avatar'] ?? $input['avatar_url'] ?? '');

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Valid user_id is required."]);
    exit;
}

if (empty($avatar)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Avatar is required."]);
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
    $stmt->execute([':avatar' => $avatar, ':id' => $userId]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Avatar updated successfully.",
        "avatar" => $avatar,
        "avatar_url" => $avatar
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>

