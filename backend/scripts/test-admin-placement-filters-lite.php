<?php

declare(strict_types=1);

/**
 * Lightweight filter test (avoids full service bootstrap).
 *
 *   php backend/scripts/test-admin-placement-filters-lite.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'PMS\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

use PMS\Models\DepartmentModel;
use PMS\Services\StaffPlacementRegistryService;

$registry = new StaffPlacementRegistryService();
$departments = $registry->departmentFilterOptions();
$count = count($departments);

echo "=== Lite admin placement filter test ===\n";
echo "departmentFilterOptions: {$count}\n";

$public = 0;
$model = new DepartmentModel();
foreach ($model->findAll([], 500) as $dept) {
    $name = trim((string) ($dept['name'] ?? ''));
    $code = strtoupper(trim((string) ($dept['code'] ?? '')));
    if ($name !== '' && DepartmentModel::isStudentAcademicDepartment($code, $name)) {
        $public++;
    }
}
echo "local academic departments (model): {$public}\n";

if ($count < 2 && $public >= 2) {
    fwrite(STDERR, "FAIL: filter options under-count vs local academic departments.\n");
    exit(1);
}
if ($count < 2 && $public < 2) {
    fwrite(STDERR, "WARN: fewer than 2 academic departments in DB — sync AES departments first.\n");
    exit(0);
}

echo "OK\n";
exit(0);
