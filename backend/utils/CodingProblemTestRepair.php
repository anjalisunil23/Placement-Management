<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Repair known bank problems whose hidden test I/O was saved incorrectly (e.g. AI import).
 */
final class CodingProblemTestRepair
{
    /** @return list<array<string, mixed>> */
    public static function arrayPartitionTestCases(): array
    {
        return [
            [
                'id' => 's1',
                'label' => 'Sample Test Case',
                'sample' => true,
                'input' => "4\n1 4 3 2",
                'expected' => '4',
            ],
            [
                'id' => 'h1',
                'label' => 'Hidden Test Case 1',
                'sample' => false,
                'input' => "2\n1 2 3 4",
                'expected' => '4',
            ],
            [
                'id' => 'h2',
                'label' => 'Hidden Test Case 2',
                'sample' => false,
                'input' => "3\n-5 -2 0 4 7 9",
                'expected' => '2',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $problem normalized bank problem
     * @return array<string, mixed>
     */
    public static function apply(array $problem): array
    {
        $title = trim((string) ($problem['title'] ?? ''));
        if ($title === '' || stripos($title, 'Array Partition') === false) {
            return $problem;
        }
        $problem['testCases'] = self::arrayPartitionTestCases();

        return $problem;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function testCasesChanged(array $before, array $after): bool
    {
        return json_encode($before['testCases'] ?? []) !== json_encode($after['testCases'] ?? []);
    }
}
