<?php
/**
 * Database Backup Utility
 * Exports database structure and records to SQL dump file.
 */

require_once __DIR__ . '/../config/db.php';

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

$date = date('Y-m-d_His');
$dumpFile = "{$backupDir}/flow_backup_{$date}.sql";

echo "Starting database backup to: {$dumpFile}..." . PHP_EOL;

try {
    $conn = getDbConnection();
    $tables = [];
    $stmt = $conn->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $handle = fopen($dumpFile, 'w');
    fwrite($handle, "-- FocusFlow Database Backup\n-- Generated on: " . date('c') . "\n\n");

    foreach ($tables as $table) {
        $createStmt = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($handle, "\n\n" . $createStmt[1] . ";\n\n");

        $dataStmt = $conn->query("SELECT * FROM `{$table}`");
        while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $escaped = array_map(function($v) use ($conn) {
                return $v === null ? "NULL" : $conn->quote($v);
            }, $row);
            $line = "INSERT INTO `{$table}` (`" . implode("`, `", array_keys($row)) . "`) VALUES (" . implode(", ", $escaped) . ");\n";
            fwrite($handle, $line);
        }
    }

    fclose($handle);
    echo "Backup completed successfully! Size: " . round(filesize($dumpFile) / 1024, 2) . " KB" . PHP_EOL;
} catch (Throwable $e) {
    echo "Backup failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
