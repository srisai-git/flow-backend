<?php
// run_migrations.php
// Standalone self-executing migration runner for Flow backend.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    $tables = runAllMigrations($conn);

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "All migrations applied successfully.",
        "data"    => [
            "database"       => "flow",
            "tables_checked" => $tables,
            "timestamp"      => date('Y-m-d H:i:s')
        ]
    ]);
} catch (PDOException $e) {
    error_log("Migration execution failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Migration execution failed. Check server logs."
    ]);
}

