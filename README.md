# Flow Backend API (PHP + PDO + MySQL)

Core backend services for the Flow productivity application.

## Directory Structure
```
flow_backend/
├── auth/
│   ├── login.php
│   ├── reset_password.php
│   └── signup.php
├── config/
│   ├── db.php
│   └── migrations.php
├── focus/
│   ├── get_sessions.php
│   └── log_session.php
├── insights/
│   └── get_insights.php
├── preferences/
│   ├── get_preferences.php
│   ├── save_preferences.php
│   └── setup_table.php
├── profile/
│   ├── get_profile.php
│   └── setup_profile.php
├── tasks/
│   ├── create_task.php
│   ├── delete_task.php
│   ├── get_tasks.php
│   └── update_task.php
├── uploads/
│   └── [uploaded user avatars]
├── workspace/
│   ├── get_workspace.php
│   └── update_settings.php
├── CHANGES.md
├── Flow_Backend_Complete.postman_collection.json
├── flow.sql
└── run_migrations.php
```

## Quick Start
1. Ensure Apache and MySQL are running in XAMPP.
2. Visit `http://localhost/flow_backend/run_migrations.php` in your browser or run via Postman to ensure all tables exist.
3. Import `Flow_Backend_Complete.postman_collection.json` into Postman to test all endpoints.
