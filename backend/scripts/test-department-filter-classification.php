<?php

declare(strict_types=1);

require dirname(__DIR__) . '/models/DepartmentModel.php';

use PMS\Models\DepartmentModel;

$checks = [
    ['', 'Computer Science and Engineering', true],
    ['12345', 'Computer Science and Engineering', true],
    ['STAFF', 'Staff', false],
    ['', '', false],
];

$ok = true;
foreach ($checks as [$code, $name, $want]) {
    $got = DepartmentModel::isStudentAcademicDepartment($code, $name);
    if ($got !== $want) {
        fwrite(STDERR, "FAIL isStudentAcademicDepartment({$code}, {$name}) expected " . ($want ? 'true' : 'false') . "\n");
        $ok = false;
    }
}

$option = DepartmentModel::toPlacementFilterOption([
    '_id'  => 'abc1234567890123456789012',
    'name' => 'Computer Applications',
    'code' => '9876',
    'aesId' => '9876',
]);
if ($option === null || ($option['name'] ?? '') !== 'Computer Applications') {
    fwrite(STDERR, "FAIL toPlacementFilterOption numeric AES code\n");
    $ok = false;
}

echo $ok ? "OK\n" : "FAILED\n";
exit($ok ? 0 : 1);
