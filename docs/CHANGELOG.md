# Changelog

All notable changes to the `iSubmit` project will be documented in this file.

## [Unreleased]

### Added
- **Proposal Submission Lifecycle (2026-07-07)**: Hardened the whole upload → review → history flow:
  - Delete is now a guarded action — only an un-reviewed `Pending` draft can be removed; reviewed
    versions are locked into a read-only history (`module_proposal.php`, `upload_handler.php`).
  - **Supersede-on-reupload**: uploading a new file automatically removes the previous un-reviewed
    `Pending` draft for that item (files pruned only when no surviving row shares the path), so at most
    one Pending version exists and ghost drafts no longer accumulate.
  - **Audit link**: added `activity_logs.upload_id` (nullable FK, `ON DELETE SET NULL`) so log entries
    reconcile to a specific upload.
  - **CSRF protection** for the proposal upload/withdraw and admin proposal-review actions.
  - **Admin archive workflow**: status tabs restructured to *Action Needed* (default) / *Revision History*
    / *Approved Archive* / *All*, so approved submissions leave the active queue but stay viewable.
- **Premium Messaging UI (v2.0)**: Overhauled the entire `message.php` module to feature a dual-pane responsive layout.
- **Interactive Emoji Reactions**: Implemented persistent emoji reactions on message bubbles, triggering via desktop hover or mobile double-tap. Added `reaction` columns to `group_messages` and `staff_messages` tables.
- **Dynamic Active States**: Pinned and recent groups now visually indicate online status and highlight elegantly when active.
- **README.md, INSTALLATION.md, SYSTEM_ARCHITECTURE.md**: Comprehensive Markdown documentation generated.
- **Favicon**: Created `favicon.svg` featuring Quill the Mascot and linked it across all authentication pages.

### Changed
- **Admin Review UI (`admin_module_dynamic.php`)**: Consolidated the per-submission review into a single
  **View & Evaluate** entry point — removed the redundant inline status dropdown + **Update** button. The
  Document column now shows a truncated filename (full name on hover) so long titles no longer break the card.
- **Revision styling (`dashboard-cards.css`)**: Requirement cards marked *Revision Requested* now render red
  (matching the badge/border/icon) instead of the amber shared with pending/under-review.
- **Notification badges**: Sidebar nav counts (`coordinator.php`, `director.php`) now count only the latest
  submission per group per item (director proposal count now spans items 11–16, added a Statistics badge),
  so superseded drafts no longer inflate the numbers.
- **Splash Screen Loader (`login.php`)**: Increased the animation duration to 4.2 seconds to give the cinematic intro more breathing room.
- **Mascot Animation Delay**: Delayed Quill's automatic wave on the login screen by 1.2 seconds so he stands still briefly before interacting.
- **Mascot Wave Mechanics (`mascot.css`)**: Adjusted the translation values in the `@keyframes quillWaveHigh` to ensure Quill's wing remains physically attached to his shoulder while waving.
- **Mascot SVG Architecture (`mascot.php`)**: Grouped body and limb vectors into proper `<g>` tags and applied explicit `transform-origin` pivots (e.g., planting the right foot at `74px 130px`) to prevent floating/detaching limbs during CSS animations.
- **Page Titles**: Standardized all browser tab `<title>` tags in the `auth/` directory to use the `iSubmit` brand name.

### Fixed
- **Broken current-submission delete**: the student current-card delete button was sending `upload_id=0`
  (the query never selected `upload_id`); it now deletes the correct upload.
- **Item 14 "Approve" doing nothing**: the 22 Form-008 rubric radios were `required` while hidden inside
  collapsed accordions, so a native submit failed silently after a rubric-less rejection. Rubric completeness
  is now validated in JS with a clear message that expands the incomplete section.
- **Preview → Cancel navigation**: closing the document preview no longer collapses the mobile requirement
  card; it returns to the same card (`dashboard-cards.js` `closeDlModal`).
- **Removal log said "a file"**: the item name is now looked up before the row is deleted.
- **Staff Messaging Syntax Error**: Removed a stray curly brace in `staff_message_handler.php` that was blocking communication between Group Leaders and Admins.
- **Splash Mascot Floating Foot**: Added missing `transform-origin` to the left foot inline style in the splash screen SVG to stop it from rotating wildly off-screen.

---
*Note: Older history is tracked via Git commits.*
