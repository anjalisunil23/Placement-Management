-- Rename createdat to createdate on existing student_placement_details tables.
-- New installs already create createdate (005).

ALTER TABLE student_placement_details
  CHANGE `createdat` `createdate` DATETIME NULL;
