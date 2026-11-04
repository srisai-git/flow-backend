<?php
/**
 * Request Validation Helper
 * Provides schema validation and sanitization for incoming JSON payloads.
 */

class Validator {

    public static function getJsonInput(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function requireFields(array $data, array $required): array {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    public static function sanitizeString(?string $value): string {
        if ($value === null) return '';
        return trim(htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8'));
    }

    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePositiveInt($value): bool {
        return filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) !== false;
    }
}
