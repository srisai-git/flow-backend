<?php
// auth/reset_password.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

$data        = getJsonInput();
$email       = trim($data['email'] ?? '');
$newPassword = trim($data['new_password'] ?? '');

if (empty($email) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Email and new password are required."]);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Password must be at least 6 characters."]);
    exit;
}

try {
    // 1. Verify user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found with this email."]);
        exit;
    }

    // 2. Hash and update password
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    $updateStmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
    $updateStmt->execute([
        ':password' => $hashedPassword,
        ':id'       => $user['id']
    ]);

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Password reset successfully. You can now log in."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}