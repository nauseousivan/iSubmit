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
