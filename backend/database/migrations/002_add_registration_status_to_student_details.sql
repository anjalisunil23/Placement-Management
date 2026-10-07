-- Portal registration status for studying students (not AES-owned).
-- registered | non_registered | NULL for alumni

ALTER TABLE student_details
  ADD COLUMN registration_status VARCHAR(16)
    GENERATED ALWAYS AS (
      NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.registrationStatus')), ''), 'null')
    ) STORED
    AFTER stud_role;

ALTER TABLE student_details
  ADD INDEX idx_student_details_registration (stud_role, registration_status);
