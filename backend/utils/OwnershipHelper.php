<?php

declare(strict_types=1);

namespace PMS\Utils;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;

/**
 * Ownership checks for multi-tenant API actions.
 */
final class OwnershipHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function requireCompanyApplication(string $appId, array $user): array
    {
        $company = (new CompanyModel())->findByUserId((string) $user['_id']);
        if (!$company) {
            Response::notFound('Company profile not found.');
        }

        $app = (new ApplicationModel())->findById($appId);
        if (!$app) {
            Response::notFound('Application not found.');
        }

        if ((string) ($app['companyId'] ?? '') !== (string) $company['_id']) {
            Response::forbidden('This application does not belong to your company.');
        }

        return $app;
    }
}
