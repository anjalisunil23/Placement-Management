<?php

declare(strict_types=1);

/**
 * Unit-style checks for offer-letter tracking helpers (no DB writes).
 * Run: php backend/scripts/test-offer-letter-tracking.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\AlumniEmploymentOfferIndex;
use PMS\Services\OfferLetterTracking;

$failed = 0;
$ok = static function (bool $cond, string $label) use (&$failed): void {
    if ($cond) {
        echo "OK  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n";
};

$ok(OfferLetterTracking::status([]) === OfferLetterTracking::PENDING_UPLOAD, 'empty doc is pending upload');
$ok(
    OfferLetterTracking::status(['offerLetter' => 'offer_letters/a.pdf']) === OfferLetterTracking::UPLOADED,
    'path without verified flag is uploaded'
);
$ok(
    OfferLetterTracking::status([
        'offerLetter' => 'offer_letters/a.pdf',
        'offerLetterVerified' => false,
    ]) === OfferLetterTracking::AWAITING_VERIFICATION,
    'path with verified=false is awaiting verification'
);
$ok(
    OfferLetterTracking::status([
        'offerLetter' => 'offer_letters/a.pdf',
        'offerLetterVerified' => true,
    ]) === OfferLetterTracking::VERIFIED,
    'verified path is verified'
);
$ok(
    OfferLetterTracking::status([
        'offerLetterException' => 'company_confidentiality',
    ]) === OfferLetterTracking::CONFIDENTIALITY_EXCEPTION,
    'confidentiality exception without path'
);
$ok(
    OfferLetterTracking::status([
        'offerLetter' => 'offer_letters/a.pdf',
        'offerLetterException' => 'company_confidentiality',
    ]) === OfferLetterTracking::UPLOADED
    || OfferLetterTracking::status([
        'offerLetter' => 'offer_letters/a.pdf',
        'offerLetterException' => 'company_confidentiality',
    ]) === OfferLetterTracking::AWAITING_VERIFICATION,
    'uploaded path wins over confidentiality exception'
);

$parsed = OfferLetterTracking::parseExceptionInput([
    'offerLetterConfidentiality' => '1',
    'offerLetterExceptionNote' => 'NDA',
]);
$ok($parsed['exception'] === OfferLetterTracking::EXCEPTION_COMPANY_CONFIDENTIALITY, 'parse confidentiality flag');
$ok($parsed['note'] === 'NDA', 'parse confidentiality note');

$applied = OfferLetterTracking::applyUploadState([], 'offer_letters/x.pdf', [
    'offerLetterConfidentiality' => '1',
]);
$ok(($applied['offerLetter'] ?? '') === 'offer_letters/x.pdf', 'upload clears exception path');
$ok(!isset($applied['offerLetterException']), 'exception removed after upload');

$pending = OfferLetterTracking::applyUploadState([], null, []);
$ok(OfferLetterTracking::status($pending) === OfferLetterTracking::PENDING_UPLOAD, 'no file stays pending');

$summary = OfferLetterTracking::summarize([
    'offerLetterException' => 'company_confidentiality',
    'offerLetterExceptionNote' => 'policy',
]);
$ok(($summary['needsOfferLetterFollowUp'] ?? false) === true, 'confidentiality needs follow-up');
$ok(!isset($summary['offerLetter']), 'summary does not invent offerLetter path');
$ok(OfferLetterTracking::displayLabel(OfferLetterTracking::PENDING_UPLOAD) === 'Not Uploaded', 'pending displays as Not Uploaded');
$ok(OfferLetterTracking::displayLabel(OfferLetterTracking::UPLOADED) === 'Uploaded', 'uploaded display label');
$ok(
    OfferLetterTracking::displayLabel(OfferLetterTracking::CONFIDENTIALITY_EXCEPTION) === 'Confidentiality Exception',
    'confidentiality display label'
);
$match = AlumniEmploymentOfferIndex::matchingEntryIndex([
    ['type' => 'Placement', 'employer' => 'Bitvion Technologies'],
    ['type' => 'Placement', 'employer' => 'Other Co'],
], 'BITVION TECHNOLOGIES');
$ok($match === 0, 'alumni letter attaches to the matching company');
$unmatched = AlumniEmploymentOfferIndex::matchingEntryIndex([
    ['type' => 'Placement', 'employer' => 'One'],
    ['type' => 'Placement', 'employer' => 'Two'],
], 'Somewhere Else');
$ok($unmatched === null, 'unmatched alumni letter is not copied onto another placement');
$only = AlumniEmploymentOfferIndex::matchingEntryIndex([
    ['type' => 'Higher Education', 'employer' => 'University'],
    ['type' => 'Placement', 'employer' => 'Only Job'],
], '');
$ok($only === 1, 'single placement entry receives the alumni letter');
$ok(OfferLetterTracking::needsFollowUp(OfferLetterTracking::PENDING_UPLOAD), 'pending needs follow-up');
$ok(!OfferLetterTracking::needsFollowUp(OfferLetterTracking::VERIFIED), 'verified does not need follow-up');

echo $failed === 0 ? "\nAll offer-letter tracking checks passed.\n" : "\n{$failed} check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
