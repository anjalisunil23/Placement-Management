<?php

declare(strict_types=1);

namespace PMS\Schemas;

/**
 * MongoDB collection schemas and field definitions.
 * Used for validation and documentation.
 */
final class Collections
{
    public const USERS = 'users';
    public const STUDENTS = 'students';
    public const STAFF = 'staff';
    public const COMPANIES = 'companies';
    public const ALUMNI = 'alumni';
    public const DEPARTMENTS = 'departments';
    public const DRIVES = 'drives';
    public const APPLICATIONS = 'applications';
    public const JOBS = 'jobs';
    public const NOTIFICATIONS = 'notifications';
    public const REPORTS = 'reports';
    public const RULES = 'rules';
    public const BLACKLIST = 'blacklist';
    public const RECOMMENDATIONS = 'recommendations';
    public const ALUMNI_REFERRALS = 'alumni_referrals';
    public const PLACEMENT_OFFICERS = 'placement_officers';
    public const RECRUITMENT_RESULTS = 'recruitment_results';
    public const SYSTEM_SETTINGS = 'system_settings';
    public const PUBLIC_PAGE_CONTENT = 'public_page_content';
    public const PLACEMENT_NEWS = 'placement_news';

    /** Valid user roles */
    public const ROLES = [
        'admin',
        'student',
        'staff',
        'company',
        'alumni',
        'placement_officer',
    ];

    public const USER_STATUS = ['active', 'blocked', 'pending'];

    public const APPLICATION_STATUS = [
        'applied',
        'resume_pending',
        'resume_verified',
        'officer_approved',
        'company_review',
        'shortlisted',
        'selected',
        'rejected',
        'withdrawn',
    ];

    public const DRIVE_TYPES = ['exclusive', 'pooled', 'direct'];

    public const COMPANY_TIERS = ['Tier 1', 'Tier 2', 'Tier 3'];

    public const COMPANY_CATEGORIES = [
        'Software',
        'Mechanical',
        'Chemical',
        'Food',
        'Production',
    ];

