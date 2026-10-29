<?php
// log_session.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Session Migration Error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId          = (int)($input['user_id'] ?? 1);
$durationMinutes = (int)($input['duration_minutes'] ?? 25);
$sessionType     = trim($input['session_type'] ?? 'pomodoro');
$taskId          = !empty($input['task_id']) ? (int)$input['task_id'] : null;
$status          = 'completed';

if ($durationMinutes <= 0) {
    $durationMinutes = 1;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO focus_sessions (user_id, task_id, duration_minutes, session_type, status, started_at, completed_at)
        VALUES (:uid, :tid, :dur, :type, :stat, DATE_SUB(NOW(), INTERVAL :dur MINUTE), NOW())
    ");
    $stmt->execute([
        ':uid'  => $userId,
        ':tid'  => $taskId,
        ':dur'  => $durationMinutes,
        ':type' => $sessionType,
        ':stat' => $status
    ]);

    // Calculate today's total focus minutes
    $sumStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :uid AND DATE(created_at) = CURDATE()
    ");
    $sumStmt->execute([':uid' => $userId]);
    $todayTotalMinutes = (int)$sumStmt->fetchColumn();

    echo json_encode([
        "status" => true,
        "message" => "Focus session logged successfully.",
        "session_id" => (int)$conn->lastInsertId(),
        "duration_minutes" => $durationMinutes,
        "today_total_minutes" => $todayTotalMinutes
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}

