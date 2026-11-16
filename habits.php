<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/migrations.php';

try {
    runAllMigrations($conn);
} catch (PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? $_GET['user_id'] ?? null;
if (empty($userId) || !is_numeric($userId)) {
    $userId = 1;
}
$userId = (int)$userId;

if ($method === 'GET') {
    $stmt = $conn->prepare("
        SELECT h.id, h.user_id, h.title, h.icon, h.frequency, h.streak_count,
               CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END AS is_completed_today
        FROM habits h
        LEFT JOIN habit_logs l ON h.id = l.habit_id AND l.completed_date = CURDATE()
        WHERE h.user_id = :uid
        ORDER BY h.id ASC
    ");
    $stmt->execute([':uid' => $userId]);
    $habits = $stmt->fetchAll();

    $allLogsStmt = $conn->prepare("
        SELECT habit_id, completed_date 
        FROM habit_logs 
        WHERE user_id = :uid 
        ORDER BY habit_id ASC, completed_date ASC
    ");
    $allLogsStmt->execute([':uid' => $userId]);
    $logsByHabit = [];
    $userDatesSet = [];
    while ($row = $allLogsStmt->fetch(PDO::FETCH_ASSOC)) {
        $hid = (int)$row['habit_id'];
        $cdate = $row['completed_date'];
        $logsByHabit[$hid][] = $cdate;
        $userDatesSet[$cdate] = true;
    }

    $habitsResult = [];
    foreach ($habits as $h) {
        $habitId = (int)$h['id'];
        $dates = $logsByHabit[$habitId] ?? [];

        $longest = 0;
        $curRun = 0;
        $prevTime = null;
        foreach ($dates as $d) {
            $time = strtotime($d);
            if ($prevTime !== null && ($time - $prevTime) === 86400) {
                $curRun++;
            } else {
                $curRun = 1;
            }
            if ($curRun > $longest) {
                $longest = $curRun;
            }
            $prevTime = $time;
        }
        $longest = max($longest, (int)$h['streak_count']);
        $totalCompletions = count($dates);

        $habitsResult[] = [
            "id" => $habitId,
            "user_id" => (int)$h['user_id'],
            "title" => $h['title'],
            "icon" => $h['icon'] ?: 'bolt',
            "frequency" => $h['frequency'],
            "streak_count" => (int)$h['streak_count'],
            "longest_streak" => $longest,
            "total_completions" => $totalCompletions,
            "is_completed_today" => (bool)$h['is_completed_today']
        ];
    }

    $totalHabitsCompleted = 0;
    foreach ($logsByHabit as $habitLogs) {
        $totalHabitsCompleted += count($habitLogs);
    }

    $focusStmt = $conn->prepare("
        SELECT COALESCE(SUM(duration_minutes), 0) 
        FROM focus_sessions 
        WHERE user_id = :uid AND status = 'completed'
    ");
    $focusStmt->execute([':uid' => $userId]);
    $totalFocusMinutes = (int)$focusStmt->fetchColumn();

    $userDates = array_keys($userDatesSet);
    rsort($userDates);

    $userDateLookup = array_flip($userDates);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $userCurrentStreak = 0;
    $ptr = null;
    if (isset($userDateLookup[$today])) {
        $ptr = strtotime($today);
    } elseif (isset($userDateLookup[$yesterday])) {
        $ptr = strtotime($yesterday);
    }

    if ($ptr !== null) {
        while (true) {
            $fmt = date('Y-m-d', $ptr);
            if (isset($userDateLookup[$fmt])) {
                $userCurrentStreak++;
                $ptr = strtotime('-1 day', $ptr);
            } else {
                break;
            }
        }
    }

    $userDatesAsc = array_reverse($userDates);
    $userLongestStreak = 0;
    $uRun = 0;
    $pTime = null;
    foreach ($userDatesAsc as $d) {
        $t = strtotime($d);
        if ($pTime !== null && ($t - $pTime) === 86400) {
            $uRun++;
        } else {
            $uRun = 1;
        }
        if ($uRun > $userLongestStreak) {
            $userLongestStreak = $uRun;
        }
        $pTime = $t;
    }
    $userLongestStreak = max($userLongestStreak, $userCurrentStreak);

    $totalHabitsCount = count($habits);
    $sevenDaysAgo = date('Y-m-d', strtotime('-6 days'));
    $weeklyStmt = $conn->prepare("
        SELECT completed_date, COUNT(DISTINCT habit_id) as cnt
        FROM habit_logs
        WHERE user_id = :uid AND completed_date >= :startDate
        GROUP BY completed_date
    ");
    $weeklyStmt->execute([':uid' => $userId, ':startDate' => $sevenDaysAgo]);
    $weeklyMap = $weeklyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $weeklyChartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $dateStr = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));
        $doneCount = (int)($weeklyMap[$dateStr] ?? 0);
        $rate = ($totalHabitsCount > 0) ? round($doneCount / $totalHabitsCount, 3) : 0.0;
        $weeklyChartData[] = [
            "date" => $dateStr,
            "day" => $dayName,
            "completed_count" => $doneCount,
            "total_count" => $totalHabitsCount,
            "completion_rate" => (float)$rate
        ];
    }

    $twentyEightDaysAgo = date('Y-m-d', strtotime('-27 days'));
    $monthlyStmt = $conn->prepare("
        SELECT completed_date, COUNT(*) as cnt
        FROM habit_logs
        WHERE user_id = :uid AND completed_date >= :startDate
        GROUP BY completed_date
    ");
    $monthlyStmt->execute([':uid' => $userId, ':startDate' => $twentyEightDaysAgo]);
    $monthlyDayMap = $monthlyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $monthlyChartData = [];
    for ($w = 3; $w >= 0; $w--) {
        $weekIndex = 4 - $w;
        $wCompleted = 0;
        for ($d = 0; $d < 7; $d++) {
            $dayOffset = ($w * 7) + $d;
            $dayStr = date('Y-m-d', strtotime("-$dayOffset days"));
            $wCompleted += (int)($monthlyDayMap[$dayStr] ?? 0);
        }
        $expected = $totalHabitsCount * 7;
        $wRate = ($expected > 0) ? round(min(1.0, $wCompleted / $expected), 3) : 0.0;
        $monthlyChartData[] = [
            "week_label" => "Week $weekIndex",
            "completed_count" => $wCompleted,
            "total_expected" => $expected,
            "completion_rate" => (float)$wRate
        ];
    }

    $avgConsistency = 0.0;
    if (!empty($weeklyChartData)) {
        $sum = 0.0;
        foreach ($weeklyChartData as $wd) {
            $sum += $wd['completion_rate'];
        }
        $avgConsistency = round(($sum / count($weeklyChartData)) * 100.0, 1);
    }

    sendJson([
        "status" => true,
        "data" => $habitsResult,
        "user_stats" => [
            "total_habits_completed" => $totalHabitsCompleted,
            "total_focus_minutes" => $totalFocusMinutes,
            "current_streak_days" => $userCurrentStreak,
            "longest_streak_days" => $userLongestStreak,
            "avg_consistency_percent" => (float)$avgConsistency,
            "weekly_chart_data" => $weeklyChartData,
            "monthly_chart_data" => $monthlyChartData
        ]
    ]);
}

if ($method === 'POST') {
    $action = strtolower($input['action'] ?? 'toggle');

    if ($action === 'create') {
        $title = trim($input['title'] ?? '');
        $icon = trim($input['icon'] ?? 'bolt');
        $frequency = trim($input['frequency'] ?? 'daily');

        if (empty($title)) {
            sendJson(["status" => false, "message" => "Title is required."], 400);
        }

        $stmt = $conn->prepare("INSERT INTO habits (user_id, title, icon, frequency, streak_count) VALUES (:uid, :title, :icon, :freq, 0)");
        $stmt->execute([
            ':uid' => $userId,
            ':title' => $title,
            ':icon' => $icon,
            ':freq' => $frequency
        ]);

        $newId = (int)$conn->lastInsertId();
        sendJson([
            "status" => true,
            "message" => "Habit created successfully.",
            "data" => [
                "id" => $newId,
                "user_id" => $userId,
                "title" => $title,
                "icon" => $icon,
                "frequency" => $frequency,
                "streak_count" => 0,
                "longest_streak" => 0,
                "total_completions" => 0,
                "is_completed_today" => false
            ]
        ]);
    }

    if ($action === 'delete') {
        $habitId = (int)($input['habit_id'] ?? $input['id'] ?? 0);
        if ($habitId <= 0) {
            sendJson(["status" => false, "message" => "Valid habit_id is required."], 400);
        }

        $stmt = $conn->prepare("DELETE FROM habits WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $habitId, ':uid' => $userId]);

        $stmtLog = $conn->prepare("DELETE FROM habit_logs WHERE habit_id = :hid AND user_id = :uid");
        $stmtLog->execute([':hid' => $habitId, ':uid' => $userId]);

        sendJson([
            "status" => true,
            "message" => "Habit deleted successfully."
        ]);
    }

    $habitId = (int)($input['habit_id'] ?? $input['id'] ?? 0);
    if ($habitId <= 0) {
        sendJson(["status" => false, "message" => "Valid habit_id is required."], 400);
    }

    $today = date('Y-m-d');
    $checkStmt = $conn->prepare("SELECT id FROM habit_logs WHERE habit_id = :hid AND completed_date = :cdate LIMIT 1");
    $checkStmt->execute([':hid' => $habitId, ':cdate' => $today]);
    $existing = $checkStmt->fetch();

    $newStreak = 0;
    if ($existing) {
        $delStmt = $conn->prepare("DELETE FROM habit_logs WHERE id = :id");
        $delStmt->execute([':id' => $existing['id']]);

        $upStmt = $conn->prepare("UPDATE habits SET streak_count = GREATEST(0, streak_count - 1) WHERE id = :id");
        $upStmt->execute([':id' => $habitId]);

        $sStmt = $conn->prepare("SELECT streak_count FROM habits WHERE id = :id");
        $sStmt->execute([':id' => $habitId]);
        $newStreak = (int)$sStmt->fetchColumn();

        sendJson([
            "status" => true,
            "is_completed" => false,
            "streak_count" => $newStreak,
            "message" => "Habit marked as incomplete."
        ]);
    } else {
        $insStmt = $conn->prepare("INSERT INTO habit_logs (habit_id, user_id, completed_date) VALUES (:hid, :uid, :cdate)");
        $insStmt->execute([':hid' => $habitId, ':uid' => $userId, ':cdate' => $today]);

        $upStmt = $conn->prepare("UPDATE habits SET streak_count = streak_count + 1 WHERE id = :id");
        $upStmt->execute([':id' => $habitId]);

        $sStmt = $conn->prepare("SELECT streak_count FROM habits WHERE id = :id");
        $sStmt->execute([':id' => $habitId]);
        $newStreak = (int)$sStmt->fetchColumn();

        sendJson([
            "status" => true,
            "is_completed" => true,
            "streak_count" => $newStreak,
            "message" => "Habit marked as complete!"
        ]);
    }
}
