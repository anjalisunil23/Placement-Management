-- Resume Builder Phase 6 — isolated Skills table.
-- Safe to run on existing production databases (does not alter other tables).

CREATE TABLE IF NOT EXISTS resume_skills (
  id CHAR(24) NOT NULL PRIMARY KEY,
  student_id CHAR(24) NOT NULL,
  skill_name VARCHAR(50) NOT NULL,
  skill_category VARCHAR(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_resume_skills_student_name (student_id, skill_name),
  KEY idx_resume_skills_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
