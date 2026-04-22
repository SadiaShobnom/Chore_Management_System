<?php
/**
 * Authentication & Session Helpers
 * ChoreQuest
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/**
 * Generate a CSRF token and store it in the session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from a form submission.
 */
function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden CSRF input field for forms.
 */
function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Require the user to be logged in as a parent.
 * Redirects to login page if not authenticated.
 */
function requireParent(): void {
    if (!isset($_SESSION['parent_id'])) {
        header('Location: ' . BASE_URL);
        exit;
    }
}

/**
 * Require the user to be logged in as a kid.
 * Redirects to kid login page if not authenticated.
 */
function requireKid(): void {
    if (!isset($_SESSION['kid_id'])) {
        header('Location: ' . BASE_URL . '?type=kid');
        exit;
    }
}

/**
 * Check if the current user is a logged-in parent.
 */
function isParent(): bool {
    return isset($_SESSION['parent_id']);
}

/**
 * Check if the current user is a logged-in kid.
 */
function isKid(): bool {
    return isset($_SESSION['kid_id']);
}

/**
 * Get the current parent ID or null.
 */
function getParentId(): ?int {
    return $_SESSION['parent_id'] ?? null;
}

/**
 * Get the current kid ID or null.
 */
function getKidId(): ?int {
    return $_SESSION['kid_id'] ?? null;
}
