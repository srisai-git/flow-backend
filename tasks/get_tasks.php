<?php
// tasks/get_tasks.php
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
    error_log("Tasks Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$userId = $_GET['user_id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_POST['id'] ?? $jsonInput['user_id'] ?? $jsonInput['id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;

// Optional filters
$isCompletedFilter = $_GET['is_completed'] ?? $_POST['is_completed'] ?? $jsonInput['is_completed'] ?? null;
$categoryFilter    = trim($_GET['category'] ?? $_POST['category'] ?? $jsonInput['category'] ?? '');
$priorityFilter    = strtolower(trim($_GET['priority'] ?? $_POST['priority'] ?? $jsonInput['priority'] ?? ''));
$searchQuery       = trim($_GET['search'] ?? $_POST['search'] ?? $jsonInput['search'] ?? '');

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Build filtered prepared query
    $sql = "SELECT id, user_id, title, description, category, priority, due_date, is_completed, created_at, updated_at 
            FROM tasks 
            WHERE user_id = :user_id";
    $params = [':user_id' => $userId];

    if ($isCompletedFilter !== null && ($isCompletedFilter === '0' || $isCompletedFilter === '1' || is_numeric($isCompletedFilter))) {
        $sql .= " AND is_completed = :is_completed";
        $params[':is_completed'] = (int)$isCompletedFilter;
    }

    if (!empty($categoryFilter)) {
        $sql .= " AND category = :category";
        $params[':category'] = $categoryFilter;
    }

    if (!empty($priorityFilter) && in_array($priorityFilter, ['low', 'medium', 'high'], true)) {
        $sql .= " AND priority = :priority";
        $params[':priority'] = $priorityFilter;
    }

    if (!empty($searchQuery)) {
        $sql .= " AND (title LIKE :search OR description LIKE :search)";
        $params[':search'] = '%' . $searchQuery . '%';
    }

    $sql .= " ORDER BY is_completed ASC, due_date IS NULL ASC, due_date ASC, created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    $formattedTasks = [];
    foreach ($tasks as $row) {
        $formattedTasks[] = [
            "id"           => (int)$row['id'],
            "user_id"      => (int)$row['user_id'],
            "title"        => $row['title'],
            "description"  => $row['description'] ?? '',
            "category"     => $row['category'],
            "priority"     => $row['priority'],
            "due_date"     => $row['due_date'],
            "is_completed" => (int)$row['is_completed'],
            "created_at"   => $row['created_at'],
            "updated_at"   => $row['updated_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Tasks retrieved successfully.",
        "data"    => $formattedTasks
    ]);
} catch (PDOException $e) {
    error_log("Get Tasks Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to retrieve tasks."]);
}

