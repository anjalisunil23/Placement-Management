<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;

/**
 * Flat placement / higher-education columns for student_placement_details.
 */
class StudentPlacementDetailsTable
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function isReady(): bool
    {
        try {
            $column = $this->db->query("SHOW COLUMNS FROM `student_placement_details` LIKE 'admno'");

            return (bool) $column->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensure(): bool
    {
        if ($this->isReady()) {
            return true;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS student_placement_details (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              student VARCHAR(255) NOT NULL DEFAULT \'\',
              admno VARCHAR(64) NOT NULL DEFAULT \'\',
              cno VARCHAR(64) NOT NULL DEFAULT \'\',
              email VARCHAR(255) NOT NULL DEFAULT \'\',
              `year` VARCHAR(64) NOT NULL DEFAULT \'\',
              courseid VARCHAR(64) NOT NULL DEFAULT \'\',
              branchid VARCHAR(64) NOT NULL DEFAULT \'\',
              employer VARCHAR(255) NOT NULL DEFAULT \'\',
              empcno VARCHAR(128) NOT NULL DEFAULT \'\',
              empadr VARCHAR(512) NOT NULL DEFAULT \'\',
              payscale VARCHAR(128) NOT NULL DEFAULT \'\',
              status VARCHAR(64) NOT NULL DEFAULT \'\',
              createdBy VARCHAR(128) NOT NULL DEFAULT \'\',
              updatedBy VARCHAR(128) NOT NULL DEFAULT \'\',
              updatedate DATETIME NULL,
              fordvv VARCHAR(16) NOT NULL DEFAULT \'\',
              `type` VARCHAR(64) NOT NULL DEFAULT \'\',
              includedvv VARCHAR(16) NOT NULL DEFAULT \'\',
              createdat DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_student_placement_details_admno (admno),
              KEY idx_student_placement_details_year (`year`),
              KEY idx_student_placement_details_type (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return $this->isReady();
    }
}
