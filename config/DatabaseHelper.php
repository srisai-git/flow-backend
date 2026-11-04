<?php
/**
 * Database Helper Utility
 * Provides connection verification, ping, and retry mechanisms.
 */

require_once __DIR__ . '/db.php';

class DatabaseHelper {

    public static function ping(): bool {
        try {
            $conn = getDbConnection();
            $stmt = $conn->query("SELECT 1");
            return $stmt !== false;
        } catch (Throwable $e) {
            error_log("DatabaseHelper::ping failed: " . $e->getMessage());
            return false;
        }
    }

    public static function getServerVersion(): string {
        try {
            $conn = getDbConnection();
            return (string)$conn->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $e) {
            return "Unknown";
        }
    }

    public static function tableExists(string $tableName): bool {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SHOW TABLES LIKE :table_name");
            $stmt->execute([':table_name' => $tableName]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
