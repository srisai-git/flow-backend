-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 30, 2026 at 04:19 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `flow`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `theme` enum('light','dark','system') NOT NULL DEFAULT 'system',
  `sound_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `vibration_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `groot_suggestions_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`id`, `user_id`, `theme`, `sound_enabled`, `vibration_enabled`, `groot_suggestions_enabled`, `updated_at`) VALUES
(1, 3, 'light', 1, 1, 1, '2026-10-28 03:43:22');

-- --------------------------------------------------------

--
-- Table structure for table `focus_sessions`
--

CREATE TABLE `focus_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 25,
  `session_type` varchar(50) NOT NULL DEFAULT 'pomodoro',
  `status` enum('completed','abandoned') NOT NULL DEFAULT 'completed',
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `focus_sessions`
--

INSERT INTO `focus_sessions` (`id`, `user_id`, `task_id`, `duration_minutes`, `session_type`, `status`, `started_at`, `completed_at`, `created_at`) VALUES
(1, 3, NULL, 45, 'deep_work', 'completed', '2026-10-27 09:42:47', '2026-10-27 10:27:47', '2026-10-27 09:27:47'),
(2, 3, NULL, 15, 'pomodoro', 'abandoned', '2026-10-27 10:12:50', '2026-10-27 10:27:50', '2026-10-27 09:27:50'),
(3, 7, NULL, 18, 'deep_work', 'completed', '2026-10-28 04:16:04', '2026-10-28 04:34:04', '2026-10-28 03:34:04'),
(4, 7, NULL, 3, 'pomodoro', 'abandoned', '2026-10-28 04:31:43', '2026-10-28 04:34:43', '2026-10-28 03:34:43');

-- --------------------------------------------------------

--
-- Table structure for table `habits`
--

