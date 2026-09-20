<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Models\StudentVolunteerModel;
use PMS\Models\UserModel;
use PMS\Utils\Response;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Generates the official Placement Campus Ambassador (PCA) appointment offer letter PDF
 * by overlaying student details on the college OFFICIAL OFFER LETTER template.
 */
final class PlacementRepresentativeOfferLetterService
{
    /** PDF points → mm (TCPDF default unit). */
    private const PT_TO_MM = 0.352778;

    /**
     * @param array<string, mixed> $ctx PlacementOfficerContext
     */
    public function streamForAssignment(array $ctx, string $assignmentId): void
    {
        $payload = $this->resolveAssignmentPayload($ctx, $assignmentId);
        $pdf = $this->buildPdf($payload);
        $filename = $this->safeFilename($payload);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        $pdf->Output($filename, 'I');
        exit;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function resolveAssignmentPayload(array $ctx, string $assignmentId): array
    {
        $volunteerModel = new StudentVolunteerModel();
        $row = $volunteerModel->findById($assignmentId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            Response::notFound('Placement representative assignment not found.');
        }

        if (empty($ctx['isAdmin'])) {
            if (empty($ctx['departmentId'])) {
                Response::forbidden('Your placement officer profile has no department assigned.');
            }
            if ((string) ($row['departmentId'] ?? '') !== (string) $ctx['departmentId']) {
                Response::forbidden('This placement representative assignment is outside your department.');
            }
        }

        $studentModel = new StudentModel();
        $studentId = (string) ($row['studentId'] ?? '');
        $student = $studentModel->findById($studentId);
        if ($student === null) {
            Response::notFound('Student not found for this assignment.');
        }

        $userModel = new UserModel();
        $userId = (string) ($row['userId'] ?? ($student['userId'] ?? ''));
        $user = $userId !== '' ? $userModel->findById($userId) : null;

        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) ($user['name'] ?? $personal['name'] ?? $personal['fullName'] ?? ''));
        if ($name === '') {
            Response::error('Student name is missing. Update the student profile before generating the offer letter.', 422);
        }

        $classBatch = trim((string) ($student['classBatch'] ?? ''));
        if ($classBatch === '') {
            $parts = array_filter([
                (string) ($personal['course'] ?? ''),
                (string) ($personal['year'] ?? ''),
                (string) ($personal['semester'] ?? ''),
            ], static fn ($v) => trim($v) !== '');
            $classBatch = implode(' ', $parts);
        }

        $assignedAt = $row['assignedAt'] ?? $row['createdAt'] ?? null;
        $assignmentDate = $this->resolveAssignmentDate($assignedAt);
        if ($assignmentDate === null) {
            Response::error('Assignment date is missing. Re-assign the placement representative to generate the offer letter.', 422);
        }

