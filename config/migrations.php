<?php
// config/migrations.php
// Centralized idempotent database migration helper for Flow backend.

function runAllMigrations(PDO $conn): array {
    $executed = [];

    // 1. Ensure users table has avatar columns
    try {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(512) DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(512) DEFAULT NULL");
    } catch (PDOException $e) {}

    // 2. Table: user_preferences
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `user_preferences` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL UNIQUE,
            `primary_goal` VARCHAR(100) NOT NULL DEFAULT 'Study',
            `preferred_focus_duration` INT(11) NOT NULL DEFAULT 45,
            `daily_focus_goal_minutes` INT(11) NOT NULL DEFAULT 210,
            `reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `reminder_time` VARCHAR(10) NOT NULL DEFAULT '09:00 AM',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $executed[] = 'user_preferences';

    // 2. Table: tasks
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `tasks` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `category` VARCHAR(100) NOT NULL DEFAULT 'General',
            `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
            `due_date` DATE DEFAULT NULL,
            `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_tasks_user_status` (`user_id`, `is_completed`),
            KEY `idx_tasks_user_due` (`user_id`, `due_date`),
            CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $executed[] = 'tasks';

    // 3. Table: focus_sessions
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `focus_sessions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL,
            `task_id` INT(11) DEFAULT NULL,
            `duration_minutes` INT(11) NOT NULL DEFAULT 25,
            `session_type` VARCHAR(50) NOT NULL DEFAULT 'pomodoro',
            `status` ENUM('completed', 'abandoned') NOT NULL DEFAULT 'completed',
            `started_at` DATETIME NOT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_focus_user_started` (`user_id`, `started_at`),
            KEY `idx_focus_user_status` (`user_id`, `status`),
            CONSTRAINT `fk_focus_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_focus_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $executed[] = 'focus_sessions';

    // 4. Table: app_settings
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `app_settings` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL UNIQUE,
            `theme` ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'system',
            `sound_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `vibration_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `groot_suggestions_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_app_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    $executed[] = 'app_settings';

    // 5. Ensure performance indexes for sub-10ms queries
    try {
        $conn->exec("CREATE INDEX idx_focus_user_created ON `focus_sessions` (`user_id`, `created_at`)");
    } catch (PDOException $e) {}
    try {
        $conn->exec("CREATE INDEX idx_tasks_user_completed ON `tasks` (`user_id`, `is_completed`)");
    } catch (PDOException $e) {}
    try {
        $conn->exec("CREATE INDEX idx_habit_logs_user_date ON `habit_logs` (`user_id`, `completed_date`)");
    } catch (PDOException $e) {}

    return $executed;
}

