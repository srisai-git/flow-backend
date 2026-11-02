<?php
// send_fcm.php - FCM Push Notification Dispatcher & Test Dashboard
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = "localhost";
$user = "root";
$pass = "";
$db = "flow";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_GET['api'])) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed"]);
        exit;
    }
}

// Read parameters from POST JSON or Request
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    $input = $_REQUEST;
}

$isApiRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') || !empty($_GET['send']) || !empty($_GET['api']);

if ($isApiRequest) {
    header("Content-Type: application/json; charset=UTF-8");

    $userId = intval($input['user_id'] ?? $input['userId'] ?? 1);
    $title = trim($input['title'] ?? 'FocusFlow Alert');
    $body = trim($input['body'] ?? $input['message'] ?? 'Your focus session is scheduled.');
    $route = trim($input['route'] ?? $input['screen'] ?? 'timer');
    $fcmToken = trim($input['token'] ?? $input['fcm_token'] ?? '');

    // Purge emojis to uphold strict zero-emoji policy
    $title = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $title);
    $body = preg_replace('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{2B55}]/u', '', $body);

    if (empty($fcmToken) && $conn) {
        $stmt = $conn->prepare("SELECT fcm_token, name, email FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $fcmToken = trim($row['fcm_token'] ?? '');
        }
        $stmt->close();
    }

    if (empty($fcmToken)) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "No FCM registration token found for user ID $userId. Launch the Android app on your device to synchronize its token."
        ]);
        exit;
    }

    // Construct FCM Payload
    $fcmPayload = [
        "to" => $fcmToken,
        "priority" => "high",
        "notification" => [
            "title" => $title,
            "body" => $body,
            "sound" => "default",
            "icon" => "ic_stat_flow",
            "color" => "#8B5CF6",
            "click_action" => "FLUTTER_NOTIFICATION_CLICK"
        ],
        "data" => [
            "title" => $title,
            "body" => $body,
            "route" => $route,
            "screen" => $route,
            "timestamp" => date("Y-m-d H:i:s")
        ]
    ];

    // Optional: Firebase Cloud Messaging Legacy Server Key or HTTP v1
    // The key can be set in an environment variable or config
    $serverKey = getenv('FCM_SERVER_KEY') ?: 'AAAA-YOUR-FIREBASE-SERVER-KEY';

    $ch = curl_init("https://fcm.googleapis.com/fcm/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: key=" . $serverKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $fcmResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        "status" => "success",
        "message" => "Notification dispatched",
        "target_user_id" => $userId,
        "token_preview" => substr($fcmToken, 0, 16) . "...",
        "payload" => [
            "title" => $title,
            "body" => $body,
            "route" => $route
        ],
        "fcm_http_code" => $httpCode,
        "fcm_raw_response" => json_decode($fcmResponse, true) ?: $fcmResponse,
        "note" => "Notification payload prepared and dispatched via FCM."
    ]);
    exit;
}

// Render clean HTML developer test console if visited via browser
$usersList = [];
if ($conn) {
    $res = $conn->query("SELECT id, name, email, fcm_token FROM users WHERE fcm_token IS NOT NULL AND fcm_token != '' LIMIT 10");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $usersList[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FocusFlow - FCM Push Notification Console</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #0F0F14;
            color: #F1F1F5;
            padding: 32px 16px;
            margin: 0;
            display: flex;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 560px;
            background: #181722;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.5);
        }
        h1 {
            color: #FFFFFF;
            font-size: 20px;
            margin-top: 0;
            margin-bottom: 8px;
        }
        p {
            color: #9CA3AF;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #D1D5DB;
            margin-bottom: 6px;
        }
        input, select, textarea {
            width: 100%;
            box-sizing: border-box;
            background: #0F0F14;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px;
            color: #F1F1F5;
            padding: 10px 12px;
            font-size: 14px;
            outline: none;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #8B5CF6;
        }
        button {
            width: 100%;
            background: #8B5CF6;
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
        }
        button:hover {
            background: #7C3AED;
        }
        .status-box {
            margin-top: 20px;
            padding: 12px;
            border-radius: 8px;
            background: #0F0F14;
            border: 1px solid rgba(255,255,255,0.08);
            font-size: 13px;
            font-family: monospace;
            white-space: pre-wrap;
            display: none;
        }
        .token-badge {
            background: #252336;
            color: #C4B5FD;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            display: inline-block;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>FocusFlow Notification Console</h1>
        <p>Send real-time FCM push notifications to your device.</p>

        <?php if (!empty($usersList)): ?>
            <div class="token-badge">Registered Devices: <?= count($usersList) ?> found in database</div>
        <?php else: ?>
            <div class="token-badge" style="background: #3B2020; color: #FCA5A5;">No devices synced yet. Launch Android app to register token.</div>
        <?php endif; ?>

        <form id="notifyForm">
            <div class="form-group">
                <label for="userId">Target User</label>
                <select id="userId" name="user_id">
                    <?php if (!empty($usersList)): ?>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (ID: <?= $u['id'] ?>)</option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="1">User 1 (Default)</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="title">Notification Title</label>
                <input type="text" id="title" name="title" value="FocusFlow Deep Work" required>
            </div>

            <div class="form-group">
                <label for="body">Message Body</label>
                <textarea id="body" name="body" rows="2" required>Time to start your focus session. Open your timer to begin.</textarea>
            </div>

            <div class="form-group">
                <label for="route">Deep Link Screen</label>
                <select id="route" name="route">
                    <option value="timer">Focus Timer</option>
                    <option value="tasks">Task Manager</option>
                    <option value="habits">Habit Tracker</option>
                    <option value="insights">Insights & Analytics</option>
                    <option value="notifications">Notification Settings</option>
                </select>
            </div>

            <button type="submit" id="sendBtn">Send Push Notification</button>
        </form>

        <div id="statusBox" class="status-box"></div>
    </div>

    <script>
        document.getElementById('notifyForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('sendBtn');
            const statusBox = document.getElementById('statusBox');
            btn.disabled = true;
            btn.textContent = 'Sending...';
            statusBox.style.display = 'block';
            statusBox.textContent = 'Contacting server...';

            const payload = {
                user_id: parseInt(document.getElementById('userId').value),
                title: document.getElementById('title').value,
                body: document.getElementById('body').value,
                route: document.getElementById('route').value
            };

            try {
                const response = await fetch('send_fcm.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                statusBox.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                statusBox.textContent = 'Error: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Send Push Notification';
            }
        });
    </script>
</body>
</html>
