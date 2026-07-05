# Installation Guide

Follow these steps to set up the `iSubmit` Research Digitalization Platform on your local machine for development.

## 📋 Prerequisites

Ensure you have the following installed on your system:
- **XAMPP / WAMP / MAMP:** (Providing Apache & MySQL/MariaDB)
- **PHP 8.1 or higher:** (Built into XAMPP)
- **Composer:** PHP Dependency Manager
- **Git:** Version control

## ⚙️ Step-by-Step Setup

### 1. Clone the Repository
Clone the project into your web server's public directory (e.g., `C:\xampp\htdocs\` for XAMPP):
```bash
cd C:\xampp\htdocs
git clone https://github.com/your-org/Research_Digital.git
cd Research_Digital
```

### 2. Install Dependencies
The project uses Composer for packages like `PHPMailer` and `Smalot PDFParser`.
```bash
composer install
```

### 3. Database Configuration
1. Open **phpMyAdmin** (usually `http://localhost/phpmyadmin`).
2. Create a new database named exactly `digital_research`.
3. Import the `databasev2.sql` file located in the root of the project.

### 4. Configure Environment Variables
Navigate to the `config/` directory and configure the database and mail settings.

**`config/db.php`**
```php
$host = 'localhost';
$dbname = 'digital_research';
$username = 'root'; // Change if your MySQL uses a different username
$password = '';     // Change if your MySQL has a password
```

**`config/mail.php`**
Update the SMTP credentials to allow the system to send OTPs:
```php
$mail->Username   = 'your-email@gmail.com';
$mail->Password   = 'your-16-char-app-password';
$mail->setFrom('your-email@gmail.com', 'MCNP-ISAP Research Office');
```
> [!NOTE] 
> You must use a Gmail "App Password" if you have 2FA enabled on your Google account.

### 5. Directory Permissions
Ensure the following directories have write permissions (CHMOD `775` or `777`) so the system can store user files:
- `uploads/`
- `storage/`

## 🚀 Running the Application
Start your Apache and MySQL servers in XAMPP.
Navigate to your browser:
```
http://localhost/Research_Digital/auth/login.php
```

You are now ready to develop!
