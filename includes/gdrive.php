<?php
/**
 * Google Drive API helper (tanpa library eksternal, via cURL)
 */

function gdriveEnsureTable(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS gdrive_config (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            client_id      VARCHAR(300) NOT NULL,
            client_secret  VARCHAR(300) NOT NULL,
            access_token   TEXT,
            refresh_token  TEXT,
            token_expires  INT DEFAULT 0,
            folder_id      VARCHAR(200) DEFAULT '',
            folder_name    VARCHAR(200) DEFAULT '',
            connected      TINYINT(1) DEFAULT 0,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
}

function gdriveGetConfig(): array {
    $db = getDB();
    gdriveEnsureTable();
    $row = $db->query('SELECT * FROM gdrive_config LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function gdriveIsConnected(): bool {
    $cfg = gdriveGetConfig();
    return !empty($cfg['refresh_token']) && !empty($cfg['connected']);
}

function gdriveRefreshToken(array $cfg): string|false {
    if (empty($cfg['refresh_token'])) return false;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $cfg['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!empty($data['access_token'])) {
        $db      = getDB();
        $expires = time() + (int)($data['expires_in'] ?? 3600) - 60;
        $db->prepare('UPDATE gdrive_config SET access_token=?, token_expires=? WHERE id=?')
           ->execute([$data['access_token'], $expires, $cfg['id']]);
        return $data['access_token'];
    }
    return false;
}

function gdriveGetAccessToken(): string|false {
    $cfg = gdriveGetConfig();
    if (empty($cfg['client_id'])) return false;
    if (!empty($cfg['access_token']) && !empty($cfg['token_expires']) && $cfg['token_expires'] > time()) {
        return $cfg['access_token'];
    }
    return gdriveRefreshToken($cfg);
}

/**
 * Upload file ke Google Drive menggunakan resumable upload
 */
function gdriveUpload(string $filePath, string $fileName): array {
    $token = gdriveGetAccessToken();
    if (!$token) return ['ok' => false, 'msg' => 'Tidak ada access token Google Drive.'];

    $cfg      = gdriveGetConfig();
    $fileSize = filesize($filePath);
    $metadata = ['name' => $fileName, 'mimeType' => 'application/zip'];
    if (!empty($cfg['folder_id'])) {
        $metadata['parents'] = [$cfg['folder_id']];
    }

    // 1. Inisiasi resumable upload session
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: application/zip',
            'X-Upload-Content-Length: ' . $fileSize,
        ],
        CURLOPT_POSTFIELDS     => json_encode($metadata),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['ok' => false, 'msg' => 'Gagal inisiasi upload ke GDrive (HTTP ' . $httpCode . ')'];
    }

    // Ambil upload URL dari header Location
    preg_match('/Location:\s*(https:\/\/[^\r\n]+)/i', $resp, $m);
    if (empty($m[1])) return ['ok' => false, 'msg' => 'Tidak dapat menemukan upload URL.'];
    $uploadUrl = trim($m[1]);

    // 2. Upload isi file
    $fh = fopen($filePath, 'rb');
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => $fileSize,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/zip',
            'Content-Length: ' . $fileSize,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 300,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    $data = json_decode($resp, true);
    if ($httpCode === 200 || $httpCode === 201) {
        return ['ok' => true, 'id' => $data['id'] ?? '', 'name' => $data['name'] ?? $fileName];
    }
    return ['ok' => false, 'msg' => 'Upload GDrive gagal (HTTP ' . $httpCode . '): ' . substr($resp, 0, 200)];
}

/**
 * Buat folder di Google Drive (jika belum ada)
 */
function gdriveCreateFolder(string $name, string $parentId = ''): array {
    $token = gdriveGetAccessToken();
    if (!$token) return ['ok' => false];

    $metadata = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
    if ($parentId) $metadata['parents'] = [$parentId];

    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($metadata),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    return ['ok' => !empty($data['id']), 'id' => $data['id'] ?? '', 'name' => $data['name'] ?? ''];
}

/**
 * Buat ZIP backup (sama seperti backup.php)
 */
function createBackupZip(): string|false {
    if (!class_exists('ZipArchive')) return false;

    $timestamp = date('Y-m-d_H-i-s');
    $zipPath   = sys_get_temp_dir() . '/backup_' . $timestamp . '.zip';
    $zip       = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    // File storage
    if (is_dir(STORAGE_PATH)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(STORAGE_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $rel = 'storage/' . substr($f->getRealPath(), strlen(realpath(STORAGE_PATH)) + 1);
            $zip->addFile($f->getRealPath(), $rel);
        }
    }

    // Export DB
    $db  = getDB();
    $sql = "-- LuhurWorkspace Backup\n-- " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (['users','folders','files','shares'] as $table) {
        $row  = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";
        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols  = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $sql  .= "INSERT INTO `$table` ($cols) VALUES\n";
            $vals  = [];
            foreach ($rows as $r) {
                $esc   = array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($r));
                $vals[] = '(' . implode(', ', $esc) . ')';
            }
            $sql .= implode(",\n", $vals) . ";\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $zip->addFromString('database.sql', $sql);
    $zip->close();
    return $zipPath;
}
