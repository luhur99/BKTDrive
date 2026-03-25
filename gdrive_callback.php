<?php
/**
 * gdrive_callback.php — OAuth2 callback dari Google
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gdrive.php';

requireAdmin();

$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    redirectWith(APP_URL . '/backup_schedule.php?tab=gdrive', 'danger',
        'Google Drive otorisasi ditolak: ' . $error);
}

if (!$code) {
    redirectWith(APP_URL . '/backup_schedule.php?tab=gdrive', 'danger', 'Kode otorisasi tidak ditemukan.');
}

$cfg = gdriveGetConfig();
if (!$cfg || empty($cfg['client_id'])) {
    redirectWith(APP_URL . '/backup_schedule.php?tab=gdrive', 'danger', 'Konfigurasi GDrive belum ada.');
}

// ── Tukar kode → token ─────────────────────────────────────────────────────
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri'  => APP_URL . '/gdrive_callback.php',
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);

if ($httpCode !== 200 || empty($data['access_token'])) {
    $errMsg = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
    redirectWith(APP_URL . '/backup_schedule.php?tab=gdrive', 'danger',
        'Gagal mendapatkan token dari Google: ' . $errMsg);
}

// ── Simpan token ───────────────────────────────────────────────────────────
$db      = getDB();
$expires = time() + (int)($data['expires_in'] ?? 3600) - 60;

$db->prepare('
    UPDATE gdrive_config
    SET access_token=?, refresh_token=?, token_expires=?, connected=1
    WHERE id=?
')->execute([
    $data['access_token'],
    $data['refresh_token'] ?? $cfg['refresh_token'], // refresh_token hanya ada saat pertama
    $expires,
    $cfg['id'],
]);

redirectWith(APP_URL . '/backup_schedule.php?tab=gdrive', 'success',
    'Google Drive berhasil terhubung! Backup otomatis siap diaktifkan.');
