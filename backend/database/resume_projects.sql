-- Resume Builder Phase 7 — isolated Projects table.
-- Safe to run on existing production databases (does not alter other tables).

CREATE TABLE IF NOT EXISTS resume_projects (
  id CHAR(24) NOT NULL PRIMARY KEY,
  student_id CHAR(24) NOT NULL,
  project_title VARCHAR(150) NOT NULL,
  project_type VARCHAR(32) NOT NULL,
  technologies_used VARCHAR(500) NULL,
  project_description VARCHAR(1000) NOT NULL,
  project_link VARCHAR(500) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_resume_projects_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