CREATE TABLE `habits` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `icon` varchar(50) NOT NULL DEFAULT '⚡',
  `frequency` varchar(50) NOT NULL DEFAULT 'daily',
  `streak_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `habits`
--

INSERT INTO `habits` (`id`, `user_id`, `title`, `icon`, `frequency`, `streak_count`, `created_at`) VALUES
(1, 7, 'Deep Focus Session', '⚡', 'daily', 5, '2026-10-29 07:07:41'),
(2, 7, 'Read Technical Docs', '📖', 'daily', 3, '2026-10-29 07:07:41'),
(3, 7, 'Daily Code Review', '💻', 'daily', 2, '2026-10-29 07:07:41'),
(4, 10, 'Deep Focus Session', '⚡', 'daily', 1, '2026-10-29 07:12:05'),
(5, 10, 'Read Technical Docs', '📖', 'daily', 1, '2026-10-29 07:12:05'),
(6, 10, 'Daily Code Review', '💻', 'daily', 0, '2026-10-29 07:12:05'),
(7, 10, 'read', 'repeat', 'weekly', 0, '2026-10-29 08:33:20');

-- --------------------------------------------------------

--
-- Table structure for table `habit_logs`
--

CREATE TABLE `habit_logs` (
  `id` int(11) NOT NULL,
  `habit_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `completed_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `habit_logs`
--

INSERT INTO `habit_logs` (`id`, `habit_id`, `user_id`, `completed_date`, `created_at`) VALUES
(10, 4, 10, '2026-10-29', '2026-10-29 08:58:43'),
(11, 5, 10, '2026-10-29', '2026-10-29 08:58:43');

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'General',
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `due_date` date DEFAULT NULL,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`id`, `user_id`, `title`, `description`, `category`, `priority`, `due_date`, `is_completed`, `created_at`, `updated_at`) VALUES
(2, 3, 'Implement Flow Backend Endpoints', 'Build and verify all 5 core modules with PDO', 'Development', 'high', '2026-10-30', 0, '2026-10-28 03:09:44', '2026-10-28 03:09:44'),
(7, 7, 'Complete FocusFlow MVVM Architecture', 'Connect Jetpack Compose with Retrofit backend', 'Coding', 'high', '2026-10-29', 1, '2026-10-29 07:09:02', '2026-10-29 07:09:08'),
(8, 7, 'Review Pomodoro Session Cycles', 'Ensure 25m focus and 5m short break rhythm works', 'Focus', 'medium', '2026-10-29', 1, '2026-10-29 07:09:02', '2026-10-29 07:09:10'),
(9, 7, 'Weekly Strategy Planning', 'Set intentions for the upcoming project sprint', 'Planning', 'low', '2026-10-30', 1, '2026-10-29 07:09:02', '2026-10-29 07:09:13'),
(17, 10, 'snskysiy', '', 'Creative', 'high', '0000-00-00', 1, '2026-10-29 09:00:33', '2026-10-29 09:00:38');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `avatar`, `bio`, `email`, `phone`, `password`, `otp`, `otp_expiry`, `is_verified`, `created_at`) VALUES
(3, 'Sai Kumar', 'uploads/user_3_1793076594.jpg', 'B.sc AI&DS Student', 'sai123@gmail.com', NULL, '$2y$10$QYJEkyZ0MXow.ZguYy3NduR5qqRltacXuPeLD9wWV5Bs7TOD9CCaK', NULL, NULL, 0, '2026-10-26 06:50:22'),
(4, 'dev', NULL, NULL, 'dev321@gmail.com', NULL, '$2y$10$LnM7wDdwQ.kJF04YBkQrq.x7aAhI1e5xnZfJ3I7R9GWex1fW.VD3S', NULL, NULL, 0, '2026-10-26 06:54:54'),
(5, 'Flow User', NULL, NULL, 'test_flow@example.com', NULL, '$2y$10$RObxKsnYX6HI3kN7sslcA.bcCr5xhsdHDMA6TNguCer3lB0FZnBtu', NULL, NULL, 0, '2026-10-27 08:33:52'),
(6, 'Sai Kumar', NULL, NULL, 'sai_dev@example.com', NULL, '$2y$10$8x1jUl2Y7UhyXJXwBeP4WuSeBNMu82udHBtUNaJoLcS5s9WOzpAcK', NULL, NULL, 0, '2026-10-27 09:26:26'),
(7, 'YUVANDHAN', '👤', 'Deep work enthusiast & Computer Science researcher', 'yuvadhamsubah@gmail.com', NULL, '$2y$10$/oIo/Fm2fRUmjK9HsejCGu2GQ5AN9GZMEoiloajIombAMNV4bv7zK', NULL, NULL, 0, '2026-10-28 02:54:20'),
(8, 'sri sai', NULL, NULL, 'saiprince7329@gmail.com', NULL, '$2y$10$UIOvK7MeoflFeJ./YAY79uv0D6aOiFu1KFWFCYOEIFbPRh.dUxpTK', NULL, NULL, 0, '2026-10-29 04:34:34'),
(9, 'Kishore', NULL, NULL, 'kishore12345@gmail.com', NULL, '$2y$10$ysvW124uOxRW.DKZu9z87udxaOgRmHacb46rjd/30W9NQ/ML8ISTu', NULL, NULL, 0, '2026-10-29 04:40:44'),
(10, 'Sri Sai', 'content://media/picker_get_content/0/com.android.providers.media.photopicker/media/45337', 'Student', 'jp123@gmail.com', NULL, '$2y$10$3DXY9hL/IPLnanX8mepmYeadULQH.uAhn1VgsG/8DDpv6UTJcJbT2', NULL, NULL, 0, '2026-10-29 05:01:43');

-- --------------------------------------------------------

--
-- Table structure for table `user_preferences`
--

CREATE TABLE `user_preferences` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `primary_goal` varchar(100) NOT NULL DEFAULT 'Study',
  `preferred_focus_duration` int(11) NOT NULL DEFAULT 45,
  `daily_focus_goal_minutes` int(11) NOT NULL DEFAULT 210,
  `reminders_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `reminder_time` varchar(10) NOT NULL DEFAULT '09:00 AM',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_preferences`
--

INSERT INTO `user_preferences` (`id`, `user_id`, `primary_goal`, `preferred_focus_duration`, `daily_focus_goal_minutes`, `reminders_enabled`, `reminder_time`, `updated_at`) VALUES
(1, 3, 'Work', 45, 210, 1, '09:00 AM', '2026-10-27 09:27:25'),
(3, 7, 'Work', 60, 120, 1, '06:00 AM', '2026-10-28 03:07:13'),
(8, 10, '💻 Coding & Projects', 50, 180, 1, '09:00 AM', '2026-10-29 05:02:50');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_focus_user_started` (`user_id`,`started_at`),
  ADD KEY `idx_focus_user_status` (`user_id`,`status`),
  ADD KEY `fk_focus_task` (`task_id`);

--
-- Indexes for table `habits`
--
ALTER TABLE `habits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_habits_user` (`user_id`);

--
-- Indexes for table `habit_logs`
--
ALTER TABLE `habit_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_habit_date` (`habit_id`,`completed_date`),
  ADD KEY `idx_habit_logs_user` (`user_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tasks_user_status` (`user_id`,`is_completed`),
  ADD KEY `idx_tasks_user_due` (`user_id`,`due_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `habits`
--
ALTER TABLE `habits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `habit_logs`
--
ALTER TABLE `habit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_preferences`
--
ALTER TABLE `user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD CONSTRAINT `fk_app_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `focus_sessions`
--
ALTER TABLE `focus_sessions`
  ADD CONSTRAINT `fk_focus_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_focus_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
