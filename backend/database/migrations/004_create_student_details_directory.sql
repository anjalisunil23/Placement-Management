-- Students directory loaded from the AES sync snapshot.
-- STUDENT, ADM.NO, DEPARTMENT, STUD_ROLE, BATCH, ACTION.
-- If the retired JSON student_details table is still present, drop it first (003).

CREATE TABLE IF NOT EXISTS student_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_name VARCHAR(255) NOT NULL DEFAULT '',
  adm_no VARCHAR(64) NOT NULL DEFAULT '',
  department VARCHAR(255) NOT NULL DEFAULT '',
  stud_role VARCHAR(16) NOT NULL,
  batch VARCHAR(128) NOT NULL DEFAULT '',
  action VARCHAR(191) NOT NULL,
  synced_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_student_details_action (action),
  KEY idx_student_details_role (stud_role),
  KEY idx_student_details_adm_no (adm_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
