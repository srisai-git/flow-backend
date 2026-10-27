# Flow Backend - Architecture & Changes Log

This document details all database schema additions, module implementations, security standards, and API endpoints for the Flow PHP/MySQL backend.

---

## 1. Database Schema Additions (`flow.sql` & `run_migrations.php`)

All tables are created idempotently via [`run_migrations.php`](file:///c:/xampp/htdocs/flow_backend/run_migrations.php) or self-healing endpoint migration triggers:

### Table 1: `user_preferences`
- **Purpose**: Stores routine onboarding and routine customization preferences ("Make it yours").
- **Columns**:
  - `id` INT(11) AUTO_INCREMENT PRIMARY KEY
  - `user_id` INT(11) NOT NULL UNIQUE (FK &rarr; `users.id` ON DELETE CASCADE)
  - `primary_goal` VARCHAR(100) DEFAULT 'Study'
  - `preferred_focus_duration` INT(11) DEFAULT 45
  - `daily_focus_goal_minutes` INT(11) DEFAULT 210
  - `reminders_enabled` TINYINT(1) DEFAULT 1
  - `reminder_time` VARCHAR(10) DEFAULT '09:00 AM'
  - `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

### Table 2: `tasks`
- **Purpose**: Stores user tasks, priorities, categories, and completion status.
- **Columns**:
  - `id` INT(11) AUTO_INCREMENT PRIMARY KEY
  - `user_id` INT(11) NOT NULL (FK &rarr; `users.id` ON DELETE CASCADE)
  - `title` VARCHAR(255) NOT NULL
  - `description` TEXT DEFAULT NULL
  - `category` VARCHAR(100) DEFAULT 'General'
  - `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium'
  - `due_date` DATE DEFAULT NULL
  - `is_completed` TINYINT(1) DEFAULT 0
  - `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  - `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- **Indexes**: `(user_id, is_completed)`, `(user_id, due_date)`.

### Table 3: `focus_sessions`
- **Purpose**: Logs pomodoro / deep work timer sessions (completed or abandoned).
- **Columns**:
  - `id` INT(11) AUTO_INCREMENT PRIMARY KEY
  - `user_id` INT(11) NOT NULL (FK &rarr; `users.id` ON DELETE CASCADE)
  - `task_id` INT(11) DEFAULT NULL (FK &rarr; `tasks.id` ON DELETE SET NULL)
  - `duration_minutes` INT(11) NOT NULL DEFAULT 25
  - `session_type` VARCHAR(50) DEFAULT 'pomodoro'
  - `status` ENUM('completed', 'abandoned') DEFAULT 'completed'
  - `started_at` DATETIME NOT NULL
  - `completed_at` DATETIME DEFAULT NULL
  - `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- **Indexes**: `(user_id, started_at)`, `(user_id, status)`.

### Table 4: `app_settings`
- **Purpose**: User application preferences and Groot suggestion toggles.
- **Columns**:
  - `id` INT(11) AUTO_INCREMENT PRIMARY KEY
  - `user_id` INT(11) NOT NULL UNIQUE (FK &rarr; `users.id` ON DELETE CASCADE)
  - `theme` ENUM('light', 'dark', 'system') DEFAULT 'system'
  - `sound_enabled` TINYINT(1) DEFAULT 1
  - `vibration_enabled` TINYINT(1) DEFAULT 1
  - `groot_suggestions_enabled` TINYINT(1) DEFAULT 1
  - `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

---

## 2. API Endpoints Catalog

All endpoints return a uniform JSON envelope:
`{"status": true/false, "message": "...", "data": ...}`

### System & Migrations
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/run_migrations.php` | Idempotently verifies and builds all 4 tables with foreign keys and indexes. |

### Authentication (`auth/`)
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/auth/signup.php` | Registers a user with bcrypt hashed password. |
| `POST` | `/auth/login.php` | Authenticates user email/password with timing-safe error response. |
| `POST` | `/auth/reset_password.php` | Updates user password by email. |

### Profile (`profile/`)
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/profile/setup_profile.php` | Updates user name, bio, and uploads avatar image to `uploads/`. |
| `GET` | `/profile/get_profile.php?id=X` | Fetches profile avatar, name, and bio. |

### Routine Preferences (`preferences/`)
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/preferences/save_preferences.php` | Upserts daily routine preferences and optionally updates display name. |
| `GET` | `/preferences/get_preferences.php?user_id=X` | Fetches routine preferences with fallback defaults if unset. |

### Tasks Management (`tasks/`)
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/tasks/create_task.php` | Creates a new task with title, category, priority, and due date. |
| `GET` | `/tasks/get_tasks.php?user_id=X` | Retrieves tasks with optional `is_completed`, `category`, and `priority` filters. |
| `POST` | `/tasks/update_task.php` | Updates task fields or toggles `is_completed`. |
| `POST` | `/tasks/delete_task.php` | Deletes task with strict user ownership isolation. |

### Focus Sessions (`focus/`)
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/focus/log_session.php` | Logs a completed or abandoned timer session linked to an optional task. |
| `GET` | `/focus/get_sessions.php?user_id=X` | Retrieves paginated focus session history with task title joins. |

### Insights & Analytics (`insights/`)
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/insights/get_insights.php?user_id=X` | Aggregates today's focus minutes, weekly minutes, goal progress %, consecutive streak days, task counts, and 7-day chronological chart breakdown. |

### Workspace Dashboard & Settings (`workspace/`)
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/workspace/get_workspace.php?user_id=X` | Consolidated home screen payload: user profile, routine settings, app toggles, today's focus progress, and top recent tasks/sessions. |
| `POST` | `/workspace/update_settings.php` | Upserts theme, sound, vibration, and Groot suggestions toggles. |

---

## 3. Security Standards Enforced

1. **User Isolation**: Every mutation (`UPDATE`, `DELETE`) and query (`SELECT`) enforces `WHERE user_id = :user_id`. A user cannot view, alter, or delete tasks or sessions belonging to another user.
2. **Prepared Statements**: Zero string concatenation in queries; 100% PDO prepared statements with parameter binding.
3. **Information Disclosure Prevention**: Catch blocks log detailed exceptions via `error_log()` and emit sanitised, generic error messages to API consumers.
4. **Guardrail Compliance**: No conversational AI or chat endpoints were created; Groot AI is handled purely as a user preference toggle (`groot_suggestions_enabled`).

