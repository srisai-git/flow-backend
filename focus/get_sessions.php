<?php
// focus/get_sessions.php
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
    error_log("Focus Migration Error: " . $e->getMessage());
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
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$statusFilter = strtolower(trim($_GET['status'] ?? $_POST['status'] ?? $jsonInput['status'] ?? ''));
$dateFilter   = trim($_GET['date'] ?? $_POST['date'] ?? $jsonInput['date'] ?? '');

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Query sessions
    $sql = "
        SELECT f.id, f.user_id, f.task_id, t.title AS task_title, f.duration_minutes,
               f.session_type, f.status, f.started_at, f.completed_at, f.created_at
        FROM focus_sessions f
        LEFT JOIN tasks t ON t.id = f.task_id
        WHERE f.user_id = :user_id
    ";
    $params = [':user_id' => $userId];

    if (!empty($statusFilter) && in_array($statusFilter, ['completed', 'abandoned'], true)) {
        $sql .= " AND f.status = :status";
        $params[':status'] = $statusFilter;
    }

    if (!empty($dateFilter) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
        $sql .= " AND DATE(f.started_at) = :date";
        $params[':date'] = $dateFilter;
    }

    $sql .= " ORDER BY f.started_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $sessions = [];
    foreach ($rows as $row) {
        $sessions[] = [
            "id"               => (int)$row['id'],
            "user_id"          => (int)$row['user_id'],
            "task_id"          => $row['task_id'] !== null ? (int)$row['task_id'] : null,
            "task_title"       => $row['task_title'] ?? null,
            "duration_minutes" => (int)$row['duration_minutes'],
            "session_type"     => $row['session_type'],
            "status"           => $row['status'],
            "started_at"       => $row['started_at'],
            "completed_at"     => $row['completed_at'],
            "created_at"       => $row['created_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Focus sessions retrieved successfully.",
        "data"    => $sessions
    ]);
} catch (PDOException $e) {
    error_log("Get Sessions Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to retrieve focus sessions."]);
}

