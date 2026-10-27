<?php
// profile/get_profile.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use GET or POST."]);
    exit;
}

$userId = $_GET['id'] ?? $_POST['id'] ?? null;

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "User ID is required."]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, name, avatar, bio FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if ($user) {
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "data"   => [
                "id"     => (int)$user['id'],
                "name"   => $user['name'] ?? '',
                "avatar" => $user['avatar'] ?? '',
                "bio"    => $user['bio'] ?? ''
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>