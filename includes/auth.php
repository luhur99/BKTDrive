<?php
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'    => (int)$_SESSION['user_id'],
        'name'  => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role'  => $_SESSION['user_role'],
    ];
}

function isAdmin(): bool {
    return !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
