-- AES student master / directory (local source of truth after sync).
-- Placement overlay remains in student_placements.

CREATE TABLE IF NOT EXISTS student_details (
  id CHAR(24) NOT NULL PRIMARY KEY,
  payload JSON NOT NULL,
  aes_admno VARCHAR(32)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.aesAdmno'))) STORED,
  register_number VARCHAR(32)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.registerNumber'))) STORED,
  email VARCHAR(255)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.email'))) STORED,
  course_id VARCHAR(16)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.courseId'))) STORED,
  branch_id VARCHAR(16)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.branchId'))) STORED,
  stud_year VARCHAR(32)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.year'))) STORED,
  stud_class VARCHAR(64)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.classBatch'))) STORED,
  stud_role VARCHAR(16)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.studRole'))) STORED,
  dept_aes_id VARCHAR(16)
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.deptAesId'))) STORED,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_student_details_aes_admno (aes_admno),
  KEY idx_student_details_register (register_number),
  KEY idx_student_details_email (email(191)),
  KEY idx_student_details_course (course_id),
  KEY idx_student_details_branch (branch_id),
  KEY idx_student_details_year (stud_year),
  KEY idx_student_details_class (stud_class),
  KEY idx_student_details_role (stud_role),
  KEY idx_student_details_dept (dept_aes_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
