<?php
// tasks/delete_task.php
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
    error_log("Tasks Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId = $input['user_id'] ?? null;
$taskId = $input['task_id'] ?? $input['id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

if (empty($taskId) || !is_numeric($taskId) || (int)$taskId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric task_id is required."]);
    exit;
}

$userId = (int)$userId;
$taskId = (int)$taskId;

try {
    // Enforce strict security isolation: only delete if task belongs to user
    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $taskId, ':user_id' => $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "Task not found or access denied."]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Task deleted successfully.",
        "data"    => [
            "id"      => $taskId,
            "user_id" => $userId
        ]
    ]);
} catch (PDOException $e) {
    error_log("Delete Task Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to delete task."]);
}

