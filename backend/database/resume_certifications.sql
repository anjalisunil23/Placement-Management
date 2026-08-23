-- Resume Builder Phase 9 — isolated Certifications table.
-- Safe to run on existing production databases (does not alter other tables).

CREATE TABLE IF NOT EXISTS resume_certifications (
  id CHAR(24) NOT NULL PRIMARY KEY,
  student_id CHAR(24) NOT NULL,
  certification_name VARCHAR(200) NOT NULL,
  issuing_organization VARCHAR(150) NOT NULL,
  issue_date DATE NOT NULL,
  expiry_date DATE NULL,
  credential_id VARCHAR(100) NULL,
  credential_url VARCHAR(500) NULL,
  description VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_resume_certifications_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
