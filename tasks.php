<?php
// tasks.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Tasks Migration Error: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? $_GET['user_id'] ?? 1;
$userId = (int)$userId;

if ($method === 'GET') {
    // Return all tasks for the user without fake seeders
    $stmt = $conn->prepare("
        SELECT id, user_id, title, description, category, priority, due_date, is_completed, created_at
        FROM tasks
        WHERE user_id = :uid
        ORDER BY is_completed ASC, FIELD(priority, 'high', 'medium', 'low'), id DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $tasks = $stmt->fetchAll();

    echo json_encode([
        "status" => true,
        "data" => array_map(function($t) {
            return [
                "id" => (int)$t['id'],
                "user_id" => (int)$t['user_id'],
                "title" => $t['title'],
                "description" => $t['description'] ?: '',
                "category" => $t['category'] ?: 'General',
                "priority" => $t['priority'] ?: 'medium',
                "due_date" => $t['due_date'] ?: date('Y-m-d'),
                "is_completed" => (bool)$t['is_completed'],
                "created_at" => $t['created_at']
            ];
        }, $tasks)
    ]);
    exit;
}

if ($method === 'POST') {
    $action = strtolower($input['action'] ?? 'create');

    if ($action === 'toggle') {
        $taskId = (int)($input['task_id'] ?? $input['id'] ?? 0);
        $newStatus = isset($input['is_completed']) ? ($input['is_completed'] ? 1 : 0) : null;

        if ($taskId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Valid task_id is required."]);
            exit;
        }

        if ($newStatus !== null) {
            $stmt = $conn->prepare("UPDATE tasks SET is_completed = :status WHERE id = :id AND user_id = :uid");
            $stmt->execute([':status' => $newStatus, ':id' => $taskId, ':uid' => $userId]);
        } else {
            $stmt = $conn->prepare("UPDATE tasks SET is_completed = 1 - is_completed WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $taskId, ':uid' => $userId]);
        }

        $resStmt = $conn->prepare("SELECT is_completed FROM tasks WHERE id = :id");
        $resStmt->execute([':id' => $taskId]);
        $current = (bool)$resStmt->fetchColumn();

        echo json_encode([
            "status" => true,
            "message" => $current ? "Task marked as completed." : "Task marked as pending.",
            "is_completed" => $current
        ]);
        exit;
    }

    if ($action === 'delete') {
        $taskId = (int)($input['task_id'] ?? $input['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $taskId, ':uid' => $userId]);
        echo json_encode(["status" => true, "message" => "Task deleted."]);
        exit;
    }

    // Default action: Create task
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $priority = strtolower(trim($input['priority'] ?? 'medium'));
    if (!in_array($priority, ['low', 'medium', 'high'])) $priority = 'medium';
    $dueDate = trim($input['due_date'] ?? date('Y-m-d'));
    $category = trim($input['category'] ?? 'General');

    if (empty($title)) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Task title is required."]);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO tasks (user_id, title, description, category, priority, due_date, is_completed)
        VALUES (:uid, :title, :desc, :cat, :pri, :due, 0)
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':title' => $title,
        ':desc' => $description,
        ':cat' => $category,
        ':pri' => $priority,
        ':due' => $dueDate
    ]);

    $newId = (int)$conn->lastInsertId();

    echo json_encode([
        "status" => true,
        "message" => "Task created successfully.",
        "data" => [
            "id" => $newId,
            "user_id" => $userId,
            "title" => $title,
            "description" => $description,
            "category" => $category,
            "priority" => $priority,
            "due_date" => $dueDate,
            "is_completed" => false
        ]
    ]);
    exit;
}

if ($method === 'DELETE') {
    $taskId = (int)($input['task_id'] ?? $input['id'] ?? $_GET['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $taskId, ':uid' => $userId]);
    echo json_encode(["status" => true, "message" => "Task deleted."]);
    exit;
}

