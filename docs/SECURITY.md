# Security Policy & Audit Findings

Security is a primary concern for the `iSubmit` platform due to the handling of academic data and user credentials.

## 🛡️ Current Security Measures
- **Password Protection:** Uses PHP's native `password_hash()` implementing the BCrypt algorithm.
- **SQL Injection Prevention:** Uses PHP Data Objects (PDO) with prepared statements for database queries, effectively mitigating standard SQL injection attacks.
- **OTP Verification:** Registration and password recovery flows require an emailed 6-digit OTP to verify user identity.

## 🚨 Known Vulnerabilities (To Be Patched)
Based on a recent security audit, the following vulnerabilities exist and must be patched before production deployment:

### 1. Brute Force & Account Enumeration
- **Issue:** The `login.php` endpoint does not limit the number of failed login attempts. An attacker could brute-force passwords.
- **Issue:** The login form explicitly states "Email not found", allowing an attacker to enumerate which emails are registered.
- **Mitigation:** Implement a rate-limiting mechanism (locking an IP or email after 5 failed attempts) and return generic error messages (e.g., "Invalid email or password").

### 2. Cross-Site Request Forgery (CSRF)
- **Issue:** POST forms (`login.php`, `register.php`) do not include CSRF tokens.
- **Mitigation:** Generate a secure, random CSRF token in `$_SESSION` and validate it upon form submission.

### 3. Session Fixation
- **Issue:** When a user logs in, their session ID remains the same as when they were unauthenticated.
- **Mitigation:** Call `session_regenerate_id(true);` immediately after verifying the user's password.

### 4. Cross-Site Scripting (XSS)
- **Issue:** User-generated content (like activity log descriptions) may be echoed directly into HTML.
- **Mitigation:** Ensure `htmlspecialchars($data, ENT_QUOTES, 'UTF-8')` is used consistently whenever outputting database content to the frontend.

## 🐛 Reporting a Vulnerability
If you discover a security vulnerability within iSubmit, please contact the development team immediately rather than creating a public issue.
