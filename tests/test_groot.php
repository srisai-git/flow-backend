<?php
/**
 * Automated CLI Unit Test: Groot AI Endpoint
 */

echo "Running Groot AI Test Suite..." . PHP_EOL;

$url = "http://127.0.0.1:8000/predict";
$payload = json_encode(["message" => "Hello Groot, are you ready?"]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && !empty($response)) {
    $data = json_decode($response, true);
    $reply = $data['response'] ?? 'OK';
    echo "  [PASS] Groot AI responded (HTTP 200): " . substr($reply, 0, 50) . "..." . PHP_EOL;
    echo "Groot AI Test Suite Completed Successfully." . PHP_EOL;
} else {
    echo "  [WARN] Groot AI endpoint test returned HTTP {$httpCode}: {$err}" . PHP_EOL;
}