    /**
     * Default document shapes for each collection.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemas(): array
    {
        return [
            self::USERS => [
                '_id'       => 'ObjectId',
                'name'      => 'string',
                'email'     => 'string',
                'password'  => 'string (bcrypt hash)',
                'role'      => 'string (enum)',
                'status'    => 'string (active|blocked|pending)',
                'approved'  => 'bool',
                'createdAt' => 'UTCDateTime',
                'updatedAt' => 'UTCDateTime',
            ],
            self::STUDENTS => [
                '_id'            => 'ObjectId',
                'userId'         => 'ObjectId',
                'registerNumber' => 'string',
                'departmentId'   => 'ObjectId',
                'personal'       => [
                    'phone'   => 'string',
                    'address' => 'string',
                    'dob'     => 'string',
                ],
                'academic' => [
                    'ugMarks'    => 'float',
                    'mcaMarks'   => 'float',
                    'cgpa'       => 'float',
                    'backlogs'   => 'int',
                    'semesters'  => 'array',
                ],
                'certifications' => 'array[{name, issuer, year}]',
                'resume'         => [
                    'filename'   => 'string',
                    'path'       => 'string',
                    'verified'   => 'bool',
                    'uploadedAt' => 'UTCDateTime',
                ],
                'policyAccepted' => 'bool',
                'signedReport'   => 'string|null',
                'placementChances' => [
                    'used'      => 'int',
                    'remaining' => 'int',
                ],
                'placed'         => 'bool',
                'placementHistory'=> 'array',
                'createdAt'      => 'UTCDateTime',
                'updatedAt'      => 'UTCDateTime',
            ],
            self::STAFF => [
                '_id'       => 'ObjectId',
                'userId'    => 'ObjectId',
                'departmentId' => 'ObjectId|null',
                'designation' => 'string',
                'createdAt' => 'UTCDateTime',
            ],
            self::PLACEMENT_OFFICERS => [
                '_id'          => 'ObjectId',
                'userId'       => 'ObjectId',
                'departmentId' => 'ObjectId (unique — one placement officer per department)',
                'designation'  => 'string',
                'createdAt'    => 'UTCDateTime',
                'updatedAt'    => 'UTCDateTime',
            ],
            self::COMPANIES => [
                '_id'           => 'ObjectId',
                'userId'        => 'ObjectId|null',
                'companyName'   => 'string',
                'category'      => 'string (enum)',
                'tier'          => 'string (enum)',
                'contacts'      => 'array[{name, email, phone}]',
                'recruitmentHistory' => 'array',
                'associationStatus'  => 'string (active|inactive|pending)',
                'comments'           => 'string',
                'website'       => 'string',
                'description'   => 'string',
                'createdAt'     => 'UTCDateTime',
                'updatedAt'     => 'UTCDateTime',
            ],
            self::ALUMNI => [
                '_id'        => 'ObjectId',
                'userId'     => 'ObjectId',
                'company'    => 'string',
                'role'       => 'string',
                'experience' => 'int (years)',
                'skills'     => 'array',
                'createdAt'  => 'UTCDateTime',
                'updatedAt'  => 'UTCDateTime',
            ],
            self::DEPARTMENTS => [
                '_id'       => 'ObjectId',
                'name'      => 'string',
                'code'      => 'string',
                'createdAt' => 'UTCDateTime',
                'updatedAt' => 'UTCDateTime',
            ],
            self::DRIVES => [
                '_id'         => 'ObjectId',
                'title'       => 'string',
                'companyId'   => 'ObjectId',
                'type'        => 'string (exclusive|pooled|direct)',
                'date'        => 'string',
                'time'        => 'string',
                'branches'    => 'array (department codes)',
                'eligibility' => [
                    'minCgpa'      => 'float',
                    'maxBacklogs'  => 'int',
                    'skills'       => 'array',
                ],
                'tier'        => 'string',
                'jdFile'      => 'string|null',
                'attendance'  => 'array[{studentId, present}]',
                'results'     => 'array',
                'status'      => 'string (scheduled|ongoing|completed)',
                'createdBy'   => 'ObjectId',
                'departmentId'=> 'ObjectId|null (officer department for dept-wise drives)',
                'createdAt'   => 'UTCDateTime',
                'updatedAt'   => 'UTCDateTime',
            ],
            self::APPLICATIONS => [
                '_id'       => 'ObjectId',
                'studentId' => 'ObjectId',
                'driveId'   => 'ObjectId',
                'companyId' => 'ObjectId',
                'jobId'     => 'ObjectId|null',
                'status'    => 'string (workflow enum)',
                'remarks'   => 'string',
                'timeline'  => 'array[{status, at, by}]',
                'createdAt' => 'UTCDateTime',
                'updatedAt' => 'UTCDateTime',
            ],
            self::JOBS => [
                '_id'         => 'ObjectId',
                'companyId'   => 'ObjectId',
                'driveId'     => 'ObjectId|null',
                'title'       => 'string',
                'description' => 'string',
                'jdFile'      => 'string|null',
                'eligibility' => [
                    'minCgpa'     => 'float',
                    'departments' => 'array',
                    'skills'      => 'array',
                    'maxBacklogs' => 'int',
                ],
                'package'     => 'string',
                'location'    => 'string',
                'createdAt'   => 'UTCDateTime',
                'updatedAt'   => 'UTCDateTime',
            ],
            self::NOTIFICATIONS => [
                '_id'       => 'ObjectId',
                'userId'    => 'ObjectId',
                'type'      => 'string',
                'title'     => 'string',
                'message'   => 'string',
                'read'      => 'bool',
                'metadata'  => 'object',
                'createdAt' => 'UTCDateTime',
            ],
            self::REPORTS => [
                '_id'       => 'ObjectId',
                'type'      => 'string (company|student|monthly)',
                'filename'  => 'string',
                'path'      => 'string',
                'generatedBy' => 'ObjectId',
                'filters'   => 'object',
                'createdAt' => 'UTCDateTime',
            ],
            self::RULES => [
                '_id'               => 'ObjectId',
                'name'              => 'string',
                'minCgpa'           => 'float',
                'maxBacklogs'       => 'int',
                'placementChances'  => 'int',
                'eligibilityCriteria' => 'string',
                'tierRules'         => 'object',
                'active'            => 'bool',
                'createdAt'         => 'UTCDateTime',
                'updatedAt'         => 'UTCDateTime',
            ],
            self::BLACKLIST => [
                '_id'       => 'ObjectId',
                'studentId' => 'ObjectId',
                'reason'    => 'string',
                'blacklistedBy' => 'ObjectId',
                'createdAt' => 'UTCDateTime',
                'removedAt' => 'UTCDateTime|null',
            ],
            self::RECOMMENDATIONS => [
                '_id'         => 'ObjectId',
                'staffId'     => 'ObjectId',
                'companyName' => 'string',
                'companyWebsite' => 'string',
                'category'    => 'string',
                'reason'      => 'string',
                'contact'     => ['name' => 'string', 'email' => 'string', 'phone' => 'string'],
                'status'      => 'string (pending|contacted|registered)',
                'createdAt'   => 'UTCDateTime',
            ],
            self::RECRUITMENT_RESULTS => [
                '_id'           => 'ObjectId',
                'studentName'   => 'string',
                'registerNumber'=> 'string',
                'departmentId'  => 'ObjectId|null',
                'classBatch'    => 'string',
                'company'       => 'string',
                'role'          => 'string',
                'package'       => 'string',
                'status'        => 'string (selected|rejected)',
                'joiningDate'   => 'string (YYYY-MM-DD)',
                'createdAt'     => 'UTCDateTime',
                'updatedAt'     => 'UTCDateTime',
            ],
            self::SYSTEM_SETTINGS => [
                '_id'              => 'ObjectId',
                'key'              => 'string (default)',
                'placementYear'    => 'string',
                'emailFrom'        => 'string',
                'maxUploadMb'      => 'int',
                'smtpEnabled'      => 'bool',
                'notifyOnApproval' => 'bool',
                'createdAt'        => 'UTCDateTime',
                'updatedAt'        => 'UTCDateTime',
            ],
            self::PUBLIC_PAGE_CONTENT => [
                '_id'          => 'ObjectId',
                'key'          => 'string (default)',
                'season'       => 'string',
                'placed'       => 'int',
                'companies'    => 'int',
                'highestPkg'   => 'float',
                'avgPkg'       => 'float',
                'medianPkg'    => 'float',
                'lowestPkg'    => 'float',
                'headline'     => 'string',
                'achievements' => 'string',
                'createdAt'    => 'UTCDateTime',
                'updatedAt'    => 'UTCDateTime',
            ],
            self::PLACEMENT_NEWS => [
                '_id'       => 'ObjectId',
                'title'     => 'string',
                'summary'   => 'string',
                'date'      => 'string (YYYY-MM-DD)',
                'link'      => 'string',
                'createdAt' => 'UTCDateTime',
                'updatedAt' => 'UTCDateTime',
            ],
        ];
    }
}
