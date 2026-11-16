<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/migrations.php';

echo "Running Distraction Logs Test Suite..." . PHP_EOL;

try {
    $conn = getDbConnection();
    runAllMigrations($conn, true);

    $stmt = $conn->query("SHOW TABLES LIKE 'distraction_logs'");
    assert($stmt->rowCount() > 0, "distraction_logs table missing");
    echo "  [PASS] distraction_logs table schema verified" . PHP_EOL;

    $ins = $conn->prepare("INSERT INTO `distraction_logs` (`user_id`, `reason`, `category`, `duration_seconds`) VALUES (1, 'Automated Test Interruption', 'Testing', 120)");
    $ins->execute();
    $id = (int)$conn->lastInsertId();
    assert($id > 0, "Failed to insert distraction log");
    echo "  [PASS] Log distraction insertion verified (ID: {$id})" . PHP_EOL;

    $sel = $conn->prepare("SELECT * FROM `distraction_logs` WHERE id = :id");
    $sel->execute([':id' => $id]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    assert($row !== false && $row['category'] === 'Testing', "Failed to retrieve distraction log");
    echo "  [PASS] Log distraction retrieval verified" . PHP_EOL;

    $del = $conn->prepare("DELETE FROM `distraction_logs` WHERE id = :id");
    $del->execute([':id' => $id]);
    echo "  [PASS] Cleanup completed" . PHP_EOL;

    echo "Distraction Logs Test Suite Completed Successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "  [FAIL] " . $e->getMessage() . PHP_EOL;
    exit(1);
}

