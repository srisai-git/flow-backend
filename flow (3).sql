-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2026 at 04:16 AM
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
(4, 10, 'Deep Focus Session', '⚡', 'daily', 0, '2026-10-29 07:12:05'),
(5, 10, 'Read Technical Docs', '📖', 'daily', 0, '2026-10-29 07:12:05'),
(6, 10, 'Daily Code Review', '💻', 'daily', 0, '2026-10-29 07:12:05'),
(7, 10, 'read', 'repeat', 'weekly', 0, '2026-10-29 08:33:20'),
(8, 11, 'djsjej', 'bolt', 'daily', 1, '2026-10-30 07:44:55'),
(9, 12, 'reaf', 'book', 'weekly', 1, '2026-10-31 06:30:04'),
(10, 14, 'ngyjd', 'bolt', 'daily', 1, '2026-11-02 07:05:15'),
(11, 18, 'workout', 'fitness', 'weekdays', 1, '2026-11-04 03:52:17'),
(12, 18, 'Read 20 pages daily', 'bolt', 'daily', 1, '2026-11-04 04:47:44');

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
(14, 8, 11, '2026-10-31', '2026-10-31 05:08:41'),
(15, 9, 12, '2026-10-31', '2026-10-31 06:30:05'),
(16, 10, 14, '2026-11-02', '2026-11-02 07:05:16'),
(19, 11, 18, '2026-11-04', '2026-11-04 04:18:06'),
(20, 12, 18, '2026-11-04', '2026-11-04 04:47:57');

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
(17, 10, 'snskysiy', '', 'Creative', 'high', '0000-00-00', 0, '2026-10-29 09:00:33', '2026-11-04 07:45:47'),
(19, 11, 'editing', '', 'Creative', 'medium', '0000-00-00', 1, '2026-10-30 05:07:58', '2026-10-30 07:52:38'),
(20, 11, 'editing', '', 'Creative', 'medium', '0000-00-00', 0, '2026-10-31 05:10:27', '2026-10-31 05:13:25'),
(21, 12, 'eye5', '', 'Deep Work', 'medium', '0000-00-00', 1, '2026-10-31 06:30:38', '2026-10-31 06:30:39');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `avatar` text DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `fcm_token` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `avatar_url` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `avatar`, `bio`, `email`, `fcm_token`, `phone`, `password`, `otp`, `otp_expiry`, `is_verified`, `created_at`, `avatar_url`) VALUES
(1, 'Sri Sai', NULL, NULL, 'sri.sai@example.com', 'fcm_device_token_sample_test', NULL, 'password123', NULL, NULL, 0, '2026-10-31 05:21:03', NULL),
(3, 'Sai Kumar', 'uploads/user_3_1793076594.jpg', 'B.sc AI&DS Student', 'sai123@gmail.com', NULL, NULL, '$2y$10$QYJEkyZ0MXow.ZguYy3NduR5qqRltacXuPeLD9wWV5Bs7TOD9CCaK', NULL, NULL, 0, '2026-10-26 06:50:22', NULL),
(4, 'dev', NULL, NULL, 'dev321@gmail.com', NULL, NULL, '$2y$10$LnM7wDdwQ.kJF04YBkQrq.x7aAhI1e5xnZfJ3I7R9GWex1fW.VD3S', NULL, NULL, 0, '2026-10-26 06:54:54', NULL),
(5, 'Flow User', NULL, NULL, 'test_flow@example.com', NULL, NULL, '$2y$10$RObxKsnYX6HI3kN7sslcA.bcCr5xhsdHDMA6TNguCer3lB0FZnBtu', NULL, NULL, 0, '2026-10-27 08:33:52', NULL),
(6, 'Sai Kumar', NULL, NULL, 'sai_dev@example.com', NULL, NULL, '$2y$10$8x1jUl2Y7UhyXJXwBeP4WuSeBNMu82udHBtUNaJoLcS5s9WOzpAcK', NULL, NULL, 0, '2026-10-27 09:26:26', NULL),
(7, 'YUVANDHAN', '👤', 'Deep work enthusiast & Computer Science researcher', 'yuvadhamsubah@gmail.com', NULL, NULL, '$2y$10$/oIo/Fm2fRUmjK9HsejCGu2GQ5AN9GZMEoiloajIombAMNV4bv7zK', NULL, NULL, 0, '2026-10-28 02:54:20', NULL),
(8, 'sri sai', NULL, NULL, 'saiprince7329@gmail.com', NULL, NULL, '$2y$10$UIOvK7MeoflFeJ./YAY79uv0D6aOiFu1KFWFCYOEIFbPRh.dUxpTK', NULL, NULL, 0, '2026-10-29 04:34:34', NULL),
(9, 'Kishore', NULL, NULL, 'kishore12345@gmail.com', NULL, NULL, '$2y$10$ysvW124uOxRW.DKZu9z87udxaOgRmHacb46rjd/30W9NQ/ML8ISTu', NULL, NULL, 0, '2026-10-29 04:40:44', NULL),
(10, 'jp', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_10_1793774218.jpg', 'Student', 'jp123@gmail.com', 'fME-7A6mT32_ZmB_rDXgxK:APA91bGfApnDyJl7QLqS_s-rBEK8-drgpZcw5NVAKz51SVQunVCUpKcHraoihXWfoTJof3c1FdrrwAKMTDdStpVcpYqOYGhy2oIvZccuonMBqeeIzdfBhik', NULL, '$2y$10$3DXY9hL/IPLnanX8mepmYeadULQH.uAhn1VgsG/8DDpv6UTJcJbT2', NULL, NULL, 0, '2026-10-29 05:01:43', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_10_1793774218.jpg'),
(11, 'vetri', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_11_1793430298.jpg', 'student', 'vetriff@gmail.com', NULL, NULL, '$2y$10$KiY8tFS2wUcNOEAAGgubiespqPFWujLZRKre.b5veTv6VroDFv3JO', NULL, NULL, 0, '2026-10-30 03:32:04', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_11_1793430298.jpg'),
(12, 'AKASH', 'content://media/picker_get_content/0/com.android.providers.media.photopicker/media/1000071707', 'student', 'user@focusflow.app', NULL, NULL, '$2y$10$eSj9PjYNrGb5qXCwzAjdOuJMX0Dk5mLbDl6j145pdrLHmtZBPBi6e', NULL, NULL, 0, '2026-10-31 06:28:16', 'content://media/picker_get_content/0/com.android.providers.media.photopicker/media/1000071707'),
(13, 'yuvadhan kishore', NULL, NULL, 'vetiff@gmail.com', NULL, NULL, '$2y$10$rf0VVvkdfJEQ7.z68eH6H.uOZC3jFYmu71n7GSIWQ2varj2EV7xPe', NULL, NULL, 0, '2026-10-31 07:06:50', NULL),
(14, 'Ajay', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_14_1793587976.jpg', 'Student', 'ajay123@gmail.com', NULL, NULL, '$2y$10$QAy1qHx1zo4s7runD6KS0OfKhUSfb/Q3U9cOW6JPnpWx8hWB5cmCG', NULL, NULL, 0, '2026-11-02 02:51:05', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_14_1793587976.jpg'),
(15, 'madesh', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_15_1793610510.jpg', 'student', 'gopi123@gmail.com', 'fME-7A6mT32_ZmB_rDXgxK:APA91bGfApnDyJl7QLqS_s-rBEK8-drgpZcw5NVAKz51SVQunVCUpKcHraoihXWfoTJof3c1FdrrwAKMTDdStpVcpYqOYGhy2oIvZccuonMBqeeIzdfBhik', NULL, '$2y$10$S7LLkpe7JnbLgOaWkEVlyenlkkmA/dtTcqwlJvb0HhWA.dmMoCax6', NULL, NULL, 0, '2026-11-02 03:29:53', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_15_1793610510.jpg'),
(16, 'Arul', NULL, NULL, 'arul@gmail.com', 'fME-7A6mT32_ZmB_rDXgxK:APA91bGfApnDyJl7QLqS_s-rBEK8-drgpZcw5NVAKz51SVQunVCUpKcHraoihXWfoTJof3c1FdrrwAKMTDdStpVcpYqOYGhy2oIvZccuonMBqeeIzdfBhik', NULL, '$2y$10$KuXhbtFXdfH8Ex3KDqD1weANrIKOwDk9LTEry7DHXhuYWHhrsi8Iy', NULL, NULL, 0, '2026-11-02 08:38:56', NULL),
(17, 'Pammal kishore', 'avatar:code', 'stu', 'pammal123@gmail.com', 'fME-7A6mT32_ZmB_rDXgxK:APA91bGfApnDyJl7QLqS_s-rBEK8-drgpZcw5NVAKz51SVQunVCUpKcHraoihXWfoTJof3c1FdrrwAKMTDdStpVcpYqOYGhy2oIvZccuonMBqeeIzdfBhik', NULL, '$2y$10$vceqwGTtmc/I0upp8jGAf.mNjl.hOnBV7T8eup0Y4yhmuWGHKXzYa', NULL, NULL, 0, '2026-11-04 03:26:53', 'avatar:code'),
(18, 'jp', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_18_1793768883.jpg', 'learning', 'jp1234@gmail.com', 'fME-7A6mT32_ZmB_rDXgxK:APA91bGfApnDyJl7QLqS_s-rBEK8-drgpZcw5NVAKz51SVQunVCUpKcHraoihXWfoTJof3c1FdrrwAKMTDdStpVcpYqOYGhy2oIvZccuonMBqeeIzdfBhik', NULL, '$2y$10$Fjh0AR1zqomQR/6hwVG7GeHndvE2OjX0Xfm4l6SbCfDGoi4.w9a9y', NULL, NULL, 0, '2026-11-04 03:43:29', 'http://reaction-union-sublevel.ngrok-free.dev/flow_backend/uploads/avatars/user_18_1793768883.jpg');

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
(8, 10, 'Study', 60, 720, 1, '09:00 AM', '2026-11-04 07:46:03'),
(11, 11, 'Creative Work', 25, 180, 1, '09:00 AM', '2026-10-30 03:32:33'),
(13, 1, 'Study', 50, 300, 1, '09:00 AM', '2026-10-31 05:21:48'),
(15, 12, 'Coding & Projects', 60, 720, 1, '09:00 AM', '2026-10-31 06:29:12'),
(20, 13, 'Creative Work', 60, 480, 1, '09:00 AM', '2026-10-31 07:07:16'),
(21, 14, 'Coding & Projects', 25, 240, 1, '09:00 AM', '2026-11-02 02:51:17'),
(22, 15, 'Study', 45, 210, 1, '02:52 PM', '2026-11-02 09:22:43'),
(23, 16, 'Coding & Projects', 45, 240, 1, '09:00 AM', '2026-11-04 04:09:16'),
(36, 17, 'Study & Academics', 60, 720, 1, '09:00 AM', '2026-11-04 03:27:03'),
(37, 18, 'Study', 45, 210, 1, '09:50 AM', '2026-11-04 05:08:52');

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
  ADD KEY `fk_focus_task` (`task_id`),
  ADD KEY `idx_focus_user_created` (`user_id`,`created_at`);

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
  ADD KEY `idx_habit_logs_user` (`user_id`),
  ADD KEY `idx_habit_logs_user_date` (`user_id`,`completed_date`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tasks_user_status` (`user_id`,`is_completed`),
  ADD KEY `idx_tasks_user_due` (`user_id`,`due_date`),
  ADD KEY `idx_tasks_user_completed` (`user_id`,`is_completed`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `habit_logs`
--
ALTER TABLE `habit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `user_preferences`
--
ALTER TABLE `user_preferences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

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
