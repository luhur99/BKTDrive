# LuhurWorkspace — Panduan Setup

## 1. Jalankan di XAMPP (Localhost)

### A. Hubungkan ke XAMPP

Karena project ada di `d:\vibecoding\luhurworkspace\`, ada 2 cara:

**Cara 1 — Symlink (rekomendasi):**
Buka Command Prompt sebagai Administrator, jalankan:
```
mklink /D "C:\xampp\htdocs\luhurworkspace" "D:\vibecoding\luhurworkspace"
```

**Cara 2 — Tambah VirtualHost di Apache:**
Edit `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, tambahkan:
```apache
<VirtualHost *:80>
    DocumentRoot "D:/vibecoding/luhurworkspace"
    ServerName luhurworkspace.local
    <Directory "D:/vibecoding/luhurworkspace">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Lalu tambahkan di `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1    luhurworkspace.local
```
Restart Apache. Ganti `APP_URL` di `config.php` menjadi `http://luhurworkspace.local`.

---

### B. Setup Database

1. Pastikan MySQL berjalan di XAMPP
2. Buka browser: `http://localhost/luhurworkspace/setup.php`
3. Isi nama, email, dan password admin
4. Klik **Jalankan Setup**
5. **Hapus `setup.php`** setelah berhasil!

---

### C. Login

Buka: `http://localhost/luhurworkspace/`
Masuk dengan email & password admin yang dibuat.

---

## 2. Aktifkan Editor Office (OnlyOffice)

### Opsi A — Windows Installer (Tanpa Docker)

1. Download OnlyOffice Document Server untuk Windows:
   https://www.onlyoffice.com/download-docs.aspx?from=default#docs-community
2. Install (butuh ~2GB, otomatis install PostgreSQL & Nginx)
3. Setelah install, server berjalan di `http://localhost` (port 80) atau `http://localhost:8080`
4. Edit `config.php`:
   ```php
   define('ONLYOFFICE_ENABLED', true);
   define('ONLYOFFICE_SERVER', 'http://localhost:8080'); // sesuaikan port
   define('ONLYOFFICE_JWT_SECRET', 'isi-dengan-secret-dari-onlyoffice');
   ```
5. JWT secret ada di file: `C:\Program Files\ONLYOFFICE\DocumentServer\config\local.json`
   cari `"secret": "..."` di bagian `services.CoAuthoring.token`

### Opsi B — Docker (saat pindah ke VPS/hosting)

```yaml
# docker-compose.yml (sudah tersedia di project)
version: '3'
services:
  onlyoffice:
    image: onlyoffice/documentserver
    ports:
      - "8080:80"
    environment:
      - JWT_ENABLED=true
      - JWT_SECRET=ganti-dengan-secret-anda
    volumes:
      - onlyoffice_data:/var/www/onlyoffice/Data
volumes:
  onlyoffice_data:
```

Jalankan: `docker-compose up -d`

---

## 3. Fitur Aplikasi

| Fitur | Status |
|-------|--------|
| Upload file (drag & drop) | ✅ |
| Buat folder | ✅ |
| Download file | ✅ |
| Hapus file/folder | ✅ |
| Ganti nama file | ✅ |
| Bagikan ke anggota | ✅ |
| Bagikan ke semua tim | ✅ |
| File terbaru | ✅ |
| Cari file | ✅ |
| Edit .docx/.xlsx/.pptx | ✅ (butuh OnlyOffice) |
| Login per pengguna | ✅ |
| Kelola pengguna (admin) | ✅ |
| Reset password | ✅ |

---

## 4. Struktur File

```
luhurworkspace/
├── config.php          ← Konfigurasi utama
├── setup.php           ← Setup awal (hapus setelah setup)
├── dashboard.php       ← File browser utama
├── login.php / logout.php
├── upload.php          ← Handler upload (AJAX)
├── download.php        ← Handler download
├── delete.php          ← Hapus file/folder
├── rename.php          ← Ganti nama
├── share.php           ← Bagikan file
├── folder_create.php   ← Buat folder
├── editor.php          ← Editor OnlyOffice
├── callback.php        ← OnlyOffice save callback
├── onlyoffice_file.php ← Endpoint file untuk OnlyOffice
├── admin.php           ← Kelola pengguna
├── includes/           ← Helper PHP
└── storage/            ← File tersimpan (jangan hapus)
```

---

## 5. Keamanan Produksi

Saat deploy ke hosting/VPS:
- Ganti `DB_PASS` dengan password kuat
- Ganti `ONLYOFFICE_JWT_SECRET` dengan string acak panjang
- Aktifkan HTTPS
- Set `APP_URL` ke domain Anda
- Pastikan folder `storage/` tidak bisa diakses langsung via browser
  (tambahkan `.htaccess` atau konfigurasi Nginx)
