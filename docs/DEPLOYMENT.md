# FocusFlow Backend Production Deployment Guide

## System Requirements
- PHP 8.1 or higher (with `pdo_mysql`, `curl`, `json`, `mbstring` extensions)
- MySQL 8.0 or MariaDB 10.4+
- Python 3.10+ (for Groot AI engine)
- Apache / Nginx Web Server

## Deployment Steps

### 1. Database Setup
1. Create a MySQL database named `flow`.
2. Import the schema using:
   ```bash
   mysql -u root -p flow < flow.sql
   ```
3. Run migrations:
   ```bash
   php run_migrations.php
   ```

### 2. Environment Configuration
Copy `.env.example` to `.env` and set your credentials:
```bash
cp .env.example .env
```

### 3. Groot AI Engine Service
Run the Groot AI background service:
```bash
cd groot_ai
pip install -r requirements.txt
python server.py
```
For production, manage the process using `systemd` or `supervisor`.

### 4. Apache Configuration
Ensure `mod_rewrite` and `mod_headers` are enabled for CORS handling:
```bash
a2enmod rewrite
a2enmod headers
systemctl restart apache2
```
