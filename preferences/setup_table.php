<?php
// preferences/setup_table.php
require_once __DIR__ . '/../config/db.php';

/**
 * Ensures the user_preferences table exists and has all required columns.
 * Self-healing migration that runs idempotently.
 */
function ensurePreferencesTableExists(PDO $conn): void {
    // 1. Create table if not present
    $createSql = "
    CREATE TABLE IF NOT EXISTS `user_preferences` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `primary_goal` VARCHAR(100) NOT NULL DEFAULT 'Study',
        `preferred_focus_duration` INT(11) NOT NULL DEFAULT 45,
        `daily_focus_goal_minutes` INT(11) NOT NULL DEFAULT 210,
        `reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `reminder_time` VARCHAR(10) NOT NULL DEFAULT '09:00 AM',
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_id` (`user_id`),
        CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    
    $conn->exec($createSql);

    // 2. Check for missing columns (in case table was previously created with an older structure)
    $columnsStmt = $conn->query("SHOW COLUMNS FROM `user_preferences`");
    $existingColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $columnDefinitions = [
        'primary_goal'             => "ALTER TABLE `user_preferences` ADD COLUMN `primary_goal` VARCHAR(100) NOT NULL DEFAULT 'Study' AFTER `user_id`",
        'preferred_focus_duration' => "ALTER TABLE `user_preferences` ADD COLUMN `preferred_focus_duration` INT(11) NOT NULL DEFAULT 45 AFTER `primary_goal`",
        'daily_focus_goal_minutes' => "ALTER TABLE `user_preferences` ADD COLUMN `daily_focus_goal_minutes` INT(11) NOT NULL DEFAULT 210 AFTER `preferred_focus_duration`",
        'reminders_enabled'        => "ALTER TABLE `user_preferences` ADD COLUMN `reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `daily_focus_goal_minutes`",
        'notifications_enabled'    => "ALTER TABLE `user_preferences` ADD COLUMN `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `reminders_enabled`",
        'daily_reminder_enabled'   => "ALTER TABLE `user_preferences` ADD COLUMN `daily_reminder_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notifications_enabled`",
        'deadline_reminder_enabled'=> "ALTER TABLE `user_preferences` ADD COLUMN `deadline_reminder_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `daily_reminder_enabled`",
        'break_reminder_enabled'   => "ALTER TABLE `user_preferences` ADD COLUMN `break_reminder_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `deadline_reminder_enabled`",
        'strict_mode_default'      => "ALTER TABLE `user_preferences` ADD COLUMN `strict_mode_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `break_reminder_enabled`",
        'break_duration'           => "ALTER TABLE `user_preferences` ADD COLUMN `break_duration` INT(11) NOT NULL DEFAULT 5 AFTER `strict_mode_default`",
        'reminder_time'            => "ALTER TABLE `user_preferences` ADD COLUMN `reminder_time` VARCHAR(10) NOT NULL DEFAULT '09:00 AM' AFTER `break_duration`",
        'updated_at'               => "ALTER TABLE `user_preferences` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `reminder_time`"
    ];

    foreach ($columnDefinitions as $col => $alterSql) {
        if (!in_array($col, $existingColumns, true)) {
            $conn->exec($alterSql);
        }
    }
}

// When accessed directly via browser/Postman, execute and return JSON status
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    try {
        ensurePreferencesTableExists($conn);
        http_response_code(200);
        echo json_encode([
            "status"  => true,
            "message" => "Database table 'user_preferences' is verified and ready."
        ]);
    } catch (PDOException $e) {
        error_log("Preferences Migration Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status"  => false,
            "message" => "Failed to setup user_preferences table."
        ]);
    }
}

