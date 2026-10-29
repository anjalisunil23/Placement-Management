-- Offer-letter follow-up metadata on student_placement_details.
-- Prefer application ensureColumns (StudentPlacementDetailsTable) which adds
-- missing columns safely. Run these only if the columns are absent.

-- ALTER TABLE `student_placement_details` ADD COLUMN `offer_letter_exception` VARCHAR(64) NOT NULL DEFAULT '';
-- ALTER TABLE `student_placement_details` ADD COLUMN `offer_letter_exception_note` VARCHAR(512) NOT NULL DEFAULT '';
-- ALTER TABLE `student_placement_details` ADD COLUMN `offer_letter_followup_at` VARCHAR(32) NOT NULL DEFAULT '';
-- ALTER TABLE `student_placement_details` ADD COLUMN `offer_letter_followup_note` VARCHAR(512) NOT NULL DEFAULT '';
