<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/migrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Distraction Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId          = $input['user_id'] ?? null;
$reason          = trim($input['reason'] ?? $input['note'] ?? $input['notes'] ?? '');
$category        = trim($input['category'] ?? 'Other');
$durationSeconds = isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : 0;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

if (empty($reason)) {
    $reason = "Focus interruption";
}

$userId = (int)$userId;

try {
    $stmt = $conn->prepare("INSERT INTO `distraction_logs` (`user_id`, `reason`, `category`, `duration_seconds`) VALUES (:user_id, :reason, :category, :duration_seconds)");
    $stmt->execute([
        ':user_id' => $userId,
        ':reason' => $reason,
        ':category' => $category,
        ':duration_seconds' => $durationSeconds
    ]);
    $insertedId = (int)$conn->lastInsertId();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Distraction logged successfully.",
        "id" => $insertedId
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Failed to log distraction: " . $e->getMessage()
    ]);
}

