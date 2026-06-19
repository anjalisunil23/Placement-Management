<?php

declare(strict_types=1);

namespace PMS\Config;

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;

/**
 * MongoDB connection singleton.
 */
class Database
{
    private static ?Client $client = null;
    private static ?MongoDatabase $database = null;

    public static function connect(): MongoDatabase
    {
        if (self::$database !== null) {
            return self::$database;
        }

        $uri  = $_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017';
        $name = $_ENV['MONGODB_DATABASE'] ?? 'pms_db';

        self::$client   = new Client($uri);
        self::$database = self::$client->selectDatabase($name);

        return self::$database;
    }

    public static function collection(string $name): \MongoDB\Collection
    {
        return self::connect()->selectCollection($name);
    }

    /** Create indexes for all collections (run once on setup). */
    public static function setupIndexes(): void
    {
        $db = self::connect();

        // users
        $db->selectCollection('users')->createIndexes([
            ['key' => ['email' => 1], 'unique' => true],
            ['key' => ['role' => 1]],
            ['key' => ['status' => 1]],
        ]);

        // students
        $db->selectCollection('students')->createIndexes([
            ['key' => ['userId' => 1], 'unique' => true],
            ['key' => ['registerNumber' => 1], 'unique' => true],
            ['key' => ['departmentId' => 1]],
            ['key' => ['cgpa' => 1]],
        ]);

        // staff
        $db->selectCollection('staff')->createIndexes([
            ['key' => ['userId' => 1], 'unique' => true],
            ['key' => ['departmentId' => 1]],
        ]);

        // department-wise placement officers
        $db->selectCollection('placement_officers')->createIndexes([
            ['key' => ['userId' => 1], 'unique' => true],
            ['key' => ['departmentId' => 1], 'unique' => true],
        ]);

        // companies
        $db->selectCollection('companies')->createIndexes([
            ['key' => ['userId' => 1], 'unique' => true, 'sparse' => true],
            ['key' => ['companyName' => 1]],
            ['key' => ['tier' => 1]],
            ['key' => ['category' => 1]],
        ]);

        // alumni
        $db->selectCollection('alumni')->createIndexes([
            ['key' => ['userId' => 1], 'unique' => true],
        ]);

        // departments
        $db->selectCollection('departments')->createIndexes([
            ['key' => ['code' => 1], 'unique' => true],
        ]);

        // drives
        $db->selectCollection('drives')->createIndexes([
            ['key' => ['companyId' => 1]],
            ['key' => ['date' => 1]],
            ['key' => ['type' => 1]],
        ]);

        // applications
        $db->selectCollection('applications')->createIndexes([
            ['key' => ['studentId' => 1, 'driveId' => 1], 'unique' => true],
            ['key' => ['status' => 1]],
            ['key' => ['companyId' => 1]],
        ]);

        // jobs
        $db->selectCollection('jobs')->createIndexes([
            ['key' => ['companyId' => 1]],
            ['key' => ['driveId' => 1]],
        ]);

        // notifications
        $db->selectCollection('notifications')->createIndexes([
            ['key' => ['userId' => 1, 'read' => 1]],
            ['key' => ['createdAt' => -1]],
        ]);

        // resumes
        $db->selectCollection('resumes')->createIndexes([
            ['key' => ['studentId' => 1, 'createdAt' => -1]],
            ['key' => ['studentId' => 1, 'isDefault' => 1]],
        ]);

        // blacklist
        $db->selectCollection('blacklist')->createIndexes([
            ['key' => ['studentId' => 1], 'unique' => true],
        ]);

        // rules
        $db->selectCollection('rules')->createIndexes([
            ['key' => ['active' => 1]],
        ]);

        $db->selectCollection('reports')->createIndexes([
            ['key' => ['type' => 1]],
            ['key' => ['createdAt' => -1]],
        ]);

        $db->selectCollection('recommendations')->createIndexes([
            ['key' => ['staffId' => 1]],
            ['key' => ['status' => 1]],
            ['key' => ['staffId' => 1, 'createdAt' => -1]],
            ['key' => ['createdAt' => -1]],
        ]);

        $db->selectCollection('alumni_referrals')->createIndexes([
            ['key' => ['alumniUserId' => 1]],
            ['key' => ['createdAt' => -1]],
        ]);

        $db->selectCollection('alumni_job_posts')->createIndexes([
            ['key' => ['alumniUserId' => 1]],
            ['key' => ['status' => 1]],
            ['key' => ['createdAt' => -1]],
        ]);

        // recruitment results
        $db->selectCollection('recruitment_results')->createIndexes([
            ['key' => ['registerNumber' => 1]],
            ['key' => ['company' => 1]],
            ['key' => ['driveId' => 1]],
            ['key' => ['registerNumber' => 1, 'driveId' => 1]],
            ['key' => ['status' => 1]],
            ['key' => ['departmentId' => 1]],
            ['key' => ['classBatch' => 1]],
            ['key' => ['createdAt' => -1]],
        ]);

        $db->selectCollection('system_settings')->createIndexes([
            ['key' => ['key' => 1], 'unique' => true],
        ]);

        $db->selectCollection('public_page_content')->createIndexes([
            ['key' => ['key' => 1], 'unique' => true],
        ]);

        $db->selectCollection('placement_news')->createIndexes([
            ['key' => ['date' => -1]],
            ['key' => ['createdAt' => -1]],
        ]);
    }
}
