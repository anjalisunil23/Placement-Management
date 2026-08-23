-- Resume Builder Phase 10 — isolated Activities table.
-- Safe to run on existing production databases (does not alter other tables).

CREATE TABLE IF NOT EXISTS resume_activities (
  id CHAR(24) NOT NULL PRIMARY KEY,
  student_id CHAR(24) NOT NULL,
  title VARCHAR(150) NOT NULL,
  activity_type VARCHAR(32) NOT NULL,
  organization VARCHAR(150) NULL,
  description VARCHAR(1000) NOT NULL,
  activity_date DATE NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_resume_activities_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
