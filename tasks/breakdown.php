<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "success" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$rawTitle = $input['task_title'] ?? $input['title'] ?? $input['prompt'] ?? '';
$title = trim($rawTitle);

if (empty($title)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "success" => false, "message" => "Task title is required", "subtasks" => []]);
    exit;
}

$subtasks = [];
$fastApiPayload = json_encode(["task_title" => $title, "title" => $title]);
$ch = curl_init("http://127.0.0.1:8000/breakdown_task");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fastApiPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 4);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response && $httpCode === 200) {
    $decoded = json_decode($response, true);
    if (!empty($decoded['subtasks']) && is_array($decoded['subtasks'])) {
        $subtasks = $decoded['subtasks'];
    }
}

if (count($subtasks) < 3) {
    $subtasks = [
        "Define requirements and scope for " . $title,
        "Draft initial implementation for " . $title,
        "Review, test and verify " . $title,
        "Finalize and mark " . $title . " complete"
    ];
}

echo json_encode([
    "status" => "success",
    "success" => true,
    "task" => $title,
    "subtasks" => array_slice($subtasks, 0, 4)
]);

