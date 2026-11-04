<?php
/**
 * Simple File-Based Request Rate Limiter
 * Protects public endpoints from brute-force and flood attacks.
 */

class RateLimiter {

    private static string $cacheDir = __DIR__ . '/../logs/ratelimit';

    public static function check(string $key, int $maxRequests = 60, int $decaySeconds = 60): bool {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }

        $hash = md5($key);
        $file = self::$cacheDir . "/rl_{$hash}.json";
        $now = time();

        $data = ['count' => 0, 'reset_time' => $now + $decaySeconds];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $parsed = json_decode($content, true);
            if (is_array($parsed) && isset($parsed['reset_time'])) {
                if ($now < $parsed['reset_time']) {
                    $data = $parsed;
                }
            }
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $data['count'] <= $maxRequests;
    }

    public static function getClientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
