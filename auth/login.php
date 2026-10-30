<?php
// auth/login.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$data     = getJsonInput();
$email    = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Email and password are required."]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, name, email, avatar, bio, password FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $avatarVal = $user['avatar'] ?? '';
        http_response_code(200);
        echo json_encode([
            "status"  => true,
            "message" => "Login successful.",
            "user"    => [
                "id"         => (int)$user['id'],
                "name"       => $user['name'],
                "email"      => $user['email'],
                "avatar"     => $avatarVal,
                "avatar_url" => $avatarVal,
                "bio"        => $user['bio'] ?? ''
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => false, "message" => "Invalid email or password."]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
