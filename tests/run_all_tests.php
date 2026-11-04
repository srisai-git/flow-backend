<?php
/**
 * Master Test Suite Runner
 * Executes all automated unit and integration tests for FocusFlow backend.
 */

echo "==========================================" . PHP_EOL;
echo " FocusFlow Automated Test Suite Execution" . PHP_EOL;
echo "==========================================" . PHP_EOL . PHP_EOL;

$tests = [
    'Tasks' => __DIR__ . '/test_tasks.php',
    'Habits' => __DIR__ . '/test_habits.php',
    'Sessions' => __DIR__ . '/test_sessions.php',
    'Groot AI' => __DIR__ . '/test_groot.php'
];

$passed = 0;
$total = count($tests);

foreach ($tests as $name => $file) {
    if (file_exists($file)) {
        echo ">>> Executing {$name} tests..." . PHP_EOL;
        require $file;
        echo PHP_EOL;
        $passed++;
    }
}

echo "==========================================" . PHP_EOL;
echo " Test Summary: {$passed}/{$total} suites executed." . PHP_EOL;
echo "==========================================" . PHP_EOL;
