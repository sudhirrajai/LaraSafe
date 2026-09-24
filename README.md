<h1 align="center"># LaraSafe - Universal Backup & Disaster Recovery Manager 🚀</h1>

<p align="center">
    <img src="https://raw.githubusercontent.com/RajaiSudhir/LaraSafe/main/public/assets/images/logos/logo.png" width="300" alt="LaraSafe Logo">
</p>

<p align="center">
    <a href="https://github.com/RajaiSudhir/LaraSafe/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/RajaiSudhir/LaraSafe"><img src="https://img.shields.io/packagist/v/RajaiSudhir/LaraSafe" alt="Latest Version"></a>
    <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/packagist/l/RajaiSudhir/LaraSafe" alt="License"></a>
</p>

A powerful, secure, and universal backup management and disaster recovery platform built with **Laravel**, **Inertia.js**, and **Vue.js**. Effortlessly backup, schedule, verify, and restore any web application or database with high-ratio `.tar.gz` compression, streaming integrity checksums, and standalone 1-click disaster recovery.

---

## 🔥 Key Highlights

- 📦 **Universal Stack Support**: Protect any project directory—**Next.js, React, Node.js, Python, Laravel, WordPress, Vue, or static sites**. The full project directory is preserved intact.
- 🗜️ **High-Ratio `.tar.gz` Compression**: Advanced gzip compression using native system `tar` (with PHP `PharData`/`ZipArchive` fallbacks) to maximize storage savings across local and remote disks.
- 🛡️ **Self-Contained Disaster Recovery Bundle**: Each archive bundles project files, a clean `database.sql` dump, `RESTORE.md` documentation, and automated helper scripts (`restore.sh` / `restore.bat`). If your server is compromised or destroyed, download the backup directly from remote storage to a fresh server and restore immediately—no LaraSafe installation required.
- 🔐 **Hardened Security & IDOR Defense**: Strict user ownership, granular permissions (`manage settings`, `create backup`, `download backup`, etc.), masked credentials, path traversal defense, and secure database dumping without exposing passwords in process listings (`ps`/`tasklist`).
- 🔍 **Streaming SHA-256 Integrity Verification**: Calculates and audits checksums in real-time with zero memory bloat, complete with an automated integrity command (`php artisan backups:verify-integrity`).
- ☁️ **Multi-Disk & Cloud Storage**: Seamlessly store and retrieve backups on Local Storage, Amazon S3, Google Drive, or Dropbox.
- ⚡ **1-Click Web Restoration**: Extract files to their original path and automatically restore database dumps with automatic cleanup.

---

## ✨ Quick Start

Follow these steps to set up and run LaraSafe:

### 1. Clone the Repository

```bash
git clone https://github.com/RajaiSudhir/LaraSafe.git
cd LaraSafe
```

### 2. Install Dependencies

```bash
composer install
npm install && npm run build
```

### 3. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` to configure your database (`DB_*`), queue connection (`QUEUE_CONNECTION=database`), and mail settings.

### 4. Run Migrations and Seeders

```bash
php artisan migrate --seed
```

### 5. Server Permissions Setup (Linux / Production)

Ensure your web server and queue worker user (e.g. `www-data` or your deployment user) has proper read/write ownership:

```bash
# Change ownership to web user
sudo chown -R www-data:www-data /var/www/LaraSafe

# Set directory and file permissions
sudo chmod -R 775 /var/www/LaraSafe/storage /var/www/LaraSafe/bootstrap/cache
```

### 6. Start the Queue Worker & Scheduler

LaraSafe processes backups and restores asynchronously in the background:

```bash
# Start the queue worker
php artisan queue:work

# In cron (crontab -e), add the Laravel scheduler:
* * * * * cd /var/www/LaraSafe && php artisan schedule:run >> /dev/null 2>&1
```

### 7. Launch the Application

```bash
php artisan serve
```

Log in with your administrator account, create your projects, and schedule your automated backups!

---

## 🛡️ Standalone Disaster Recovery Architecture

LaraSafe is designed with a **zero-lock-in, disaster-first philosophy**. If your host server is hacked, ransomed, or destroyed:

```
backup_archive.tar.gz
├── database.sql           # Clean MySQL database dump (if DB backup enabled)
├── RESTORE.md             # Complete step-by-step restoration guide
├── restore.sh             # 1-click Linux/macOS restore helper
├── restore.bat            # 1-click Windows restore helper
└── [Your Project Files]   # Full project directory & code
```

### Remote Restore Steps (Fresh Server):
1. **Download Archive**: Directly download your `.tar.gz` from your S3 bucket, Google Drive, Dropbox, or backup storage.
2. **Extract to Target Path**:
   ```bash
   mkdir -p /var/www/my-app
   tar -xzf backup_2026_09_24.tar.gz -C /var/www/my-app
   cd /var/www/my-app
   ```
3. **Restore Database**:
   - Run `bash restore.sh` (or `restore.bat` on Windows), enter your target database credentials, and your database is restored instantly.
   - Or manually: `mysql -u root -p my_db < database.sql`
4. **Verify Permissions**:
   ```bash
   sudo chown -R www-data:www-data /var/www/my-app
   sudo chmod -R 755 /var/www/my-app
   ```

*Security Note: LaraSafe intentionally does not execute arbitrary scripts, post-install commands, or alter system permissions during extraction, preventing tampering or remote code execution risks.*

---

## 🔒 Integrity Verification

To detect storage bit rot, transmission errors, or tampering, every backup file is fingerprinted with a streaming SHA-256 checksum.

### Run Integrity Audit:
```bash
# Verify integrity across all stored backups
php artisan backups:verify-integrity

# Check backups for a specific project
php artisan backups:verify-integrity --project="My Application"

# Check backups on a specific storage disk
php artisan backups:verify-integrity --disk=s3
```

---

## 📚 Core Features & Dashboard

| Category | Description |
| :--- | :--- |
| **Project Management** | Manage multiple projects across any language/framework with custom paths and ownership. |
| **Flexible Scheduling** | Daily, weekly, or monthly automated backups with retention auto-cleanup policies. |
| **Database Dumps** | Secure `mysqldump` packaging with support for full database or selected tables. |
| **Cloud Storage** | Dynamic disk configuration supporting Local, AWS S3, Google Drive, and Dropbox. |
| **Interactive UI** | Inertia.js + Vue 3 interface with real-time stats cards, countdown timers, charts, and activity logs. |
| **Email Alerts** | Instant email notifications for successful backups and detailed failure alerts. |

---

## 🧪 Testing

LaraSafe includes automated feature and unit test suites:

```bash
php artisan test
```

Includes coverage for:
- Streaming SHA-256 integrity calculation and command verification.
- IDOR access control and download authorization.
- `.tar.gz` disaster recovery packaging and in-app restoration.
- Next.js, React, and multi-stack full directory backup workflows.
- Settings permissions and security policies.

---

## 🤝 Contributing

1. Fork the repository.
2. Create your feature branch (`git checkout -b feature/amazing-feature`).
3. Commit your changes (`git commit -m 'feat: add amazing feature'`).
4. Push to the branch (`git push origin feature/amazing-feature`).
5. Open a Pull Request.

---

## 📄 License

This project is open-sourced software licensed under the [MIT License](LICENSE).
