<?php
/**
 * Development Seed Data Script
 * Seeds tasks and habits for local sandbox testing.
 */

require_once __DIR__ . '/../config/db.php';

echo "Seeding Development Data..." . PHP_EOL;

try {
    $conn = getDbConnection();
    $userId = 1;

    // Seed sample tasks if user exists
    $tasks = [
        ['Read research paper on LLMs', 'high', 'Study'],
        ['Complete Android Focus Timer module', 'urgent', 'Work'],
        ['Review Weekly Analytics report', 'medium', 'Productivity']
    ];

    $stmt = $conn->prepare("INSERT INTO tasks (user_id, title, priority, category, is_completed) VALUES (?, ?, ?, ?, 0)");
    foreach ($tasks as $t) {
        $stmt->execute([$userId, $t[0], $t[1], $t[2]]);
    }
    echo "  Seeded sample tasks for user {$userId}." . PHP_EOL;

    echo "Seed completed successfully." . PHP_EOL;
} catch (Throwable $e) {
    echo "Seed skipped or failed: " . $e->getMessage() . PHP_EOL;
}
