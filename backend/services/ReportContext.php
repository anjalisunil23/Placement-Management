<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Options passed into report generation.
 */
final class ReportContext
{
    public function __construct(
        public ?string $departmentId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public string $format = 'pdf',
        public ?string $generatedBy = null,
        public int $month = 0,
        public int $year = 0,
    ) {
        if ($this->month <= 0) {
            $this->month = (int) date('n');
        }
        if ($this->year <= 0) {
            $this->year = (int) date('Y');
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input, ?string $forcedDepartmentId = null, ?string $userId = null): self
    {
        return new self(
            departmentId: $forcedDepartmentId ?: (isset($input['departmentId']) ? (string) $input['departmentId'] : null),
            dateFrom: isset($input['dateFrom']) ? (string) $input['dateFrom'] : null,
            dateTo: isset($input['dateTo']) ? (string) $input['dateTo'] : null,
            format: in_array($input['format'] ?? 'pdf', ['pdf', 'csv'], true) ? (string) $input['format'] : 'pdf',
            generatedBy: $userId,
            month: (int) ($input['month'] ?? date('n')),
            year: (int) ($input['year'] ?? date('Y')),
        );
    }
}
