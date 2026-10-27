<?php
// auth/signup.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$data     = getJsonInput();
$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Name, email, and password are required."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Invalid email format."]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Password must be at least 6 characters."]);
    exit;
}

try {
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);

    if ($stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(["status" => false, "message" => "Email already registered."]);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $insert = $conn->prepare("INSERT INTO users (name, email, password) VALUES (:name, :email, :password)");
    $insert->execute([
        ':name'     => $name,
        ':email'    => $email,
        ':password' => $hashedPassword
    ]);

    http_response_code(201);
    echo json_encode([
        "status"  => true,
        "message" => "User registered successfully.",
        "user_id" => $conn->lastInsertId()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}