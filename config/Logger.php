<?php
/**
 * FocusFlow Error & Activity Logger
 * Logs system warnings, API exceptions, and debug traces to file.
 */

class Logger {

    private static string $logDir = __DIR__ . '/../logs';

    private static function ensureLogDirectory(): void {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0777, true);
        }
    }

    public static function log(string $level, string $message, array $context = []): void {
        self::ensureLogDirectory();
        $date = date('Y-m-d');
        $logFile = self::$logDir . "/app-{$date}.log";
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }
}
