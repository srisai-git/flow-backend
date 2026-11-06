<?php
// streaks.php
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? $_GET['user_id'] ?? null;
if (empty($userId) || !is_numeric($userId)) {
    $userId = 1;
}
$userId = (int)$userId;

try {
    // 1. Gather all unique active dates (from both focus sessions and habit logs)
    $stmt = $conn->prepare("
        SELECT DISTINCT activity_date FROM (
            SELECT DATE(created_at) as activity_date FROM focus_sessions WHERE user_id = :uid1
            UNION
            SELECT completed_date as activity_date FROM habit_logs WHERE user_id = :uid2
        ) combined
        ORDER BY activity_date DESC
    ");
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $currentStreak = 0;
    $longestStreak = 0;
    $totalActiveDays = count($dates);
    $lastActiveDate = $dates[0] ?? null;

    if (!empty($dates)) {
        // Calculate current streak
        $today = new DateTime('today');
        $yesterday = (new DateTime('today'))->modify('-1 day');
        $checkDate = new DateTime($dates[0]);

        // Current streak is alive if latest activity was today or yesterday
        if ($checkDate == $today || $checkDate == $yesterday) {
            $expected = clone $checkDate;
            foreach ($dates as $d) {
                $cur = new DateTime($d);
                if ($cur->format('Y-m-d') === $expected->format('Y-m-d')) {
                    $currentStreak++;
                    $expected->modify('-1 day');
                } else {
                    break;
                }
            }
        }

        // Calculate longest streak historically
        $tempStreak = 0;
        $prevDate = null;
        // Sort ascending for longest streak calculation
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

    // Handle POST updates if supplied
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
            'current_streak' => max(1, $currentStreak),
            'longest_streak' => max(1, $longestStreak),
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

