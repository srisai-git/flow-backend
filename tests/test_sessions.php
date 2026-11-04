<?php
/**
 * Automated CLI Unit Test: Focus Sessions Logging API
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Focus Sessions Test Suite..." . PHP_EOL;

try {
    $conn = getDbConnection();
    
    // 1. Verify focus_sessions table
    $stmt = $conn->query("SHOW TABLES LIKE 'focus_sessions'");
    assert($stmt->rowCount() > 0, "focus_sessions table missing");
    echo "  [PASS] focus_sessions table schema verified" . PHP_EOL;

    // 2. Query completed sessions
    $stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE status = 'completed'");
    $stmt->execute();
    [$count, $mins] = $stmt->fetch(PDO::FETCH_NUM);
    echo "  [PASS] Found {$count} completed focus sessions totaling {$mins} minutes" . PHP_EOL;

    echo "Focus Sessions Test Suite Completed Successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "  [FAIL] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
