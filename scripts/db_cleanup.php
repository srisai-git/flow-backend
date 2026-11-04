<?php
/**
 * Database Cleanup Script
 * Cleans up orphaned focus sessions and incomplete records.
 */

require_once __DIR__ . '/../config/db.php';

echo "Running Database Cleanup Utility..." . PHP_EOL;

try {
    $conn = getDbConnection();

    // 1. Delete abandoned zero-duration sessions
    $stmt = $conn->prepare("DELETE FROM focus_sessions WHERE duration_minutes = 0 AND status = 'abandoned'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "  Cleaned {$deleted} abandoned zero-duration sessions." . PHP_EOL;

    // 2. Remove orphaned tasks with invalid user_id
    $stmt = $conn->prepare("DELETE FROM tasks WHERE user_id NOT IN (SELECT id FROM users)");
    $stmt->execute();
    $orphanedTasks = $stmt->rowCount();
    echo "  Cleaned {$orphanedTasks} orphaned tasks." . PHP_EOL;

    echo "Cleanup completed successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "Cleanup failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
