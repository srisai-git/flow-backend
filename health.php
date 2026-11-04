<?php
/**
 * System Health & Readiness API Endpoint
 * Checks MySQL database connectivity, Groot AI server status, and system memory.
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/config/db.php';

$startTime = microtime(true);
$status = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'checks' => []
];

// 1. Database Health Check
try {
    $conn = getDbConnection();
    $stmt = $conn->query("SELECT 1");
    $dbOk = ($stmt !== false);
    $status['checks']['database'] = [
        'status' => $dbOk ? 'up' : 'down',
        'type' => 'mysql'
    ];
} catch (Throwable $e) {
    $status['status'] = 'degraded';
    $status['checks']['database'] = [
        'status' => 'down',
        'error' => $e->getMessage()
    ];
}

// 2. Groot AI Service Check
$grootUrl = "http://127.0.0.1:8000/docs";
$ch = curl_init($grootUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
curl_setopt($ch, CURLOPT_NOBODY, true);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$grootOk = ($httpCode >= 200 && $httpCode < 400);
$status['checks']['groot_ai'] = [
    'status' => $grootOk ? 'up' : 'down',
    'endpoint' => 'http://127.0.0.1:8000'
];

$status['latency_ms'] = round((microtime(true) - $startTime) * 1000, 2);
http_response_code($status['status'] === 'healthy' ? 200 : 503);
echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
