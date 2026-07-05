# Contributing to iSubmit

Thank you for your interest in contributing to the `iSubmit` platform! As this is an internal platform for ISAP and MCNP, please follow these guidelines to ensure code consistency and quality.

## 🛠️ Development Workflow

1. **Branch Naming:** Create a new branch for your feature or fix.
   - `feature/dashboard-ui`
   - `fix/login-rate-limit`
2. **Coding Standards:**
   - **PHP:** Use PHP 8+ syntax. Do not use deprecated functions.
   - **CSS:** Do not use utility frameworks like Tailwind unless previously discussed. Use the custom variables in `assets/css/theme.css`.
   - **JS:** Keep JavaScript Vanilla. Avoid jQuery or heavy frameworks.
3. **Mascot Adjustments:** If you need to modify "Quill" (the mascot), update `assets/mascot/mascot.php` and test all states (`idle`, `shy`, `wave`) to ensure his SVG pivots remain intact.

## 🐛 Submitting a Pull Request
1. Ensure your code does not break the mobile responsiveness.
2. Ensure you have sanitized all inputs and escaped all outputs (`htmlspecialchars()`).
3. Request a review from the lead developer.
