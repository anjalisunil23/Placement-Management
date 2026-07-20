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
        public string $format = 'xlsx',
        public ?string $generatedBy = null,
        public int $month = 0,
        public int $year = 0,
        public ?string $companyId = null,
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
        $companyId = isset($input['companyId']) ? trim((string) $input['companyId']) : '';
        $formatRaw = strtolower(trim((string) ($input['format'] ?? 'xlsx')));
        // Reports are Excel-only.
        $format = in_array($formatRaw, ['xlsx', 'excel', 'xls'], true) ? 'xlsx' : 'xlsx';
        return new self(
            departmentId: $forcedDepartmentId ?: (isset($input['departmentId']) ? (string) $input['departmentId'] : null),
            dateFrom: isset($input['dateFrom']) ? (string) $input['dateFrom'] : null,
            dateTo: isset($input['dateTo']) ? (string) $input['dateTo'] : null,
            format: $format,
            generatedBy: $userId,
            month: (int) ($input['month'] ?? date('n')),
            year: (int) ($input['year'] ?? date('Y')),
            companyId: $companyId !== '' ? $companyId : null,
        );
    }
}
