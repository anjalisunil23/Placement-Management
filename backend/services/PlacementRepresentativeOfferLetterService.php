<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Models\StudentVolunteerModel;
use PMS\Models\UserModel;
use PMS\Utils\Response;
use TCPDF;

/**
 * Generates the official Placement Campus Ambassador (PCA) appointment offer letter PDF.
 * Layout matches the college OFFICIAL OFFER LETTER template (letterhead + footer artwork).
 */
final class PlacementRepresentativeOfferLetterService
{
    private const HEADER_X_MM = 10.6;
    private const HEADER_Y_MM = 9.2;
    private const HEADER_W_MM = 188.0;
    private const HEADER_H_MM = 28.7;
    private const FOOTER_Y_MM = 251.4;
    private const FOOTER_W_MM = 190.2;
    private const FOOTER_H_MM = 36.3;
    private const CONTENT_TOP_MM = 40.0;
    private const CONTENT_BOTTOM_MM = 48.0;
    private const MARGIN_LEFT_MM = 20.6;
    private const MARGIN_RIGHT_MM = 15.0;

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
            'classBatch'     => $classBatch !== '' ? $classBatch : '—',
            'letterDate'     => $this->formatLetterDate($assignmentDate),
            'academicYear'   => $this->academicYearJulyToJune($assignmentDate),
            'registerNumber' => (string) ($student['registerNumber'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildPdf(array $payload): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PlaceHub PMS');
        $pdf->SetAuthor('Training & Placement Cell, AJCE');
        $pdf->SetTitle('Official Offer Letter — ' . ($payload['name'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN_LEFT_MM, self::CONTENT_TOP_MM, self::MARGIN_RIGHT_MM);
        $pdf->SetAutoPageBreak(true, self::CONTENT_BOTTOM_MM);
        $pdf->AddPage();

        $letterhead = $this->letterheadPath();
        if ($letterhead !== '') {
            $pdf->Image(
                $letterhead,
                self::HEADER_X_MM,
                self::HEADER_Y_MM,
                self::HEADER_W_MM,
                self::HEADER_H_MM,
                'PNG',
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
        }

        $footer = $this->footerPath();
        if ($footer !== '') {
            $pdf->Image(
                $footer,
                self::HEADER_X_MM,
                self::FOOTER_Y_MM,
                self::FOOTER_W_MM,
                self::FOOTER_H_MM,
                'PNG',
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
        }

        $pdf->SetY(self::CONTENT_TOP_MM);
        $pdf->writeHTML($this->buildHtml($payload), true, false, true, false, '');

        return $pdf;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildHtml(array $payload): string
    {
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $name = $esc($payload['name'] ?? '');
        $salutation = $esc($payload['salutation'] ?? 'Mr./Ms.');
        $classBatch = $esc($payload['classBatch'] ?? '—');
        $letterDate = $esc($payload['letterDate'] ?? '');
        $academicYear = $esc($payload['academicYear'] ?? '');

        $signaturePath = $this->principalSignaturePath();
        $signatureHtml = is_file($signaturePath)
            ? '<img src="' . $esc($signaturePath) . '" width="105" height="40" />'
            : '';

        $base = 'font-family:times,serif;font-size:11pt;line-height:1.45;color:#000;text-align:left;';

        return <<<HTML
<style>
  p { margin: 0 0 6px 0; {$base} }
  .title { font-size:12pt; font-weight:bold; margin-bottom:8px; }
  .rule { border:none;border-top:1.5px solid #9f9f9f;height:0;margin:10px 0 12px 0; }
  .field-line { border-bottom:1px solid #000; display:inline-block; min-width:280px; padding-bottom:1px; }
  .sig { margin-top:18px; }
</style>

<p class="title">OFFICIAL OFFER LETTER OF APPOINTMENT</p>
<p>Placement Campus Ambassador (PCA)</p>
<p>Academic Year {$academicYear}</p>
<p>&nbsp;</p>
<p><strong>Training &amp; Placement Cell</strong></p>
<p><strong>Amal Jyothi College of Engineering (Autonomous)</strong></p>
<p>&nbsp;</p>
<p>Date: {$letterDate}</p>
<p><strong>{$salutation}</strong> <span class="field-line">{$name}</span></p>
<p>Class: <span class="field-line">{$classBatch}</span></p>
<p>&nbsp;</p>

<hr class="rule" />

<p><strong>Congratulations!</strong></p>
<p><strong>Welcome aboard,</strong></p>
<p>&nbsp;</p>
<p>
  It gives us immense pleasure to inform you that you have been <strong>appointed as the <em>Placement Campus Ambassador (PCA)</em></strong>
  representing your respective class for the <strong>Academic Year {$academicYear}</strong> under the
  <strong>Training &amp; Placement Cell, Amal Jyothi College of Engineering (Autonomous).</strong>
</p>
<p>&nbsp;</p>
<p><strong>Your Appointment Begins Now:</strong></p>
<p>&nbsp;</p>
<p>
  Your appointment shall commence with immediate effect from the receipt of this Offer Letter, and will remain
  valid for the Academic Year {$academicYear}, unless modified or withdrawn by the Training &amp; Placement Cell.
</p>
<p>&nbsp;</p>
<p>
  From this moment onward, you officially become a member of the <strong>Placement Campus Ambassadors Team.</strong>
</p>
<p><strong>Welcome to the Team:</strong></p>
<p>
  This appointment reflects the confidence we have in your abilities. We are excited to have you represent your
  class and department and contribute towards creating a vibrant, efficient, and successful placement ecosystem
  within the institution.
</p>
<p>&nbsp;</p>
<p>
  We look forward to welcoming you to the Training &amp; Placement Cell, Amal Jyothi College of Engineering
  (Autonomous), and are confident that you will make a significant contribution to the success of our team.
</p>
<p>&nbsp;</p>
<p>
  We wish you a rewarding and successful tenure as a <strong>Placement Campus Ambassador.</strong>
</p>
<p><strong>With Best Wishes,</strong></p>
<p><strong>Training &amp; Placement Cell, Amal Jyothi College of Engineering (Autonomous)</strong></p>
<p>&nbsp;</p>
<div class="sig">
  {$signatureHtml}
  <p>Signature of Principal</p>
</div>
HTML;
    }

    private function assetRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function letterheadPath(): string
    {
        foreach ([
            $this->assetRoot() . '/css/img/pca-letterhead.png',
            $this->assetRoot() . '/css/pca-letterhead.png',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    private function footerPath(): string
    {
        foreach ([
            $this->assetRoot() . '/css/img/pca-footer.png',
            $this->assetRoot() . '/css/pca-footer.png',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    private function principalSignaturePath(): string
    {
        foreach ([
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
