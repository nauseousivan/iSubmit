# iSubmit - Research Digitalization Platform

iSubmit is a comprehensive, modern Research Digitalization Platform built for the International School of Asia & the Pacific (ISAP) and Medical Colleges of Northern Philippines (MCNP). It handles the complete lifecycle of academic research submissions, consultations, and approvals.

## 🚀 Features

- **Dynamic Authentication:** Beautiful, responsive authentication flows featuring "Quill", our interactive SVG mascot who watches your cursor, covers his eyes during password entry, and waves to greet you!
- **Role-Based Access:** Dedicated interfaces for Students, Coordinators, and Statisticians.
- **Integrated Communication Module (v2.0):** A completely redesigned, app-like messaging system inspired by Slack and iMessage. Features include:
  - **Dynamic Context Routing:** Students securely message staff using title-based routing (Research Coordinator, Statistician) without knowing the staff's actual identity.
  - **Interactive Emoji Reactions:** Double-tap or hover over any message to react with emojis (❤️, 👍, 😂, etc.), saving instantly to the database.
  - **Smart State Management:** Responsive sidebar with categorized pinned groups and active chat streams, built entirely in Vanilla JS and AJAX without heavy frameworks.
- **Smart Theming:** Automatically switches branding and colors based on institutional email (`@isap.edu.ph` vs `@mcnp.edu.ph`).
- **Research Lifecycle Management:** Track document submissions, handle iterations/revisions, and maintain a seamless consultation log with your advisers.
- **OTP Verification & Security:** Built-in email verification and secure password recovery mechanisms.

## 🛠️ Technology Stack

- **Frontend:** HTML5, CSS3 (Custom Variables, Flexbox/Grid), Vanilla JavaScript (No heavy frameworks!)
- **Backend:** PHP 8.2+ (PDO for Database Interactions)
- **Database:** MySQL / MariaDB (`digital_research`)
- **Assets:** Lucide Icons, Google Fonts (Poppins & Plus Jakarta Sans)
- **Dependencies:** Composer, PHPMailer, smalot/pdfparser

## 📁 Directory Structure

```
/
├── assets/         # CSS, JS, Images, and Mascot SVGs
├── auth/           # Login, Registration, OTP, and Password Recovery
├── config/         # Database (db.php) and Mail (mail.php) Configurations
├── dashboards/     # Specific portals based on user roles (Student, Statistician, etc.)
├── scratch/        # Temporary playground files
├── uploads/        # User-uploaded research documents (PDFs, Docx, Xlsx)
├── storage/        # Historical files
└── vendor/         # Composer dependencies
```

## 📖 Documentation Directory
For an in-depth understanding of the platform, refer to the following documentation files inside the `docs/` folder:
- [INSTALLATION.md](docs/INSTALLATION.md): Setup instructions for local development.
- [SYSTEM_ARCHITECTURE.md](docs/SYSTEM_ARCHITECTURE.md): Frontend/Backend architectural overview.
- [DATABASE_DOCUMENTATION.md](docs/DATABASE_DOCUMENTATION.md): Schema, constraints, and tables.
- [SECURITY.md](docs/SECURITY.md): Authentication and vulnerability management.
- [DEPLOYMENT.md](docs/DEPLOYMENT.md): Guide for deploying to a production server.
- [CONTRIBUTING.md](docs/CONTRIBUTING.md): Guidelines for developers.
- [CHANGELOG.md](docs/CHANGELOG.md): History of updates.
- [TODO.md](docs/TODO.md) / [ROADMAP.md](docs/ROADMAP.md): Upcoming features and tasks.

---
*Built with ❤️ for the students and faculty of ISAP and MCNP.*
