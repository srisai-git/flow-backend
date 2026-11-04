<?php
/**
 * User Credentials & Password Security Validator
 */

class UserValidator {

    public static function validatePassword(string $password): array {
        $errors = [];
        if (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters long.";
        }
        return $errors;
    }

    public static function validateUsername(string $username): array {
        $errors = [];
        $trimmed = trim($username);
        if (strlen($trimmed) < 3) {
            $errors[] = "Username must be at least 3 characters long.";
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmed)) {
            $errors[] = "Username can only contain alphanumeric characters, underscores, hyphens, and dots.";
        }
        return $errors;
    }
}
