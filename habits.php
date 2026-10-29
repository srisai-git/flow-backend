<?php
// habits.php
require_once __DIR__ . '/config/db.php';

// Ensure tables exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `habits` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `title` varchar(255) NOT NULL,
            `icon` varchar(50) NOT NULL DEFAULT 'bolt',
            `frequency` varchar(50) NOT NULL DEFAULT 'daily',
            `streak_count` int(11) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_habits_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `habit_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `habit_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `completed_date` date NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_habit_date` (`habit_id`, `completed_date`),
            KEY `idx_habit_logs_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    error_log("Habits migration error: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$json = getJsonInput();
$input = !empty($json) ? $json : $_REQUEST;

$userId = $input['user_id'] ?? $_GET['user_id'] ?? null;
if (empty($userId) || !is_numeric($userId)) {
    $userId = 1; // Default fallback user
}
$userId = (int)$userId;

if ($method === 'GET') {
    // 1. Fetch habits joined with today's log status
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

    // 2. Compute per-habit statistics (longest streak, total completions)
    $habitsResult = [];
    foreach ($habits as $h) {
        $habitId = (int)$h['id'];

        $logDatesStmt = $conn->prepare("
            SELECT DISTINCT completed_date 
            FROM habit_logs 
            WHERE habit_id = :hid 
            ORDER BY completed_date ASC
        ");
        $logDatesStmt->execute([':hid' => $habitId]);
        $dates = $logDatesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

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

    // 3. Compute Real Overall User Statistics
    // Total habits completed by user
    $totStmt = $conn->prepare("SELECT COUNT(*) FROM habit_logs WHERE user_id = :uid");
    $totStmt->execute([':uid' => $userId]);
    $totalHabitsCompleted = (int)$totStmt->fetchColumn();

    // Total focus minutes from real completed focus sessions
    $focusStmt = $conn->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM focus_sessions WHERE user_id = :uid AND status = 'completed'");
    $focusStmt->execute([':uid' => $userId]);
    $totalFocusMinutes = (int)$focusStmt->fetchColumn();

    // Consecutive active habit streak days (days with at least 1 habit completed)
    $userDatesStmt = $conn->prepare("SELECT DISTINCT completed_date FROM habit_logs WHERE user_id = :uid ORDER BY completed_date DESC");
    $userDatesStmt->execute([':uid' => $userId]);
    $userDates = $userDatesStmt->fetchAll(PDO::FETCH_COLUMN, 0);

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

    // Longest user habit streak
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

    // 4. Real Weekly Chart Data (7 Days: 6 days ago up to today)
    $totalHabitsCount = count($habits);
    $weeklyChartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $dateStr = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));

        $dayStmt = $conn->prepare("SELECT COUNT(DISTINCT habit_id) FROM habit_logs WHERE user_id = :uid AND completed_date = :cdate");
        $dayStmt->execute([':uid' => $userId, ':cdate' => $dateStr]);
        $doneCount = (int)$dayStmt->fetchColumn();

        $rate = ($totalHabitsCount > 0) ? round($doneCount / $totalHabitsCount, 3) : 0.0;
        $weeklyChartData[] = [
            "date" => $dateStr,
            "day" => $dayName,
            "completed_count" => $doneCount,
            "total_count" => $totalHabitsCount,
            "completion_rate" => (float)$rate
        ];
    }

    // 5. Real 4-Week Consistency Data
    $monthlyChartData = [];
    for ($w = 3; $w >= 0; $w--) {
        $startDayOffset = ($w * 7) + 6;
        $endDayOffset = $w * 7;
        $startDate = date('Y-m-d', strtotime("-$startDayOffset days"));
        $endDate = date('Y-m-d', strtotime("-$endDayOffset days"));
        $weekIndex = 4 - $w;

        $wStmt = $conn->prepare("SELECT COUNT(*) FROM habit_logs WHERE user_id = :uid AND completed_date BETWEEN :sdate AND :edate");
        $wStmt->execute([':uid' => $userId, ':sdate' => $startDate, ':edate' => $endDate]);
        $wCompleted = (int)$wStmt->fetchColumn();

        $expected = $totalHabitsCount * 7;
        $wRate = ($expected > 0) ? round(min(1.0, $wCompleted / $expected), 3) : 0.0;
        $monthlyChartData[] = [
            "week_label" => "Week $weekIndex",
            "completed_count" => $wCompleted,
            "completion_rate" => (float)$wRate
        ];
    }

    $sumRates = array_sum(array_column($weeklyChartData, 'completion_rate'));
    $avgConsistency = count($weeklyChartData) > 0 ? round(($sumRates / count($weeklyChartData)) * 100, 1) : 0.0;

    echo json_encode([
        "status" => true,
        "data" => $habitsResult,
        "user_stats" => [
            "current_streak" => $userCurrentStreak,
            "longest_streak" => $userLongestStreak,
            "total_habits_completed" => $totalHabitsCompleted,
            "total_focus_minutes" => $totalFocusMinutes,
            "avg_consistency_percent" => $avgConsistency,
            "weekly_chart_data" => $weeklyChartData,
            "monthly_chart_data" => $monthlyChartData
        ]
    ]);
    exit;
}

