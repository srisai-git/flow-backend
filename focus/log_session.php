<?php
// focus/log_session.php
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
    error_log("Focus Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId          = $input['user_id'] ?? null;
$taskId          = $input['task_id'] ?? null;
$durationMinutes = $input['duration_minutes'] ?? null;
$sessionType     = trim($input['session_type'] ?? 'pomodoro');
$status          = strtolower(trim($input['status'] ?? 'completed'));
$startedAt       = trim($input['started_at'] ?? '');
$completedAt     = trim($input['completed_at'] ?? '');

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

if (empty($durationMinutes) || !is_numeric($durationMinutes) || (int)$durationMinutes <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Duration in minutes must be a positive integer."]);
    exit;
}

$userId          = (int)$userId;
$durationMinutes = (int)$durationMinutes;

if (!in_array($status, ['completed', 'abandoned'], true)) {
    $status = 'completed';
}

$now = time();
if (empty($completedAt)) {
    $completedAt = date('Y-m-d H:i:s', $now);
}
if (empty($startedAt)) {
    $startedAt = date('Y-m-d H:i:s', $now - ($durationMinutes * 60));
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

    // 2. If task_id provided, verify task ownership
    $validTaskId = null;
    if (!empty($taskId) && is_numeric($taskId) && (int)$taskId > 0) {
        $taskCheck = $conn->prepare("SELECT id FROM tasks WHERE id = :id AND user_id = :user_id LIMIT 1");
        $taskCheck->execute([':id' => (int)$taskId, ':user_id' => $userId]);
        if ($taskCheck->fetch()) {
            $validTaskId = (int)$taskId;
        } else {
            http_response_code(404);
            echo json_encode(["status" => false, "message" => "Associated task not found or access denied."]);
            exit;
        }
    }

    // 3. Insert focus session
    $insertStmt = $conn->prepare("
        INSERT INTO focus_sessions (user_id, task_id, duration_minutes, session_type, status, started_at, completed_at)
        VALUES (:user_id, :task_id, :duration_minutes, :session_type, :status, :started_at, :completed_at)
    ");
    $insertStmt->execute([
        ':user_id'          => $userId,
        ':task_id'          => $validTaskId,
        ':duration_minutes' => $durationMinutes,
        ':session_type'     => !empty($sessionType) ? $sessionType : 'pomodoro',
        ':status'           => $status,
        ':started_at'       => $startedAt,
        ':completed_at'     => $completedAt
    ]);

    $sessionId = (int)$conn->lastInsertId();

    $fetchStmt = $conn->prepare("
        SELECT f.*, t.title AS task_title
        FROM focus_sessions f
        LEFT JOIN tasks t ON t.id = f.task_id
        WHERE f.id = :id
        LIMIT 1
    ");
    $fetchStmt->execute([':id' => $sessionId]);
    $session = $fetchStmt->fetch();

    http_response_code(201);
    echo json_encode([
        "status"  => true,
        "message" => "Focus session logged successfully.",
        "data"    => [
            "id"               => (int)$session['id'],
            "user_id"          => (int)$session['user_id'],
            "task_id"          => $session['task_id'] !== null ? (int)$session['task_id'] : null,
            "task_title"       => $session['task_title'] ?? null,
            "duration_minutes" => (int)$session['duration_minutes'],
            "session_type"     => $session['session_type'],
            "status"           => $session['status'],
            "started_at"       => $session['started_at'],
            "completed_at"     => $session['completed_at'],
            "created_at"       => $session['created_at']
        ]
    ]);
} catch (PDOException $e) {
    error_log("Log Session Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to log focus session."]);
}

