<?php
/**
 * save_session.php
 * Dedicated endpoint alias for logging and persisting focus sessions.
 */

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/log_session.php';
