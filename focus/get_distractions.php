<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/migrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use GET or POST."]);
    exit;
}

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Distraction Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_POST['id'] ?? $jsonInput['user_id'] ?? $jsonInput['id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;
$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

try {
    $stmt = $conn->prepare("
        SELECT `id`, `user_id`, `reason`, `category`, `duration_seconds`, `created_at`
        FROM `distraction_logs`
        WHERE `user_id` = :user_id
        ORDER BY `id` DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'reason' => (string)$r['reason'],
            'category' => (string)$r['category'],
            'duration_seconds' => (int)$r['duration_seconds'],
            'created_at' => (string)$r['created_at']
        ];
    }, $rows);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Distractions retrieved successfully.",
        "data" => $formatted
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage(),
        "data" => []
    ]);
}

