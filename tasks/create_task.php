<?php
// tasks/create_task.php
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

$userId      = $input['user_id'] ?? null;
$title       = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$category    = trim($input['category'] ?? 'General');
$priority    = strtolower(trim($input['priority'] ?? 'medium'));
$dueDate     = trim($input['due_date'] ?? '');

// Validation
if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;

if (empty($title)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Task title cannot be empty."]);
    exit;
}

$allowedPriorities = ['low', 'medium', 'high'];
if (!in_array($priority, $allowedPriorities, true)) {
    $priority = 'medium';
}

if (!empty($dueDate)) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Invalid due_date format. Use YYYY-MM-DD."]);
        exit;
    }
} else {
    $dueDate = null;
}

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Insert Task
    $stmt = $conn->prepare("
        INSERT INTO tasks (user_id, title, description, category, priority, due_date, is_completed)
        VALUES (:user_id, :title, :description, :category, :priority, :due_date, 0)
    ");
    $stmt->execute([
        ':user_id'     => $userId,
        ':title'       => $title,
        ':description' => !empty($description) ? $description : null,
        ':category'    => !empty($category) ? $category : 'General',
        ':priority'    => $priority,
        ':due_date'    => $dueDate
    ]);

    $taskId = (int)$conn->lastInsertId();

    $fetchStmt = $conn->prepare("SELECT * FROM tasks WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $taskId]);
    $task = $fetchStmt->fetch();

    http_response_code(201);
    echo json_encode([
        "status"  => true,
        "message" => "Task created successfully.",
        "data"    => [
            "id"           => (int)$task['id'],
            "user_id"      => (int)$task['user_id'],
            "title"        => $task['title'],
            "description"  => $task['description'] ?? '',
            "category"     => $task['category'],
            "priority"     => $task['priority'],
            "due_date"     => $task['due_date'],
            "is_completed" => (int)$task['is_completed'],
            "created_at"   => $task['created_at'],
            "updated_at"   => $task['updated_at']
        ]
    ]);
} catch (PDOException $e) {
    error_log("Create Task Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to create task."]);
}

