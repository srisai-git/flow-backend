<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// 1. Read input JSON sent by Android app
$raw_input = file_get_contents("php://input");
$data = json_decode($raw_input, true);
$user_message = trim($data['message'] ?? '');

if (empty($user_message)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Message cannot be empty."]);
    exit;
}

// 2. Call the Python Groot AI microservice running locally
$python_url = "http://127.0.0.1:8000/ask_groot";

$ch = curl_init($python_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["message" => $user_message]));
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// 3. Forward Python's response back to Android
if ($http_code === 200 && $response) {
    echo $response;
} else {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "message" => "Groot server is offline or unreachable.",
        "error" => $curl_error
    ]);
}
?>