# FocusFlow REST API Documentation

Base URL: `http://localhost/flow_backend/`

## Authentication Endpoints

### 1. User Registration
- **Route:** `POST /register.php`
- **Body:**
  ```json
  {
    "username": "user123",
    "email": "user@example.com",
    "password": "securepassword"
  }
  ```

### 2. User Login
- **Route:** `POST /login.php`
- **Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "securepassword"
  }
  ```

---

## Task Management Endpoints

### 1. Get Tasks
- **Route:** `GET /tasks.php?user_id=1`

### 2. Create Task
- **Route:** `POST /tasks.php`
- **Body:**
  ```json
  {
    "user_id": 1,
    "title": "Study algorithms",
    "priority": "high",
    "category": "Education"
  }
  ```

### 3. Toggle Task Completion
- **Route:** `POST /tasks.php`
- **Body:**
  ```json
  {
    "task_id": 12,
    "is_completed": 1
  }
  ```

---

## Pomodoro & Focus Logging

### 1. Log Session
- **Route:** `POST /log_session.php`
- **Body:**
  ```json
  {
    "user_id": 1,
    "duration_minutes": 25,
    "duration_seconds": 1500,
    "session_type": "pomodoro",
    "task_id": 12
  }
  ```

### 2. Get Recent Sessions
- **Route:** `GET /log_session.php?user_id=1&limit=20`

---

## Insights & Analytics

### 1. Get Dashboard Analytics
- **Route:** `GET /insights/get_insights.php?user_id=1`
- **Response:** Daily chart array (last 7 days), hourly focus matrix, distraction distribution.

---

## Groot AI Engine

### 1. Ask Groot AI
- **Route:** `POST /ask_groot.php`
- **Body:**
  ```json
  {
    "message": "Give me a productivity plan for today"
  }
  ```
