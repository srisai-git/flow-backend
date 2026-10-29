<?php
// reset_stats.php
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$input = getJsonInput();
$userId = (int)($_POST['user_id'] ?? $input['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Valid user_id is required."]);
    exit;
}

try {
    // 1. Reset/Delete focus sessions for this user
    $stmt1 = $conn->prepare("DELETE FROM focus_sessions WHERE user_id = :uid");
    $stmt1->execute([':uid' => $userId]);

    // 2. Reset tasks completion or delete
    $stmt2 = $conn->prepare("UPDATE tasks SET is_completed = 0 WHERE user_id = :uid");
    $stmt2->execute([':uid' => $userId]);

    // 3. Reset habit streaks
    $stmt3 = $conn->prepare("UPDATE habits SET streak_count = 0 WHERE user_id = :uid");
    $stmt3->execute([':uid' => $userId]);

    $stmt4 = $conn->prepare("DELETE FROM habit_logs WHERE user_id = :uid");
    $stmt4->execute([':uid' => $userId]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "All focus stats, sessions, and streaks have been reset."
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>

