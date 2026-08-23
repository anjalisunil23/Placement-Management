-- Resume Builder Phase 8 — isolated Experience table.
-- Safe to run on existing production databases (does not alter other tables).

CREATE TABLE IF NOT EXISTS resume_experience (
  id CHAR(24) NOT NULL PRIMARY KEY,
  student_id CHAR(24) NOT NULL,
  organization_name VARCHAR(150) NOT NULL,
  position_title VARCHAR(150) NOT NULL,
  experience_type VARCHAR(32) NOT NULL,
  location VARCHAR(150) NULL,
  description VARCHAR(1000) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  currently_working TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_resume_experience_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
