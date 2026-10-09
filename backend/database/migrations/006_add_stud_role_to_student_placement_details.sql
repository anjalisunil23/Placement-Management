-- stud_role on placement rows: student or alumni, matched from student_details by admission number.

ALTER TABLE student_placement_details
  ADD COLUMN stud_role VARCHAR(16) NOT NULL DEFAULT '' AFTER admno;
