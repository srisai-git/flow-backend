# FocusFlow Backend API (PHP + PDO + MySQL + Groot AI)

Core backend services and AI engine powering the FocusFlow productivity and focus timer mobile platform.

## Architecture & Directory Structure
```text
flow_backend/
├── auth/                       # User registration and authentication endpoints
├── config/                     # Core system configuration, helpers, and database
│   ├── AuthHelper.php          # Authorization token extraction and validation
│   ├── Cors.php                # CORS and security header middleware
│   ├── DatabaseHelper.php      # Connection verification and table ping utilities
│   ├── Logger.php              # File-based logging utility
│   ├── RateLimiter.php         # Request throttling and rate limiting
│   ├── Response.php            # Unified JSON HTTP response helper
│   ├── UserValidator.php       # Password and username validation
│   ├── Validator.php           # Request schema and sanitization helpers
│   ├── db.php                  # PDO database connection provider
│   └── migrations.php          # Schema migration definitions
├── docs/                       # Comprehensive documentation
│   ├── API.md                  # REST endpoint definitions and payloads
│   ├── DEPLOYMENT.md           # Production deployment guide
│   ├── SECURITY.md             # Security policies and safeguards
│   ├── TESTING.md              # Test suite instructions
│   └── openapi.yaml            # OpenAPI 3.0 API specification
├── groot_ai/                   # Python Groot AI inference engine (Port 8000)
│   ├── latency_check.py        # Latency evaluation tool
│   ├── server.py               # Fast inference server
│   ├── test_benchmarks.py      # Prompt benchmark test suite
│   └── train_groot.py          # Fine-tuning and training scripts
├── migrations/                 # SQL performance and schema migrations
│   └── add_performance_indexes.sql
├── scripts/                    # Maintenance, seed, and backup utilities
│   ├── db_backup.php           # Database backup and export script
│   ├── db_cleanup.php          # Orphaned record cleanup utility
│   └── seed_data.php           # Local test seed data generator
├── tests/                      # Automated unit and integration test suites
│   ├── run_all_tests.php       # Master test runner
│   ├── test_groot.php          # Groot AI test suite
│   ├── test_habits.php         # Habit tracking test suite
│   ├── test_sessions.php       # Focus session test suite
│   └── test_tasks.php          # Task management test suite
├── ask_groot.php               # Proxy endpoint for Groot AI assistant
├── get_dashboard.php           # Aggregated user dashboard endpoint
├── get_profile.php             # User profile information
├── habits.php                  # Habit CRUD operations
├── health.php                  # System health and readiness endpoint
├── log_session.php             # Focus timer session persistence
├── save_session.php            # Alias endpoint for session recording
├── sync_session.php            # Real-time session sync endpoint
├── tasks.php                   # Task management CRUD operations
├── update_profile.php          # User profile update handler
├── CONTRIBUTING.md             # Developer contribution guidelines
├── README.md                   # Project overview and quickstart
└── flow.sql                    # Base MySQL database schema
```

## Quick Start

1. **Start Services**:
   - Ensure Apache and MySQL are running in XAMPP.
2. **Setup Database**:
   - Run `run_migrations.php` or import `flow.sql`.
3. **Start Groot AI Server**:
   ```bash
   cd groot_ai
   python server.py
   ```
4. **Verify Health**:
   - Visit `http://localhost/flow_backend/health.php`
5. **Run Test Suite**:
   ```bash
   php tests/run_all_tests.php
   ```
