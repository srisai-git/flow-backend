<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? $_GET['user_id'] ?? null;
if (empty($userId) || !is_numeric($userId)) {
    $userId = 1;
}
$userId = (int)$userId;

try {
    $stmt = $conn->prepare("
        SELECT DISTINCT activity_date FROM (
            SELECT DATE(created_at) as activity_date 
            FROM focus_sessions 
            WHERE user_id = :uid1 AND status = 'completed' AND LOWER(session_type) NOT LIKE '%break%' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            UNION
            SELECT completed_date as activity_date 
            FROM habit_logs 
            WHERE user_id = :uid2 AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            UNION
            SELECT DATE(created_at) as activity_date
            FROM tasks
            WHERE user_id = :uid3 AND is_completed = 1 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
        ) combined
        ORDER BY activity_date DESC
    ");
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $currentStreak = 0;
    $longestStreak = 0;
    $totalActiveDays = count($dates);
    $lastActiveDate = $dates[0] ?? null;

    if (!empty($dates)) {
        $todayStr = (new DateTime('today'))->format('Y-m-d');
        $yesterdayStr = (new DateTime('today'))->modify('-1 day')->format('Y-m-d');

        if ($dates[0] === $todayStr) {
            $expected = new DateTime('today');
            foreach ($dates as $d) {
                if ($d === $expected->format('Y-m-d')) {
                    $currentStreak++;
                    $expected->modify('-1 day');
                } else {
                    break;
                }
            }
        } else if ($dates[0] === $yesterdayStr) {
            $expected = (new DateTime('today'))->modify('-1 day');
            foreach ($dates as $d) {
                if ($d === $expected->format('Y-m-d')) {
                    $currentStreak++;
                    $expected->modify('-1 day');
                } else {
                    break;
                }
            }
        } else {
            $currentStreak = 0;
        }

        $tempStreak = 0;
        $prevDate = null;
        $ascDates = array_reverse($dates);
        foreach ($ascDates as $d) {
            $cur = new DateTime($d);
            if ($prevDate === null) {
                $tempStreak = 1;
            } else {
                $diff = $prevDate->diff($cur)->days;
                if ($diff === 1) {
                    $tempStreak++;
                } else if ($diff > 1) {
                    $tempStreak = 1;
                }
            }
            $prevDate = $cur;
            if ($tempStreak > $longestStreak) {
                $longestStreak = $tempStreak;
            }
        }
    }

    if ($longestStreak < $currentStreak) {
        $longestStreak = $currentStreak;
    }

    if ($method === 'POST') {
        $reportedStreak = (int)($input['streak_count'] ?? 0);
        if ($reportedStreak > $currentStreak) {
            $currentStreak = $reportedStreak;
        }
        if ($currentStreak > $longestStreak) {
            $longestStreak = $currentStreak;
        }
    }

    sendJson([
        'status' => 'success',
        'data' => [
            'user_id' => $userId,
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'total_active_days' => $totalActiveDays,
            'last_active_date' => $lastActiveDate
        ]
    ]);
} catch (Exception $e) {
    sendJson([
        'status' => 'error',
        'message' => $e->getMessage()
    ], 500);
}
