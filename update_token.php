<?php
// update_token.php
require_once __DIR__ . '/config/db.php';

$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? null;
$fcmToken = $input['fcm_token'] ?? null;

if (!$userId || !$fcmToken) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing user_id or fcm_token."]);
    exit;
}

$userId = (int)$userId;
$fcmToken = trim($fcmToken);

try {
    // Ensure fcm_token column exists and is TEXT
    try {
        $conn->exec("ALTER TABLE users MODIFY COLUMN fcm_token TEXT");
    } catch (Exception $e) {
        // Column may already be TEXT or alter permission restricted
    }

    $stmt = $conn->prepare("UPDATE users SET fcm_token = :token WHERE id = :id");
    $stmt->execute([
        ':token' => $fcmToken,
        ':id' => $userId
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "FCM token updated successfully.",
        "user_id" => $userId
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
