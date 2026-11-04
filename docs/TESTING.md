# Testing Guidelines for FocusFlow Backend

## 1. Running Automated CLI Tests

Run the complete test suite from the terminal:
```bash
php tests/run_all_tests.php
```

Or run individual test modules:
```bash
php tests/test_tasks.php
php tests/test_habits.php
php tests/test_sessions.php
php tests/test_groot.php
```

---

## 2. Postman Collection Testing

1. Import the Postman collection located in the root folder:
   - `Flow_Backend_Complete.postman_collection.json`
2. Import the environment variables file:
   - `Flow_Environment.postman_environment.json`
3. Execute the collection runner in Postman to test:
   - Authentication flow (Register -> Login -> Store Token)
   - Task CRUD lifecycle
   - Pomodoro timer logging & session verification
   - Dashboard & Insights aggregation

---

## 3. Groot AI Engine Testing

Ensure the Python Groot AI server is active on port 8000:
```bash
cd groot_ai
python server.py
```

Test directly using cURL:
```bash
curl -X POST http://127.0.0.1:8000/predict \
  -H "Content-Type: application/json" \
  -d "{\"message\": \"Hello Groot\"}"
```
