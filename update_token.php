<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$host = "localhost";
$user = "root";
$pass = "";
$db = "flow";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['user_id']) || !isset($data['fcm_token'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing user_id or fcm_token"]);
    exit;
}

$user_id = intval($data['user_id']);
$fcm_token = trim($data['fcm_token']);

$stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
$stmt->bind_param("si", $fcm_token, $user_id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Token updated successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Update failed: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>