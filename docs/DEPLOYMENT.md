# Deployment Guide

Follow these instructions to safely deploy `iSubmit` from your local environment to a live production server (e.g., Hostinger, cPanel, or a Linux VPS).

## 1. Prepare the Production Server
Ensure your hosting provider supports:
- **PHP 8.1+**
- **MySQL / MariaDB**
- **Composer** (Optional, if you upload the `vendor/` folder directly)

## 2. Secure Configuration
Before uploading, update your configuration files for the production environment.

**`config/db.php`**
Update the credentials to match your live database.
```php
$host = 'localhost'; // Usually remains localhost on shared hosting
$dbname = 'live_database_name';
$username = 'live_db_user';
$password = 'Secure!Strong!Password';
```
> [!WARNING]
> Never commit your production database passwords to Git! Keep a `.env` file or local `db.php` file excluded from version control.

**`config/mail.php`**
Ensure your SMTP settings are correct. Using a generic Gmail account on a production server may lead to rate limiting by Google. Consider using a transactional email provider like SendGrid, Mailgun, or AWS SES for production.

## 3. Database Migration
1. Export your local `digital_research` database via phpMyAdmin.
2. Log in to your live server's phpMyAdmin.
3. Import the `databasev2.sql` file.

## 4. File Upload & Permissions
Upload all files (excluding `scratch/` and `.git/`) to your public HTML directory (`public_html/` or `/var/www/html/`).

Set strict permissions to secure the server:
- **Files:** `644` (Read/Write for owner, Read for others)
- **Directories:** `755` (Read/Write/Execute for owner, Read/Execute for others)
- **Upload Directories:** Ensure `/uploads` and `/storage` are writable by the PHP process (e.g., `www-data`).

## 5. Security Hardening
- **Disable Directory Browsing:** Add `Options -Indexes` to an `.htaccess` file in the root.
- **Force HTTPS:** Add rewrite rules in `.htaccess` to force all traffic over SSL/TLS.

## 6. Updating the live server (DigitalOcean, `/var/www/isubmit`)
```bash
cd /var/www/isubmit
git pull origin main
# then run any NEW file in database/migrations/ once (the release notes say which)
```

## 7. Form auto-fill — one-time server setup (2026-10-17)
Forms whose Word master carries `{{TAGS}}` are filled in for each student and converted to PDF by
LibreOffice. Until this is done nothing breaks — those downloads simply stay blank.

```bash
sudo apt update
sudo apt install -y php-zip libreoffice-writer-nogui fontconfig
# Arial + Georgia (Microsoft core fonts), accepting their licence without a prompt:
echo ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true | sudo debconf-set-selections
sudo apt install -y ttf-mscorefonts-installer
sudo systemctl restart apache2
```

**Tahoma** (used by several RDC forms) is not in that package. Copy it from a Windows PC
(`C:\Windows\Fonts\tahoma.ttf` and `tahomabd.ttf`):
```bash
# on the Windows PC:
scp C:\Windows\Fonts\tahoma.ttf C:\Windows\Fonts\tahomabd.ttf root@SERVER:/usr/local/share/fonts/
# on the server:
sudo fc-cache -f
```

Check: `php -m | grep zip` prints `zip`, and `soffice --version` prints a version. Then in
Admin → Forms, open Form 011 and use **Try it with a group** — it should open a filled PDF.
