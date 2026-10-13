-- 2026-09-25 — Application for Research Defense (Form 002) is ALSO a proposal requirement.
--
-- The office files this form for BOTH defenses. Item 20 is the Final defense's copy and is its
-- phase-1 gate (df_phase1_items('final') = [20]); uploads are keyed by item_id, so reusing 20
-- would mean the proposal's approval instantly cleared Final's application and unlocked
-- requirements 22-27 early. The proposal therefore gets its OWN row: item 17, form_id 1.
--
-- It is the FIRST card in the proposal grid — the application is what opens the defense — so the
-- grid goes from 6 requirements to 7 (17, 11, 12, 14, 13, 15, 16). Groups already sitting at 6/6
-- drop to 6/7 until they upload it; that is intended, it is a requirement that was missing.

INSERT INTO `checklist_items`
    (`item_id`, `form_id`, `item_name`, `required_format`, `description`, `requirement_name`, `required_file_type`)
VALUES
    (17, 1, 'Application for Research Defense (Form 002)', 'PDF',
     'Download the form, fill it out, have it signed, then upload it to apply for your proposal defense.',
     '', 'pdf')
ON DUPLICATE KEY UPDATE
    `form_id` = VALUES(`form_id`),
    `item_name` = VALUES(`item_name`),
    `required_format` = VALUES(`required_format`),
    `description` = VALUES(`description`),
    `required_file_type` = VALUES(`required_file_type`);
