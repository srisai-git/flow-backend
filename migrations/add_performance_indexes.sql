-- Performance Indexes for FocusFlow Backend
-- Improves query times on large focus_sessions, tasks, and habits tables.

-- Index for session history and streak calculations
ALTER TABLE focus_sessions 
    ADD INDEX idx_user_status_started (user_id, status, started_at);

ALTER TABLE focus_sessions 
    ADD INDEX idx_user_created (user_id, created_at);

-- Index for task status filtering and sorting
ALTER TABLE tasks 
    ADD INDEX idx_user_completed_due (user_id, is_completed, due_date);

-- Index for habits user lookups
ALTER TABLE habits 
    ADD INDEX idx_habits_user_archived (user_id, is_archived);

-- Index for user profiles and preferences
ALTER TABLE user_preferences 
    ADD INDEX idx_pref_user_id (user_id);