if ($method === 'POST') {
    $action = strtolower($input['action'] ?? 'create');

    if ($action === 'toggle') {
        $habitId = (int)($input['habit_id'] ?? $input['id'] ?? 0);
        if ($habitId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => false, "message" => "Valid habit_id is required."]);
            exit;
        }

        // Check if logged today
        $checkStmt = $conn->prepare("SELECT id FROM habit_logs WHERE habit_id = :hid AND completed_date = CURDATE()");
        $checkStmt->execute([':hid' => $habitId]);
        $existingLog = $checkStmt->fetch();

        if ($existingLog) {
            // Untoggle
            $conn->prepare("DELETE FROM habit_logs WHERE id = :lid")->execute([':lid' => $existingLog['id']]);
            $conn->prepare("UPDATE habits SET streak_count = GREATEST(0, streak_count - 1) WHERE id = :hid")->execute([':hid' => $habitId]);
            $isCompleted = false;
        } else {
            // Mark completed
            $conn->prepare("INSERT INTO habit_logs (habit_id, user_id, completed_date) VALUES (:hid, :uid, CURDATE())")
                 ->execute([':hid' => $habitId, ':uid' => $userId]);
            $conn->prepare("UPDATE habits SET streak_count = streak_count + 1 WHERE id = :hid")->execute([':hid' => $habitId]);
            $isCompleted = true;
        }

        // Get updated habit
        $hStmt = $conn->prepare("SELECT streak_count FROM habits WHERE id = :hid");
        $hStmt->execute([':hid' => $habitId]);
        $streak = (int)$hStmt->fetchColumn();

        echo json_encode([
            "status" => true,
            "message" => $isCompleted ? "Habit completed!" : "Habit unchecked.",
            "is_completed_today" => $isCompleted,
            "streak_count" => $streak
        ]);
        exit;
    }

    if ($action === 'delete') {
        $habitId = (int)($input['habit_id'] ?? $input['id'] ?? 0);
        $conn->prepare("DELETE FROM habit_logs WHERE habit_id = :hid")->execute([':hid' => $habitId]);
        $conn->prepare("DELETE FROM habits WHERE id = :hid AND user_id = :uid")->execute([':hid' => $habitId, ':uid' => $userId]);
        echo json_encode(["status" => true, "message" => "Habit deleted."]);
        exit;
    }

    // Default: Create Habit
    $title = trim($input['title'] ?? '');
    $icon = trim($input['icon'] ?? 'bolt');
    $frequency = trim($input['frequency'] ?? 'daily');

    if (empty($title)) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Title is required."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO habits (user_id, title, icon, frequency, streak_count) VALUES (:uid, :title, :icon, :freq, 0)");
    $stmt->execute([
        ':uid' => $userId,
        ':title' => $title,
        ':icon' => $icon,
        ':freq' => $frequency
    ]);

    $newId = (int)$conn->lastInsertId();
    echo json_encode([
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
    exit;
}
?>
