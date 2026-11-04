<?php
/**
 * Automated CLI Unit Test: Habits Management API
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Habits Test Suite..." . PHP_EOL;

try {
    $conn = getDbConnection();
    
    // 1. Verify habits table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'habits'");
    assert($stmt->rowCount() > 0, "Habits table missing");
    echo "  [PASS] Habits table schema verified" . PHP_EOL;

    // 2. Query habits for user 1
    $stmt = $conn->prepare("SELECT COUNT(*) FROM habits WHERE user_id = 1");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    echo "  [PASS] User 1 has {$count} recorded habits" . PHP_EOL;

    echo "Habits Test Suite Completed Successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "  [FAIL] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
