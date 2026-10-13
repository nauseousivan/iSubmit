-- 2026-09-19b — a section label repeats across year levels; the unique key must say so.
--
-- BUG this fixes: the key was (department, program, label). Offices name sections just "A" / "B"
-- and let the YEAR distinguish them, so creating 3rd Year "A" after 4th Year "A" in the same
-- course was rejected as a duplicate. It is not a duplicate — it is a different room.
--
-- year_level becomes NOT NULL DEFAULT '' because MySQL allows repeated NULLs inside a UNIQUE
-- index: leaving it nullable would have let two year-less "A"s through the new key anyway.

UPDATE sections SET year_level = '' WHERE year_level IS NULL;

ALTER TABLE sections
    MODIFY COLUMN year_level VARCHAR(20) NOT NULL DEFAULT '';

ALTER TABLE sections
    DROP INDEX uniq_section_label,
    ADD UNIQUE KEY uniq_section_label (department, program, year_level, label);
