<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\AlumniModel;
use PMS\Services\JobFeedService;
use PMS\Utils\Response;

final class JobFeedController
{
    public function admin(): void
    {
        RBACMiddleware::requireAdmin();
        Response::success((new JobFeedService())->listForAdmin());
    }

    public function officer(): void
    {
        $user = RBACMiddleware::requireRoles(['placement_officer']);
        Response::success((new JobFeedService())->listForOfficer($user));
    }

    public function staff(): void
    {
        $user = RBACMiddleware::requireRoles(['staff']);
        Response::success((new JobFeedService())->listForStaff($user));
    }

    public function student(): void
    {
        $user = RBACMiddleware::requireStudent();
        Response::success((new JobFeedService())->listForStudent($user));
    }

    public function alumni(): void
    {
        $user = RBACMiddleware::requireAlumni();
        $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
        if (!$profile || ($profile['isWorking'] ?? false) === true) {
            Response::forbidden('This feed is available to job-seeking alumni.');
        }
        Response::success((new JobFeedService())->listForSeekingAlumni($user));
    }
}
