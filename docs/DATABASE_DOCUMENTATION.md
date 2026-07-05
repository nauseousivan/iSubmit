# Database Documentation

This document maps out the core schema of the `digital_research` MySQL database.

## 📊 Core Tables

### `users`
Stores all registered accounts for the platform.
- **`user_id`** (INT, PK, Auto Increment)
- **`username`** (VARCHAR 50)
- **`email`** (VARCHAR 100) - Should be unique.
- **`password`** (VARCHAR 255) - Stores BCrypt hashes.
- **`role`** (ENUM) - `Student`, `Research Coordinator`, `Statistician`, `Research Director`.
- **`department`** / **`institution`** (VARCHAR) - Used for frontend branding logic.

### `otp_verifications`
Temporarily stores OTP codes for email verification and password resets.
- **`otp_id`** (INT, PK)
- **`email`** (VARCHAR 100)
- **`otp_code`** (VARCHAR 6)
- **`expires_at`** (DATETIME) - Ensures codes expire (usually within 15 minutes).
- **`created_at`** (TIMESTAMP)

### `activity_logs`
Tracks historical actions taken by users across the platform.
- **`activity_id`** (INT, PK)
- **`user_id`** (INT) - Refers to the user who performed the action.
- **`title`** (VARCHAR 100) - Short description (e.g., "File Upload").
- **`description`** (TEXT) - Detailed description.
- **`status_type`** (VARCHAR 30) - Determines UI color (`success`, `warning`, `info`).

### `uploads`
Stores metadata and file paths for research documents uploaded by users.
- **`upload_id`** (INT, PK)
- **`user_id`** (INT) - The uploader.
- **`item_id`** (INT) - Links to the specific checklist item requirement.
- **`original_filename`** (VARCHAR 255)
- **`file_path`** (VARCHAR 255) - Path relative to the server root (e.g., `uploads/file.pdf`).

### `approvals`
Tracks the status of a specific document or stage in the research lifecycle.
- **`approval_id`** (INT, PK)
- **`user_id`** (INT)
- **`form_id`** (INT)
- **`coordinator_status`** (ENUM) - `Pending`, `Approved`, `Rejected`.
- **`statistician_status`** (ENUM) - `Not Required`, `Pending`, `Approved`, `Rejected`.

## ⚠️ Current Limitations & Improvements (Planned)
- **Missing Foreign Keys:** Tables currently lack explicit `FOREIGN KEY` constraints. Deleting a user does not automatically cascade and delete their uploads or activity logs.
- **Indexing:** Needs `INDEX` additions on `users.email`, `otp_verifications.email`, and `uploads.user_id` to improve query speeds on large datasets.
