<?php
// log_session.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Session Migration Error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = (int)($_GET['user_id'] ?? 1);
    $limit  = (int)($_GET['limit'] ?? 20);
    if ($limit <= 0 || $limit > 100) $limit = 20;

    try {
        $stmt = $conn->prepare("
            SELECT s.id, s.user_id, s.task_id, s.duration_minutes, s.session_type, s.tag, s.status, s.started_at, s.completed_at, s.created_at,
                   t.title AS task_title, t.category AS task_category
            FROM focus_sessions s
            LEFT JOIN tasks t ON s.task_id = t.id
            WHERE s.user_id = :uid
            ORDER BY s.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => true,
            "data" => array_map(function($s) {
                return [
                    "id" => (int)$s['id'],
                    "user_id" => (int)$s['user_id'],
                    "task_id" => $s['task_id'] !== null ? (int)$s['task_id'] : null,
                    "duration_minutes" => (int)$s['duration_minutes'],
                    "session_type" => $s['session_type'] ?: 'pomodoro',
                    "tag" => $s['tag'] ?: 'General',
                    "status" => $s['status'] ?: 'completed',
                    "started_at" => $s['started_at'] ?: $s['created_at'],
                    "completed_at" => $s['completed_at'] ?: $s['created_at'],
                    "task_title" => $s['task_title'] ?: '',
                    "task_category" => $s['task_category'] ?: ''
                ];
            }, $sessions)
        ]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use GET or POST."]);
    exit;
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId          = (int)($input['user_id'] ?? 1);
$durationMinutes = (int)($input['duration_minutes'] ?? 25);
$sessionType     = trim($input['session_type'] ?? 'pomodoro');
$taskId          = !empty($input['task_id']) ? (int)$input['task_id'] : null;
$tag             = !empty($input['tag']) ? trim($input['tag']) : (!empty($input['task_category']) ? trim($input['task_category']) : 'General');
$status          = !empty($input['status']) && in_array(trim($input['status']), ['completed', 'abandoned']) ? trim($input['status']) : 'completed';

if ($durationMinutes <= 0 && isset($input['duration_seconds'])) {
    $durationMinutes = max(1, (int)round((int)$input['duration_seconds'] / 60));
}
if ($durationMinutes <= 0) {
    $durationMinutes = 1;
}

$now = date('Y-m-d H:i:s');
$completedAt = !empty($input['completed_at']) ? trim((string)$input['completed_at']) : $now;
if (!empty($input['started_at'])) {
    $startedAt = trim((string)$input['started_at']);
} else {
    $startedAt = date('Y-m-d H:i:s', strtotime("-{$durationMinutes} minutes", strtotime($completedAt)));
}

try {
    $dupCheck = $conn->prepare("
        SELECT id FROM focus_sessions 
        WHERE user_id = :uid AND duration_minutes = :dur 
          AND (started_at = :start OR TIMESTAMPDIFF(SECOND, started_at, :start2) BETWEEN -5 AND 5)
        ORDER BY id DESC LIMIT 1
    ");
    $dupCheck->execute([
        ':uid' => $userId,
        ':dur' => $durationMinutes,
        ':start' => $startedAt,
        ':start2' => $startedAt
    ]);
    $existingId = $dupCheck->fetchColumn();
    if ($existingId) {
        $sumStmt = $conn->prepare("
            SELECT COALESCE(SUM(duration_minutes), 0)
            FROM focus_sessions
            WHERE user_id = :uid AND created_at >= CURDATE() AND status = 'completed'
        ");
        $sumStmt->execute([':uid' => $userId]);
        $todayTotalMinutes = (int)$sumStmt->fetchColumn();

        echo json_encode([
            "status" => "success",
            "success" => true,
            "message" => "Focus session already recorded.",
            "session_id" => (int)$existingId,
            "duration_minutes" => $durationMinutes,
            "today_total_minutes" => $todayTotalMinutes
        ]);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO focus_sessions (user_id, task_id, duration_minutes, session_type, tag, status, started_at, completed_at, created_at)
        VALUES (:uid, :tid, :dur, :type, :tag, :stat, :start, :end, NOW())
    ");
    $stmt->execute([
        ':uid'   => $userId,
        ':tid'   => $taskId,
        ':dur'   => $durationMinutes,
        ':type'  => $sessionType,
        ':tag'   => $tag,
        ':stat'  => $status,
        ':start' => $startedAt,
        ':end'   => $completedAt
    ]);

    $sumStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0)
        FROM focus_sessions
        WHERE user_id = :uid AND created_at >= CURDATE() AND status = 'completed'
    ");
    $sumStmt->execute([':uid' => $userId]);
    $todayTotalMinutes = (int)$sumStmt->fetchColumn();

    echo json_encode([
        "status" => "success",
        "success" => true,
        "message" => "Focus session logged successfully.",
        "session_id" => (int)$conn->lastInsertId(),
        "duration_minutes" => $durationMinutes,
        "today_total_minutes" => $todayTotalMinutes
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
