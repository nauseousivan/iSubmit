# System Architecture

This document provides a high-level overview of the architectural design of the `iSubmit` platform.

## 🏗️ Architectural Pattern

`iSubmit` follows a **Monolithic Client-Server Architecture** utilizing a standard LAMP stack (Linux, Apache, MySQL, PHP). The architecture relies on Server-Side Rendering (SSR) via PHP, combined with lightweight, Vanilla JavaScript on the frontend for interactions.

## 🖥️ Frontend (Presentation Layer)
The frontend is designed to be incredibly fast, relying entirely on raw CSS3 and Vanilla JavaScript without heavy frameworks (No React, Vue, or Tailwind).

- **CSS Methodology:** Uses custom properties (variables) for theme switching (ISAP vs. MCNP branding). Relies on CSS Grid and Flexbox.
- **Micro-Interactions:** JavaScript is strictly used for DOM manipulation (e.g., `mascot.js` state machine for the Quill mascot, `ripple.js` for button clicks, and `constellation.js` for background canvas effects).
- **Responsive Design:** Utilizes relative units (`rem`, `vh`, `vw`) and `@media` queries to ensure fluidity across Mobile, Tablet, and Desktop.

## ⚙️ Backend (Application Layer)
The backend uses raw PHP 8.2 scripts.

- **Routing:** Direct file access (e.g., `/auth/login.php`). There is currently no front-controller pattern (Router).
- **Authentication:** Uses native PHP sessions (`$_SESSION`) to persist user state.
- **Dependencies:** Managed via Composer (`vendor/`), heavily utilizing `PHPMailer` for transactional emails.

## 💾 Database (Data Layer)
- **Database Engine:** MySQL / MariaDB (InnoDB).
- **Driver:** PHP Data Objects (PDO) to prevent SQL injection via prepared statements.
- **Structure:** Fully relational tables tracking Users, Uploads, Approvals, and Activity Logs.

## 🔄 Data Flow Example (Registration)
1. User submits `POST` data via `register.php`.
2. Frontend validates basic inputs (`required`, `type="email"`).
3. Backend receives request, checks if email exists via PDO.
4. Password is hashed using `password_hash($pass, PASSWORD_BCRYPT)`.
5. User is inserted into `users` table.
6. A 6-digit OTP is generated and stored in `otp_verifications`.
7. `PHPMailer` connects to SMTP and sends the OTP to the user.
8. User is redirected to `verify_otp.php`.
