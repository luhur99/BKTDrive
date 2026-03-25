<?php
/**
 * cron.php — Endpoint backup otomatis
 *
 * Dipanggil oleh:
 *   - Windows Task Scheduler: php cron.php <secret>
 *   - Linux crontab:          php /path/to/cron.php <secret>
 *   - URL trigger:            GET /cron.php?secret=<secret>
 */

define('RUNNING_AS_CLI', php_sapi_name() === 'cli');

// Load aplikasi
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gdrive.php';

// ── Verifikasi secret ──────────────────────────────────────────────────────
$secret = RUNNING_AS_CLI ? ($argv[1] ?? '') : ($_GET['secret'] ?? '');
if (!hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    die('Unauthorized');
}

// ── Setup table (jika belum ada) ───────────────────────────────────────────
$db = getDB();
$db->exec("
    CREATE TABLE IF NOT EXISTS backup_schedules (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        frequency    ENUM('daily','weekly') DEFAULT 'daily',
        day_of_week  TINYINT DEFAULT 0,
        hour         TINYINT DEFAULT 2,
        minute       TINYINT DEFAULT 0,
        gdrive_enabled TINYINT(1) DEFAULT 0,
        enabled      TINYINT(1) DEFAULT 0,
        last_run     DATETIME DEFAULT NULL,
        next_run     DATETIME DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");
$db->exec("
    CREATE TABLE IF NOT EXISTS backup_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        type          ENUM('manual','auto') DEFAULT 'manual',
        status        ENUM('success','failed') DEFAULT 'success',
        file_size     BIGINT DEFAULT 0,
        gdrive_file_id VARCHAR(255) DEFAULT '',
        message       TEXT,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");
gdriveEnsureTable();

// ── Cek jadwal ─────────────────────────────────────────────────────────────
$schedule = $db->query('SELECT * FROM backup_schedules WHERE enabled=1 LIMIT 1')->fetch();
if (!$schedule) {
    logMsg('INFO', 'Tidak ada jadwal backup yang aktif.');
    exit(0);
}

// Apakah sudah waktunya?
$now = time();
if ($schedule['next_run'] && strtotime($schedule['next_run']) > $now) {
    logMsg('INFO', 'Belum waktunya. Next run: ' . $schedule['next_run']);
    exit(0);
}

// ── Jalankan Backup ─────────────────────────────────────────────────────────
logMsg('INFO', 'Memulai backup otomatis...');

$zipPath = createBackupZip();
if (!$zipPath || !file_exists($zipPath)) {
    $db->prepare('INSERT INTO backup_logs (type,status,message) VALUES ("auto","failed","Gagal membuat file ZIP backup.")')
       ->execute();
    logMsg('ERROR', 'Gagal membuat ZIP backup.');
    exit(1);
}

$zipSize  = filesize($zipPath);
$zipName  = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
$gdriveId = '';
$message  = 'Backup lokal berhasil.';

// ── Upload ke Google Drive ─────────────────────────────────────────────────
if ($schedule['gdrive_enabled'] && gdriveIsConnected()) {
    logMsg('INFO', 'Mengupload ke Google Drive...');
    $result = gdriveUpload($zipPath, $zipName);
    if ($result['ok']) {
        $gdriveId = $result['id'];
        $message  = 'Backup berhasil diupload ke Google Drive (ID: ' . $gdriveId . ')';
        logMsg('OK', 'Upload GDrive berhasil. File ID: ' . $gdriveId);
    } else {
        $message = 'Backup dibuat tapi upload GDrive gagal: ' . $result['msg'];
        logMsg('WARN', 'Upload GDrive gagal: ' . $result['msg']);
    }
} else {
    logMsg('INFO', 'GDrive tidak aktif — backup disimpan lokal saja.');
}

// Hapus ZIP sementara
@unlink($zipPath);

// ── Catat log ─────────────────────────────────────────────────────────────
$db->prepare('INSERT INTO backup_logs (type,status,file_size,gdrive_file_id,message) VALUES ("auto","success",?,?,?)')
   ->execute([$zipSize, $gdriveId, $message]);

// ── Update next_run ────────────────────────────────────────────────────────
$nextRun = computeNextRun($schedule);
$db->prepare('UPDATE backup_schedules SET last_run=NOW(), next_run=? WHERE id=?')
   ->execute([$nextRun, $schedule['id']]);

logMsg('OK', 'Backup selesai. Ukuran: ' . formatSize($zipSize) . '. Next run: ' . $nextRun);
exit(0);

// ── Helper ────────────────────────────────────────────────────────────────
function computeNextRun(array $s): string {
    $hour   = (int)$s['hour'];
    $minute = (int)$s['minute'];
    $today  = mktime($hour, $minute, 0, date('n'), date('j'), date('Y'));
    $now    = time();

    if ($s['frequency'] === 'daily') {
        $next = ($today > $now) ? $today : $today + 86400;
    } else {
        $curDow = (int)date('w');
        $dow    = (int)$s['day_of_week'];
        $diff   = ($dow - $curDow + 7) % 7;
        $diff   = $diff === 0 && $today <= $now ? 7 : $diff;
        $next   = $today + $diff * 86400;
    }
    return date('Y-m-d H:i:s', $next);
}

function logMsg(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg . PHP_EOL;
    if (RUNNING_AS_CLI) {
        echo $line;
    } else {
        // Tulis ke log file
        $logFile = __DIR__ . '/storage/cron.log';
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
