<?php
// signup.php
require_once "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$data     = getJsonInput();
$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$phone    = trim($data['phone'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($name) || empty($email) || empty($phone) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Name, email, phone, and password are required."]);
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
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1");
    $stmt->execute([':email' => $email, ':phone' => $phone]);

    if ($stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(["status" => false, "message" => "Email or phone number already in use."]);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $insert = $conn->prepare("INSERT INTO users (name, email, phone, password) VALUES (:name, :email, :phone, :password)");
    $insert->execute([
        ':name'     => $name,
        ':email'    => $email,
        ':phone'    => $phone,
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
?>