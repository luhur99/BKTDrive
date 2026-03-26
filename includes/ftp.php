<?php
/**
 * Shared FTP helpers — robust connect with passive/active fallback and retry.
 */

/**
 * Open an authenticated FTP connection.
 * Tries passive mode first; if ftp_pasv() fails, falls back to active mode.
 * Retries the full connect sequence up to $retries times on transient failures.
 *
 * Returns the FTP resource on success, false on failure.
 */
function ftpConnect(array $es, string $pass, int $retries = 2): mixed {
    for ($i = 0; $i < $retries; $i++) {
        if ($i > 0) sleep(1);

        $conn = @ftp_connect($es['host'], (int)$es['port'], 8);
        if (!$conn) continue;

        if (!@ftp_login($conn, $es['username'], $pass)) {
            @ftp_close($conn);
            return false;          // wrong credentials — no point retrying
        }

        if ($es['passive']) {
            if (!@ftp_pasv($conn, true)) {
                @ftp_pasv($conn, false);   // fall back to active mode
            }
        }

        return $conn;
    }
    return false;
}

/**
 * Execute an FTP operation with automatic reconnect-and-retry.
 *
 * $op receives the open FTP connection and must return:
 *   - any non-false value  → success, stops retrying
 *   - false                → failure, retry (reconnect first)
 *
 * Returns the successful result, or false if all attempts fail.
 */
function ftpRetry(array $es, string $pass, callable $op, int $retries = 2): mixed {
    for ($i = 0; $i < $retries; $i++) {
        if ($i > 0) sleep(1);

        $conn = ftpConnect($es, $pass);
        if (!$conn) continue;

        $result = $op($conn);
        @ftp_close($conn);

        if ($result !== false) return $result;
    }
    return false;
}

/**
 * List a directory on the FTP server, with retry.
 * Returns a parsed array of ['name', 'is_dir', 'size', 'raw'] or false.
 */
function ftpListDir(array $es, string $pass, string $path, int $retries = 2): array|false {
    for ($i = 0; $i < $retries; $i++) {
        if ($i > 0) sleep(1);

        $conn = ftpConnect($es, $pass);
        if (!$conn) continue;

        $rawList = @ftp_rawlist($conn, $path);

        if ($rawList === false) {
            // Fallback: ftp_nlist + ftp_size to detect directories
            $nlist = @ftp_nlist($conn, $path);
            if ($nlist === false) { @ftp_close($conn); continue; }

            $items = [];
            foreach ($nlist as $entry) {
                $name = basename($entry);
                if ($name === '.' || $name === '..') continue;
                $size  = @ftp_size($conn, $path . '/' . $name);
                $isDir = ($size === -1);
                $items[] = ['name' => $name, 'is_dir' => $isDir, 'size' => max(0, (int)$size), 'raw' => ''];
            }
            @ftp_close($conn);
            return $items;
        }

        @ftp_close($conn);

        $items = [];
        foreach ($rawList as $line) {
            if (!$line) continue;

            // Windows/IIS format: "01-01-22  12:00AM  <DIR>  name"
            if (preg_match('/^\d{2}-\d{2}-\d{2,4}\s+\d{2}:\d{2}(?:AM|PM)\s+(<DIR>|\d+)\s+(.+)$/', $line, $m)) {
                $name  = trim($m[2]);
                $isDir = ($m[1] === '<DIR>');
                $size  = $isDir ? 0 : (int)$m[1];
            } else {
                // Unix format: "drwxr-xr-x  2 user group 4096 Jan  1 12:00 name"
                $parts = preg_split('/\s+/', $line, 9);
                if (count($parts) < 9) continue;
                $name  = $parts[8];
                $isDir = $parts[0][0] === 'd';
                $size  = (int)$parts[4];
            }

            if ($name === '.' || $name === '..') continue;
            $items[] = ['name' => $name, 'is_dir' => $isDir, 'size' => $size, 'raw' => $line];
        }
        return $items;
    }
    return false;
}
