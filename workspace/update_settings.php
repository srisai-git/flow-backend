<?php
// workspace/update_settings.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/migrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => false, "message" => "Method not allowed. Use POST."]);
    exit;
}

try {
    runAllMigrations($conn);
} catch (PDOException $e) {
    error_log("Workspace Migration Error: " . $e->getMessage());
}

$jsonInput = getJsonInput();
$input = !empty($jsonInput) ? $jsonInput : $_POST;

$userId = $input['user_id'] ?? null;

if (empty($userId) || !is_numeric($userId) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "A valid numeric user_id is required."]);
    exit;
}

$userId = (int)$userId;

// Parse settings
$theme = strtolower(trim($input['theme'] ?? 'system'));
if (!in_array($theme, ['light', 'dark', 'system'], true)) {
    $theme = 'system';
}

function parseBoolSetting($val, int $default = 1): int {
    if ($val === null) return $default;
    $parsed = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return ($parsed !== null) ? ($parsed ? 1 : 0) : (((int)$val === 1) ? 1 : 0);
}

$soundEnabled    = isset($input['sound_enabled']) ? parseBoolSetting($input['sound_enabled'], 1) : 1;
$vibrationEnabled = isset($input['vibration_enabled']) ? parseBoolSetting($input['vibration_enabled'], 1) : 1;
$grootSuggestions = isset($input['groot_suggestions_enabled']) ? parseBoolSetting($input['groot_suggestions_enabled'], 1) : 1;

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    if (!$userStmt->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => false, "message" => "User not found."]);
        exit;
    }

    // 2. Upsert settings
    $upsertSql = "
        INSERT INTO app_settings (user_id, theme, sound_enabled, vibration_enabled, groot_suggestions_enabled)
        VALUES (:user_id, :theme, :sound_enabled, :vibration_enabled, :groot_suggestions_enabled)
        ON DUPLICATE KEY UPDATE
            theme                     = VALUES(theme),
            sound_enabled             = VALUES(sound_enabled),
            vibration_enabled         = VALUES(vibration_enabled),
            groot_suggestions_enabled = VALUES(groot_suggestions_enabled),
            updated_at                = CURRENT_TIMESTAMP
    ";

    $stmt = $conn->prepare($upsertSql);
    $stmt->execute([
        ':user_id'                   => $userId,
        ':theme'                     => $theme,
        ':sound_enabled'             => $soundEnabled,
        ':vibration_enabled'         => $vibrationEnabled,
        ':groot_suggestions_enabled' => $grootSuggestions
    ]);

    // 3. Fetch updated settings
    $fetchStmt = $conn->prepare("SELECT * FROM app_settings WHERE user_id = :user_id LIMIT 1");
    $fetchStmt->execute([':user_id' => $userId]);
    $updated = $fetchStmt->fetch();

    http_response_code(200);
    echo json_encode([
        "status"  => true,
        "message" => "App settings updated successfully.",
        "data"    => [
            "user_id"                   => (int)$updated['user_id'],
            "theme"                     => $updated['theme'],
            "sound_enabled"             => (int)$updated['sound_enabled'],
            "vibration_enabled"         => (int)$updated['vibration_enabled'],
            "groot_suggestions_enabled" => (int)$updated['groot_suggestions_enabled'],
            "updated_at"                => $updated['updated_at']
        ]
    ]);
} catch (PDOException $e) {
    error_log("Update Settings Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Failed to update app settings."]);
}

