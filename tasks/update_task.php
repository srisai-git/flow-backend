<?php
// tasks/update_task.php
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
    // 1. Strict Security & Isolation: check task ownership
    $checkStmt = $conn->prepare("SELECT * FROM tasks WHERE id = :id AND user_id = :user_id LIMIT 1");
    $checkStmt->execute([':id' => $taskId, ':user_id' => $userId]);
    $existing = $checkStmt->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "Task not found or access denied."]);
        exit;
    }

    // 2. Prepare field updates
    $updates = [];
    $params  = [':id' => $taskId, ':user_id' => $userId];

    if (isset($input['title'])) {
        $title = trim($input['title']);
        if (empty($title)) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Task title cannot be empty."]);
            exit;
        }
        $updates[] = "title = :title";
        $params[':title'] = $title;
    }

    if (array_key_exists('description', $input)) {
        $updates[] = "description = :description";
        $params[':description'] = !empty(trim($input['description'])) ? trim($input['description']) : null;
    }

    if (isset($input['category'])) {
        $updates[] = "category = :category";
        $params[':category'] = !empty(trim($input['category'])) ? trim($input['category']) : 'General';
    }

    if (isset($input['priority'])) {
        $priority = strtolower(trim($input['priority']));
        if (in_array($priority, ['low', 'medium', 'high'], true)) {
            $updates[] = "priority = :priority";
            $params[':priority'] = $priority;
        }
    }

    if (array_key_exists('due_date', $input)) {
        $dueDate = trim($input['due_date']);
        if (!empty($dueDate)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                http_response_code(400);
                echo json_encode(["status" => false, "message" => "Invalid due_date format. Use YYYY-MM-DD."]);
                exit;
            }
            $updates[] = "due_date = :due_date";
            $params[':due_date'] = $dueDate;
        } else {
            $updates[] = "due_date = NULL";
        }
    }

    if (isset($input['is_completed'])) {
        $isCompletedVal = $input['is_completed'];
        $parsedBool = filter_var($isCompletedVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $isCompleted = ($parsedBool !== null) ? ($parsedBool ? 1 : 0) : (((int)$isCompletedVal === 1) ? 1 : 0);
        $updates[] = "is_completed = :is_completed";
        $params[':is_completed'] = $isCompleted;
    }

    if (!empty($updates)) {
        $sql = "UPDATE tasks SET " . implode(", ", $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    }

    // 3. Return updated task
    $fetchStmt = $conn->prepare("SELECT * FROM tasks WHERE id = :id AND user_id = :user_id LIMIT 1");
    $fetchStmt->execute([':id' => $taskId, ':user_id' => $userId]);
    $updated = $fetchStmt->fetch();

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Task updated successfully.",
        "data"    => [
            "id"           => (int)$updated['id'],
            "user_id"      => (int)$updated['user_id'],
            "title"        => $updated['title'],
            "description"  => $updated['description'] ?? '',
            "category"     => $updated['category'],
            "priority"     => $updated['priority'],
            "due_date"     => $updated['due_date'],
            "is_completed" => (int)$updated['is_completed'],
            "created_at"   => $updated['created_at'],
            "updated_at"   => $updated['updated_at']
        ]
    ]);
} catch (PDOException $e) {
    error_log("Update Task Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to update task."]);
}