        return [
            'name'           => $name,
            'salutation'     => $this->salutation($personal),
            'classBatch'     => $classBatch !== '' ? $classBatch : '',
            'letterDate'     => $this->formatLetterDate($assignmentDate),
            'academicYear'   => $this->academicYearJulyToJune($assignmentDate),
            'registerNumber' => (string) ($student['registerNumber'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildPdf(array $payload): Fpdi
    {
        $template = $this->templatePath();
        if ($template === '') {
            Response::error('Offer letter template is missing on the server.', 500);
        }

        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetAuthor('Training & Placement Cell, AJCE');
        $pdf->SetTitle('Official Offer Letter — ' . ($payload['name'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->setSourceFile($template);
        $tpl = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);

        $this->overlayDynamicFields($pdf, $payload);

        return $pdf;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function overlayDynamicFields(Fpdi $pdf, array $payload): void
    {
        $dateText = (string) ($payload['letterDate'] ?? '');
        $nameText = trim((string) (($payload['salutation'] ?? 'Mr./Ms.') . ' ' . ($payload['name'] ?? '')));
        $classText = (string) ($payload['classBatch'] ?? '');
        $yearText = (string) ($payload['academicYear'] ?? '');

        // Cover placeholder/sample values from the official template (coordinates in PDF points).
        $this->coverRect($pdf, 82.0, 224.8, 62.0, 13.5);
        $this->coverRect($pdf, 58.0, 251.0, 285.0, 13.5);
        $this->coverRect($pdf, 58.0, 265.5, 285.0, 13.5);
        $this->coverRect($pdf, 128.5, 150.2, 56.0, 13.5);
        $this->coverRect($pdf, 349.0, 358.7, 56.0, 13.5);
        $this->coverRect($pdf, 184.5, 444.5, 56.0, 13.5);

        $this->writeText($pdf, 82.0, 225.8, $dateText);
        $this->writeText($pdf, 58.5, 252.5, $nameText, 'B');
        $this->writeText($pdf, 58.5, 266.8, 'Class: ' . $classText);
        $this->writeText($pdf, 129.0, 151.3, $yearText);
        $this->writeText($pdf, 349.5, 359.8, $yearText);
        $this->writeText($pdf, 185.0, 445.6, $yearText);

        $signature = $this->principalSignaturePath();
        if ($signature !== '') {
            try {
                $ext = str_ends_with(strtolower($signature), '.png') ? 'PNG' : 'JPG';
                $pdf->Image(
                    $signature,
                    $this->mm(58.0),
                    $this->mm(648.0),
                    $this->mm(120.0),
                    0,
                    $ext,
                    '',
                    '',
                    false,
                    300,
                    '',
                    false,
                    false,
                    0,
                    false,
                    false,
                    false
                );
            } catch (\Throwable) {
                // Signature is optional when GD/Imagick cannot load PNG alpha.
            }
        }
    }

    private function coverRect(Fpdi $pdf, float $xPt, float $yPt, float $wPt, float $hPt): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($this->mm($xPt), $this->mm($yPt), $this->mm($wPt), $this->mm($hPt), 'F');
    }

    private function writeText(Fpdi $pdf, float $xPt, float $yPt, string $text, string $style = ''): void
    {
        if ($text === '') {
            return;
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('times', $style, 11);
        $pdf->SetXY($this->mm($xPt), $this->mm($yPt));
        $pdf->Write(0, $text);
    }

    private function mm(float $points): float
    {
        return $points * self::PT_TO_MM;
    }

    private function assetRoot(): string
    {
        $root = dirname(__DIR__, 2);
        $resolved = realpath($root);

        return $resolved !== false ? $resolved : $root;
    }

    private function backendRoot(): string
    {
        $root = dirname(__DIR__);
        $resolved = realpath($root);

        return $resolved !== false ? $resolved : $root;
    }

    private function templatePath(): string
    {
        $candidates = [
            $this->backendRoot() . '/resources/templates/pca-offer-letter-template.pdf',
            $this->assetRoot() . '/assets/templates/pca-offer-letter-template.pdf',
            $this->assetRoot() . '/assets/templates/OFFICIAL-OFFER-LETTER.pdf',
            $this->assetRoot() . '/backend/resources/templates/pca-offer-letter-template.pdf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    private function principalSignaturePath(): string
    {
        foreach ([
            $this->assetRoot() . '/css/img/principal-signature.jpg',
            $this->assetRoot() . '/css/img/principal-signature.png',
            $this->assetRoot() . '/css/principal-signature.png',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $personal
     */
    private function salutation(array $personal): string
    {
        $gender = strtolower(trim((string) ($personal['gender'] ?? '')));
        if ($gender === '') {
            return 'Mr./Ms.';
        }
        if (in_array($gender, ['f', 'female', 'woman', 'girl', 'w'], true) || str_starts_with($gender, 'f')) {
            return 'Ms.';
        }
        if (in_array($gender, ['m', 'male', 'man', 'boy'], true) || str_starts_with($gender, 'm')) {
            return 'Mr.';
        }

        return 'Mr./Ms.';
    }

    private function letterTimezone(): \DateTimeZone
    {
        return new \DateTimeZone('Asia/Kolkata');
    }

    private function resolveAssignmentDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone($this->letterTimezone());
        }
        if (is_numeric($value)) {
            $dt = \DateTimeImmutable::createFromFormat('U', (string) (int) $value);
            return $dt ? $dt->setTimezone($this->letterTimezone()) : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        if (str_contains($raw, '.')) {
            $raw = explode('.', $raw, 2)[0];
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d', \DateTimeInterface::RFC3339] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->setTimezone($this->letterTimezone());
            }
        }

        $parsed = strtotime($raw);
        if ($parsed !== false) {
            return (new \DateTimeImmutable('@' . $parsed))->setTimezone($this->letterTimezone());
        }

        return null;
    }

    private function formatLetterDate(\DateTimeImmutable $assignmentDate): string
    {
        return $assignmentDate->format('j F Y');
    }

    /** Academic year runs July → June (e.g. assignment on 23 July 2026 → 2026–2027). */
    private function academicYearJulyToJune(\DateTimeImmutable $assignmentDate): string
    {
        $year = (int) $assignmentDate->format('Y');
        $month = (int) $assignmentDate->format('n');

        return $month >= 7
            ? $year . '–' . ($year + 1)
            : ($year - 1) . '–' . $year;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function safeFilename(array $payload): string
    {
        $reg = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) ($payload['registerNumber'] ?? '')) ?: 'student';

        return str_replace(['"', "\r", "\n"], '', 'Official-Offer-Letter-' . $reg . '.pdf');
    }
}
