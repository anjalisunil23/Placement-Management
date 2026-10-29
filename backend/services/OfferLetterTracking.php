<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Shared offer-letter document states for placement records.
 * Distinct from placement status (placed / pending approval).
 */
final class OfferLetterTracking
{
    public const PENDING_UPLOAD = 'pending_upload';
    public const UPLOADED = 'uploaded';
    public const CONFIDENTIALITY_EXCEPTION = 'confidentiality_exception';
    public const AWAITING_VERIFICATION = 'awaiting_verification';
    public const VERIFIED = 'verified';

    public const EXCEPTION_COMPANY_CONFIDENTIALITY = 'company_confidentiality';

    public const LABELS = [
        self::PENDING_UPLOAD => 'Pending Upload',
        self::UPLOADED => 'Uploaded',
        self::CONFIDENTIALITY_EXCEPTION => 'Confidentiality Exception Recorded',
        self::AWAITING_VERIFICATION => 'Awaiting Verification',
        self::VERIFIED => 'Verified',
    ];

    /**
     * @param array<string, mixed> $doc selfPlacement, placement, or registry entry
     */
    public static function hasOfferLetterPath(array $doc): bool
    {
        foreach (['offerLetter', 'offer_letter'] as $key) {
            if (trim((string) ($doc[$key] ?? '')) !== '') {
                return true;
            }
        }

        return !empty($doc['hasOfferLetter']);
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function isVerified(array $doc): bool
    {
        return filter_var($doc['offerLetterVerified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function exceptionReason(array $doc): string
    {
        $reason = trim((string) (
            $doc['offerLetterException']
            ?? $doc['offer_letter_exception']
            ?? ''
        ));
        if ($reason === self::EXCEPTION_COMPANY_CONFIDENTIALITY || $reason === 'company_policy') {
            return self::EXCEPTION_COMPANY_CONFIDENTIALITY;
        }

        return $reason;
    }

    /**
     * @param array<string, mixed> $doc
     */
    public static function status(array $doc): string
    {
        if (self::isVerified($doc) && self::hasOfferLetterPath($doc)) {
            return self::VERIFIED;
        }
        if (self::hasOfferLetterPath($doc)) {
            // Registry verification flag exists; treat uploaded-but-not-verified as awaiting check.
            if (array_key_exists('offerLetterVerified', $doc) || array_key_exists('verificationDate', $doc)) {
                return self::isVerified($doc) ? self::VERIFIED : self::AWAITING_VERIFICATION;
            }

            return self::UPLOADED;
        }
        if (self::exceptionReason($doc) === self::EXCEPTION_COMPANY_CONFIDENTIALITY) {
            return self::CONFIDENTIALITY_EXCEPTION;
        }

        return self::PENDING_UPLOAD;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * @return list<string>
     */
    public static function followUpStatuses(): array
    {
        return [self::PENDING_UPLOAD, self::CONFIDENTIALITY_EXCEPTION];
    }

    public static function needsFollowUp(string $status): bool
    {
        return in_array($status, self::followUpStatuses(), true);
    }

    /**
     * Read confidentiality fields from request input / POST.
     *
     * @param array<string, mixed> $input
     * @return array{exception: string, note: string}
     */
    public static function parseExceptionInput(array $input): array
    {
        $raw = strtolower(trim((string) (
            $input['offerLetterException']
            ?? $input['offer_letter_exception']
            ?? ''
        )));
        $flag = filter_var(
            $input['offerLetterConfidentiality'] ?? $input['confidentialityException'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $exception = '';
        if ($flag || $raw === self::EXCEPTION_COMPANY_CONFIDENTIALITY || $raw === 'company_policy' || $raw === '1' || $raw === 'true') {
            $exception = self::EXCEPTION_COMPANY_CONFIDENTIALITY;
        }
        $note = trim((string) (
            $input['offerLetterExceptionNote']
            ?? $input['offer_letter_exception_note']
            ?? $input['confidentialityNote']
            ?? ''
        ));
        if (strlen($note) > 500) {
            $note = substr($note, 0, 500);
        }

        return ['exception' => $exception, 'note' => $note];
    }

    /**
     * Merge offer-letter tracking fields onto a placement/selfPlacement document.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function applyUploadState(array $doc, ?string $offerLetterPath, array $exceptionInput = []): array
    {
        $path = trim((string) ($offerLetterPath ?? $doc['offerLetter'] ?? ''));
        if ($path !== '') {
            $doc['offerLetter'] = $path;
            unset($doc['offerLetterException'], $doc['offerLetterExceptionNote']);
            if (!array_key_exists('offerLetterVerified', $doc)) {
                $doc['offerLetterVerified'] = false;
            }

            return $doc;
        }

        $parsed = self::parseExceptionInput($exceptionInput !== [] ? $exceptionInput : $doc);
        if ($parsed['exception'] !== '') {
            $doc['offerLetterException'] = $parsed['exception'];
            if ($parsed['note'] !== '') {
                $doc['offerLetterExceptionNote'] = $parsed['note'];
            }
        } else {
            unset($doc['offerLetterException'], $doc['offerLetterExceptionNote']);
        }

        return $doc;
    }

    /**
     * Public summary for APIs (never includes file paths).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function summarize(array $doc): array
    {
        $status = self::status($doc);

        return [
            'offerLetterStatus' => $status,
            'offerLetterStatusLabel' => self::label($status),
            'hasOfferLetter' => self::hasOfferLetterPath($doc),
            'offerLetterVerified' => self::isVerified($doc),
            'offerLetterException' => self::exceptionReason($doc),
            'offerLetterExceptionNote' => trim((string) (
                $doc['offerLetterExceptionNote']
                ?? $doc['offer_letter_exception_note']
                ?? ''
            )),
            'offerLetterFollowUpAt' => trim((string) (
                $doc['offerLetterFollowUpAt']
                ?? $doc['offer_letter_followup_at']
                ?? ''
            )),
            'offerLetterFollowUpNote' => trim((string) (
                $doc['offerLetterFollowUpNote']
                ?? $doc['offer_letter_followup_note']
                ?? ''
            )),
            'needsOfferLetterFollowUp' => self::needsFollowUp($status),
        ];
    }
}
