# Product Roadmap

This roadmap outlines the long-term vision and upcoming milestones for the `iSubmit` Research Digitalization Platform.

## Milestone 1: Security Hardening (Current Focus)
Before expanding the application features, the foundation must be secured.
- Patch Session Fixation and Brute Force vulnerabilities.
- Implement CSRF protection across all forms.
- Enforce strict Foreign Key constraints in the database to guarantee data integrity.

## Milestone 2: Dashboard Development
Building out the core role-based portals.
- **Student Portal:** Interface for uploading requirements, viewing approval status, and chatting with advisers.
- **Coordinator Portal:** Interface for managing students, reviewing Form 008, and forwarding to statisticians.
- **Statistician Portal:** Interface for downloading data sheets, returning feedback, and approving statistical treatments.

## Milestone 3: UI/UX Polish
Enhancing the visual experience.
- Implement a global Dark Mode toggle.
- Create empty state illustrations for all data tables.
- Add toast notifications to replace all native browser alerts.

## Milestone 4: Performance & Optimization
Preparing the platform for heavy concurrent usage.
- Move database queries to an abstracted Model layer.
- Implement server-side file compression for PDFs and Images to reduce storage costs.
- Minify all CSS and JS assets for production deployment.
