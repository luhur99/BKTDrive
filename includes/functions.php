<?php
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function formatSize(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function formatDate(string $datetime): string {
    return date('d M Y, H:i', strtotime($datetime));
}

function getFileExtension(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function getFileIcon(string $filename): string {
    $icons = [
        'pdf'  => 'fa-file-pdf',
        'doc'  => 'fa-file-word',  'docx' => 'fa-file-word',
        'xls'  => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'ppt'  => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
        'jpg'  => 'fa-file-image', 'jpeg' => 'fa-file-image',
        'png'  => 'fa-file-image', 'gif'  => 'fa-file-image',
        'webp' => 'fa-file-image', 'svg'  => 'fa-file-image',
        'zip'  => 'fa-file-zipper', 'rar' => 'fa-file-zipper',
        '7z'   => 'fa-file-zipper',
        'txt'  => 'fa-file-lines',
        'csv'  => 'fa-file-csv',
        'mp4'  => 'fa-file-video', 'avi' => 'fa-file-video', 'mkv' => 'fa-file-video',
        'mp3'  => 'fa-file-audio', 'wav' => 'fa-file-audio',
    ];
    $ext = getFileExtension($filename);
    return $icons[$ext] ?? 'fa-file';
}

function getFileIconColor(string $filename): string {
    $colors = [
        'pdf'  => 'text-danger',
        'doc'  => 'text-primary', 'docx' => 'text-primary',
        'xls'  => 'text-success', 'xlsx' => 'text-success',
        'ppt'  => 'text-warning', 'pptx' => 'text-warning',
        'jpg'  => 'text-info',    'jpeg' => 'text-info',
        'png'  => 'text-info',    'gif'  => 'text-info',
        'zip'  => 'text-secondary', 'rar' => 'text-secondary',
        'mp4'  => 'text-purple',  'mp3'  => 'text-purple',
    ];
    $ext = getFileExtension($filename);
    return $colors[$ext] ?? 'text-secondary';
}

function isOfficeFile(string $filename): bool {
    return in_array(getFileExtension($filename), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
}

function getDocumentType(string $filename): string {
    $types = [
        'doc' => 'word',  'docx' => 'word',
        'xls' => 'cell',  'xlsx' => 'cell',
        'ppt' => 'slide', 'pptx' => 'slide',
    ];
    return $types[getFileExtension($filename)] ?? 'word';
}

function isPreviewable(string $filename): bool {
    return in_array(getFileExtension($filename), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);
}

function isViewableFile(string $filename): bool {
    return in_array(getFileExtension($filename), [
        'jpg','jpeg','png','gif','webp','svg','bmp',
        'pdf',
        'txt','log','csv','md','json','xml',
    ]);
}

function generateStoredName(string $originalName): string {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    return bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
}

// Cek apakah user bisa akses file (owner atau punya share)
function canAccessFile(int $fileId, int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM files WHERE id = ? AND owner_id = ?');
    $stmt->execute([$fileId, $userId]);
    if ($stmt->fetch()) return true;

    $stmt = $db->prepare('
        SELECT s.id FROM shares s
        WHERE s.resource_type = "file" AND s.resource_id = ?
          AND (s.shared_with = ? OR s.shared_with IS NULL)
    ');
    $stmt->execute([$fileId, $userId]);
    return (bool)$stmt->fetch();
}

// Cek apakah user bisa edit file
function canEditFile(int $fileId, int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM files WHERE id = ? AND owner_id = ?');
    $stmt->execute([$fileId, $userId]);
    if ($stmt->fetch()) return true;

    $stmt = $db->prepare('
        SELECT s.id FROM shares s
        WHERE s.resource_type = "file" AND s.resource_id = ?
          AND (s.shared_with = ? OR s.shared_with IS NULL)
          AND s.permission = "edit"
    ');
    $stmt->execute([$fileId, $userId]);
    return (bool)$stmt->fetch();
}

// Ambil path lengkap file di storage
function storagePath(string $storedName): string {
    return STORAGE_PATH . '/' . $storedName;
}

// Redirect dengan flash message
function redirectWith(string $url, string $type, string $message): void {
    setFlash($type, $message);
    header('Location: ' . $url);
    exit;
}
