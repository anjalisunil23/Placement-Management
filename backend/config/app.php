<?php

declare(strict_types=1);

/**
 * Application configuration loader.
 */

$rootPath = dirname(__DIR__, 2);

if (file_exists($rootPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($rootPath);
    $dotenv->load();
}
if (file_exists($rootPath . '/.env.local')) {
    $dotenv = Dotenv\Dotenv::createMutable($rootPath, '.env.local');
    $dotenv->load();
}

return [
    'name'    => $_ENV['APP_NAME'] ?? 'AJCE Placements',
    'env'     => $_ENV['APP_ENV'] ?? 'production',
    'debug'   => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'     => rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/'),
    'jwt'     => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'insecure-default-change-me',
        'expiry' => (int) ($_ENV['JWT_EXPIRY'] ?? 86400),
    ],
    'session' => [
        'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 7200),
    ],
    'mail' => [
        'driver'     => $_ENV['MAIL_DRIVER'] ?? 'elasticemail',
        'host'       => $_ENV['MAIL_HOST'] ?? 'localhost',
        'port'       => (int) ($_ENV['MAIL_PORT'] ?? 587),
        'username'   => $_ENV['MAIL_USERNAME'] ?? '',
        'password'   => $_ENV['MAIL_PASSWORD'] ?? '',
        'from'       => $_ENV['MAIL_FROM'] ?? 'info@aessas.org',
        'from_name'  => $_ENV['MAIL_FROM_NAME'] ?? 'Placement Cell - Amal Jyothi',
        'api_key'    => $_ENV['ELASTICEMAIL_API_KEY'] ?? '',
        'endpoint'   => $_ENV['ELASTICEMAIL_ENDPOINT'] ?? 'https://api.elasticemail.com/v2/email/send',
        'timeout'    => (int) ($_ENV['MAIL_TIMEOUT'] ?? 20),
        'verify_ssl' => filter_var($_ENV['MAIL_VERIFY_SSL'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
    ],
    'whatsapp' => [
        'enabled'  => filter_var($_ENV['WHATSAPP_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
        'endpoint' => $_ENV['WHATSAPP_ENDPOINT'] ?? 'https://wapi.aesajce.in/send',
        'tag'      => $_ENV['WHATSAPP_TAG'] ?? 'AJCE Placements',
        'template' => $_ENV['WHATSAPP_TEMPLATE'] ?? 'ajce_official_notification',
        'signature'=> $_ENV['WHATSAPP_SIGNATURE'] ?? 'AJCE Placement Cell',
        'timeout'  => (int) ($_ENV['WHATSAPP_TIMEOUT'] ?? 15),
    ],
    'uploads' => [
        'max_resume' => (int) ($_ENV['MAX_RESUME_SIZE'] ?? 5242880),
        'max_certificate' => (int) ($_ENV['MAX_CERTIFICATE_SIZE'] ?? 5242880),
        'max_jd'     => (int) ($_ENV['MAX_JD_SIZE'] ?? 10485760),
        'resume_dir' => $rootPath . '/uploads/resumes',
        'certificate_dir' => $rootPath . '/uploads/certificates',
        'reports_dir'=> $rootPath . '/uploads/reports',
        'jd_dir'     => $rootPath . '/uploads/jd',
        'shortlist_dir' => $rootPath . '/uploads/shortlists',
        'max_shortlist' => (int) ($_ENV['MAX_SHORTLIST_SIZE'] ?? 10485760),
        'signed_dir' => $rootPath . '/uploads/signed_reports',
        'offer_letter_dir' => $rootPath . '/uploads/offer_letters',
        'self_placement_dir' => $rootPath . '/uploads/self_placement',
        'alumni_employment_dir' => $rootPath . '/uploads/alumni_employment',
        'photo_dir'  => $rootPath . '/uploads/photos',
        'job_poster_dir' => $rootPath . '/uploads/job-posters',
        'max_job_poster' => (int) ($_ENV['MAX_JOB_POSTER_SIZE'] ?? 10485760),
    ],
    'cors' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:8080,http://127.0.0.1:8080')
        )),
    ],
    'roles' => [
        'admin'             => 'Admin',
        'student'           => 'Student',
        'staff'             => 'Staff',
        'company'           => 'Company',
        'alumni'            => 'Alumni',
        'placement_officer' => 'Department Placement Officer',
    ],
    'role_dashboards' => [
        'admin'             => '/dashboard.html',
        'student'           => '/drives.html',
        'staff'             => '/staff-recommend.html',
        'company'           => '/company.html',
        'alumni'            => '/dashboard.html',
        'placement_officer' => '/dashboard.html',
    ],
    'super_admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', $_ENV['SUPER_ADMIN_EMAILS'] ?? 'placements@amaljyothi.ac.in')
    ))),
];
