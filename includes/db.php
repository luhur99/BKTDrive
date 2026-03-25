<?php
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:2rem;color:red">
                <b>Database Error:</b> ' . htmlspecialchars($e->getMessage()) . '<br><br>
                Pastikan MySQL berjalan dan database <b>' . DB_NAME . '</b> sudah dibuat.<br>
                Jalankan <a href="' . APP_URL . '/setup.php">setup.php</a> untuk konfigurasi awal.
            </div>');
        }
    }
    return $pdo;
}
