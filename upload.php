<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak valid.']);
    exit;
}

// CSRF via form field (AJAX)
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals(csrfToken(), $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token tidak valid.']);
    exit;
}

$user     = currentUser();
$db       = getDB();
$folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
$uploaded = [];
$errors   = [];

if (empty($_FILES['files'])) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada file.']);
    exit;
}

// Normalize multiple file upload array
$fileCount = count($_FILES['files']['name']);
for ($i = 0; $i < $fileCount; $i++) {
    $name  = $_FILES['files']['name'][$i];
    $tmp   = $_FILES['files']['tmp_name'][$i];
    $size  = $_FILES['files']['size'][$i];
    $error = $_FILES['files']['error'][$i];

    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = "$name: upload error ($error)";
        continue;
    }

    if ($size > MAX_FILE_SIZE) {
        $errors[] = "$name: file terlalu besar (maks " . formatSize(MAX_FILE_SIZE) . ")";
        continue;
    }

    // Buat nama tersimpan unik
    $storedName = generateStoredName($name);
    $destPath   = STORAGE_PATH . '/' . $storedName;

    if (!is_dir(STORAGE_PATH)) {
        mkdir(STORAGE_PATH, 0755, true);
    }

    if (!move_uploaded_file($tmp, $destPath)) {
        $errors[] = "$name: gagal disimpan.";
        continue;
    }

    // Deteksi MIME
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($destPath);

    // Simpan ke database
    $stmt = $db->prepare('
        INSERT INTO files (original_name, stored_name, size, mime_type, owner_id, folder_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$name, $storedName, $size, $mimeType, $user['id'], $folderId]);
    $uploaded[] = $name;
}

if (!empty($uploaded)) {
    echo json_encode([
        'success' => true,
        'message' => count($uploaded) . ' file berhasil diupload.',
        'uploaded' => $uploaded,
        'errors'   => $errors,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Upload gagal: ' . implode(', ', $errors),
    ]);
}
