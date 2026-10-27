<?php
// profile/setup_profile.php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

// 1. Read Form-Data inputs
$userId = $_POST['user_id'] ?? $_POST['id'] ?? null;
$name   = trim($_POST['name'] ?? '');
$bio    = trim($_POST['bio'] ?? '');

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "User ID is required."]);
    exit;
}

if (empty($name)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Name cannot be empty."]);
    exit;
}

$avatarPath = null;

// 2. Handle Image File Upload (from Camera or Gallery)
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath   = $_FILES['profile_image']['tmp_name'];
    $fileName      = $_FILES['profile_image']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Invalid image format. Allowed: jpg, jpeg, png, webp."]);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Generate unique filename: e.g. user_3_1730000000.jpg
    $newFileName = 'user_' . $userId . '_' . time() . '.' . $fileExtension;
    $targetPath  = $uploadDir . $newFileName;

    if (move_uploaded_file($fileTmpPath, $targetPath)) {
        $avatarPath = 'uploads/' . $newFileName;
    } else {
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Failed to save uploaded image."]);
        exit;
    }
}

// 3. Update MySQL Database
try {
    if ($avatarPath !== null) {
        // If image uploaded, update name, bio, and avatar
        $stmt = $conn->prepare("UPDATE users SET name = :name, bio = :bio, avatar = :avatar WHERE id = :id");
        $stmt->execute([
            ':name'   => $name,
            ':bio'    => !empty($bio) ? $bio : null,
            ':avatar' => $avatarPath,
            ':id'     => $userId
        ]);
    } else {
        // If no new image, retain existing avatar
        $stmt = $conn->prepare("UPDATE users SET name = :name, bio = :bio WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':bio'  => !empty($bio) ? $bio : null,
            ':id'   => $userId
        ]);
    }

    // Return the updated data
    $fetchStmt = $conn->prepare("SELECT id, name, avatar, bio FROM users WHERE id = :id LIMIT 1");
    $fetchStmt->execute([':id' => $userId]);
    $updatedUser = $fetchStmt->fetch();

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "Profile updated successfully.",
        "data"    => [
            "id"     => (int)$updatedUser['id'],
            "name"   => $updatedUser['name'],
            "avatar" => $updatedUser['avatar'],
            "bio"    => $updatedUser['bio']
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>