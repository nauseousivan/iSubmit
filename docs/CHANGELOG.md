# Changelog

All notable changes to the `iSubmit` project will be documented in this file.

## [Unreleased]

### Added
- **Premium Messaging UI (v2.0)**: Overhauled the entire `message.php` module to feature a dual-pane responsive layout.
- **Interactive Emoji Reactions**: Implemented persistent emoji reactions on message bubbles, triggering via desktop hover or mobile double-tap. Added `reaction` columns to `group_messages` and `staff_messages` tables.
- **Dynamic Active States**: Pinned and recent groups now visually indicate online status and highlight elegantly when active.
- **README.md, INSTALLATION.md, SYSTEM_ARCHITECTURE.md**: Comprehensive Markdown documentation generated.
- **Favicon**: Created `favicon.svg` featuring Quill the Mascot and linked it across all authentication pages.

### Changed
- **Splash Screen Loader (`login.php`)**: Increased the animation duration to 4.2 seconds to give the cinematic intro more breathing room.
- **Mascot Animation Delay**: Delayed Quill's automatic wave on the login screen by 1.2 seconds so he stands still briefly before interacting.
- **Mascot Wave Mechanics (`mascot.css`)**: Adjusted the translation values in the `@keyframes quillWaveHigh` to ensure Quill's wing remains physically attached to his shoulder while waving.
- **Mascot SVG Architecture (`mascot.php`)**: Grouped body and limb vectors into proper `<g>` tags and applied explicit `transform-origin` pivots (e.g., planting the right foot at `74px 130px`) to prevent floating/detaching limbs during CSS animations.
- **Page Titles**: Standardized all browser tab `<title>` tags in the `auth/` directory to use the `iSubmit` brand name.

### Fixed
- **Staff Messaging Syntax Error**: Removed a stray curly brace in `staff_message_handler.php` that was blocking communication between Group Leaders and Admins.
- **Splash Mascot Floating Foot**: Added missing `transform-origin` to the left foot inline style in the splash screen SVG to stop it from rotating wildly off-screen.

---
*Note: Older history is tracked via Git commits.*
