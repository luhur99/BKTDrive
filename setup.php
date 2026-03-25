<?php
/**
 * Setup Wizard — Jalankan sekali untuk inisialisasi database.
 * Hapus file ini setelah setup selesai!
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'bktdrive');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_URL',  'http://localhost/BKTDrive');
define('APP_NAME', 'BKTDrive');

$step    = $_GET['step']  ?? '1';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
    $adminName     = trim($_POST['admin_name']     ?? '');
    $adminEmail    = trim($_POST['admin_email']    ?? '');
    $adminPassword = trim($_POST['admin_password'] ?? '');

    if (!$adminName || !$adminEmail || !$adminPassword) {
        $error = 'Semua field harus diisi.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($adminPassword) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        try {
            // Buat database dan tabel
            $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `" . DB_NAME . "`");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    name       VARCHAR(100)  NOT NULL,
                    email      VARCHAR(150)  NOT NULL UNIQUE,
                    password   VARCHAR(255)  NOT NULL,
                    role       ENUM('admin','member') DEFAULT 'member',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS folders (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    name       VARCHAR(255) NOT NULL,
                    owner_id   INT NOT NULL,
                    parent_id  INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
                    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS files (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    original_name VARCHAR(255) NOT NULL,
                    stored_name   VARCHAR(255) NOT NULL UNIQUE,
                    size          BIGINT       NOT NULL DEFAULT 0,
                    mime_type     VARCHAR(100),
                    owner_id      INT NOT NULL,
                    folder_id     INT DEFAULT NULL,
                    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
                    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
                ) ENGINE=InnoDB
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS shares (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    resource_type ENUM('file','folder') NOT NULL,
                    resource_id   INT NOT NULL,
                    shared_by     INT NOT NULL,
                    shared_with   INT DEFAULT NULL,
                    permission    ENUM('view','edit') DEFAULT 'view',
                    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (shared_by)   REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_share (resource_type, resource_id, shared_with)
                ) ENGINE=InnoDB
            ");

            // Buat folder storage
            $storagePath = __DIR__ . '/storage';
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            // Cek apakah admin sudah ada
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$adminEmail]);
            if ($stmt->fetch()) {
                $error = 'Email admin sudah digunakan. Gunakan email lain.';
            } else {
                $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
                $stmt->execute([$adminName, $adminEmail, $hash, 'admin']);
                $success = 'Setup berhasil!';
                $step = '3';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Cek apakah sudah pernah setup
if ($step === '1') {
    try {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            $alreadySetup = true;
        }
    } catch (Exception $e) {
        $alreadySetup = false;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg,#4f46e5,#7c3aed); min-height:100vh; display:flex; align-items:center; }
        .setup-card { max-width:500px; width:100%; }
        .card { border:none; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.15); }
        .step-badge { background:#4f46e5; color:white; border-radius:999px; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; }
    </style>
</head>
<body>
<div class="container">
<div class="setup-card mx-auto">
    <div class="text-center mb-3">
        <div style="width:56px;height:56px;background:white;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:1.8rem;color:#4f46e5">
            <i class="fa-solid fa-cloud"></i>
        </div>
        <h3 class="fw-bold text-white mt-2"><?= APP_NAME ?></h3>
        <p class="text-white-50">Setup Awal</p>
    </div>

    <div class="card">
        <div class="card-body p-4">

        <?php if (!empty($alreadySetup) && $step === '1'): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                Setup sudah pernah dijalankan. <b>Hapus file setup.php</b> untuk keamanan.
            </div>
            <a href="<?= APP_URL ?>/dashboard.php" class="btn btn-primary w-100">
                <i class="fa-solid fa-arrow-right me-1"></i> Buka Aplikasi
            </a>

        <?php elseif ($step === '3'): ?>
            <div class="text-center py-2">
                <div style="font-size:3rem;color:#22c55e"><i class="fa-solid fa-circle-check"></i></div>
                <h5 class="fw-bold mt-2">Setup Berhasil!</h5>
                <p class="text-muted small">Database dan akun admin berhasil dibuat.</p>
            </div>
            <div class="alert alert-warning small">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                <strong>Penting:</strong> Hapus file <code>setup.php</code> sekarang untuk keamanan!
            </div>
            <a href="<?= APP_URL ?>/login.php" class="btn btn-primary w-100" style="background:#4f46e5;border:none">
                <i class="fa-solid fa-right-to-bracket me-1"></i> Masuk ke Aplikasi
            </a>

        <?php else: ?>
            <h6 class="fw-semibold mb-1">
                <span class="step-badge me-2">1</span>Konfigurasi Database
            </h6>
            <div class="small text-muted mb-3">
                Database: <code><?= DB_NAME ?></code> di <code><?= DB_HOST ?></code>
                (user: <code><?= DB_USER ?></code>)
            </div>
            <hr>
            <h6 class="fw-semibold mb-3 mt-3">
                <span class="step-badge me-2">2</span>Buat Akun Admin
            </h6>

            <?php if ($error): ?>
            <div class="alert alert-danger small py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="setup.php?step=2">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nama Admin</label>
                    <input type="text" name="admin_name" class="form-control"
                           value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Email Admin</label>
                    <input type="email" name="admin_email" class="form-control"
                           value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Password Admin</label>
                    <input type="password" name="admin_password" class="form-control" minlength="6" required>
                    <div class="form-text">Minimal 6 karakter.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100" style="background:#4f46e5;border:none">
                    <i class="fa-solid fa-rocket me-1"></i> Jalankan Setup
                </button>
            </form>
        <?php endif; ?>

        </div>
    </div>
</div>
</div>
</body>
</html>
