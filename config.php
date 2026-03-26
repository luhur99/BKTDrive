<?php
// ============================================================
// Konfigurasi BKTDrive
// ============================================================

// Database — baca dari environment variable (Docker)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'bktdrive');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Aplikasi
define('APP_NAME', getenv('APP_NAME') ?: 'BKTDrive');
define('APP_URL',  getenv('APP_URL')  ?: 'http://localhost:8090');
define('APP_INTERNAL_URL', getenv('APP_INTERNAL_URL') ?: 'http://app');
define('STORAGE_PATH', __DIR__ . '/storage');
define('MAX_FILE_SIZE', (int)(getenv('MAX_FILE_SIZE') ?: 100) * 1024 * 1024);

// OnlyOffice Document Server
define('ONLYOFFICE_ENABLED',    filter_var(getenv('ONLYOFFICE_ENABLED')    ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('ONLYOFFICE_SERVER',     getenv('ONLYOFFICE_SERVER')     ?: 'http://localhost:7400');
define('ONLYOFFICE_JWT_SECRET', getenv('ONLYOFFICE_JWT_SECRET') ?: 'local-dev-secret');

// Cron secret (untuk keamanan endpoint cron.php)
define('CRON_SECRET', 'cron-' . md5(DB_NAME . DB_USER . APP_URL));

// Timezone Indonesia
date_default_timezone_set('Asia/Jakarta');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
