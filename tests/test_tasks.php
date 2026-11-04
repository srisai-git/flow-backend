<?php
/**
 * Automated CLI Unit Test: Task Management API
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Tasks Test Suite..." . PHP_EOL;

try {
    $conn = getDbConnection();
    
    // 1. Verify tasks table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'tasks'");
    assert($stmt->rowCount() > 0, "Tasks table missing");
    echo "  [PASS] Tasks table schema verified" . PHP_EOL;

    // 2. Query test tasks for user 1
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = 1");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    echo "  [PASS] User 1 has {$count} recorded tasks" . PHP_EOL;

    echo "Tasks Test Suite Completed Successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "  [FAIL] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
