# To-Do List

This is the immediate action list for the `iSubmit` project, prioritized based on the recent project audit.

## High Priority (Security & Architecture)
- [ ] Implement `session_regenerate_id(true)` upon successful login.
- [ ] Add CSRF tokens to all POST forms (`login.php`, `register.php`, etc.).
- [ ] Implement Login Rate Limiting to prevent brute force attacks.
- [ ] Sanitize all outputs using `htmlspecialchars()` to prevent XSS.
- [ ] Add `FOREIGN KEY` constraints to link tables in MySQL.
- [ ] Add indexes to frequently queried columns (e.g., `email`, `role`).
- [ ] Obfuscate "Account Not Found" errors to prevent email enumeration.

## Medium Priority (UI/UX)
- [ ] Add loading spinners to form submit buttons to prevent double submission.
- [ ] Implement proper CSS Variables for a toggleable Dark Mode.
- [ ] Design friendly "Empty States" illustrations for Dashboard tables.
- [ ] Ensure large dashboard data tables have horizontal scroll wrappers on Mobile.
- [ ] Implement toast notifications instead of standard JS `alert()` for errors.

## Low Priority (Code Quality & Refactoring)
- [ ] Separate raw SQL queries into a central `Database` or `Model` file.
- [ ] Abstract Mailer logic so `config/mail.php` handles templating cleanly.
- [ ] Minify and bundle CSS/JS assets for production.
- [ ] Implement server-side checks for file upload limits.
- [ ] Validate MIME types for file uploads strictly (not just extensions).
- [ ] Normalize `approvals` table to track historical status changes.
- [ ] Introduce a routing mechanism to prevent direct access to raw `.php` files.
- [ ] Remove or `.gitignore` the `scratch/` directory.
