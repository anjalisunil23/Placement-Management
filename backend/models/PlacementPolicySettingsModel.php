<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Services\ObjectStorageService;
use PMS\Utils\DocumentHelper;

/**
 * Admin-managed placement & internship policy PDFs (student registration gate).
 */
class PlacementPolicySettingsModel extends BaseModel
{
    private const DOC_KEY = 'placement_policies';

    private const DEFAULT_PLACEMENT_VERSION = 'ajce-placement-2026-09';

    private const DEFAULT_INTERNSHIP_VERSION = 'ajce-internship-2026-09';

    private const FALLBACK_PLACEMENT_PDF = 'assets/policies/amal-jyothi-placement-policy-2026.pdf';

    private const FALLBACK_INTERNSHIP_PDF = 'assets/policies/ajce-student-internship-policy-2026.pdf';

    protected function collectionName(): string
    {
        return Collections::SYSTEM_SETTINGS;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $doc = $this->findOne(['key' => self::DOC_KEY]);

        return $this->normalize(is_array($doc) ? $doc : []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $allowed = [
            'placementPolicyVersion',
            'placementPolicyTitle',
            'placementPolicyStorageUri',
            'internshipPolicyVersion',
            'internshipPolicyTitle',
            'internshipPolicyStorageUri',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['key'] = self::DOC_KEY;
        $update['updatedAt'] = DocumentHelper::now();

        $this->upsert(
            ['key' => self::DOC_KEY],
            $update,
            ['createdAt' => DocumentHelper::now()]
        );

        return $this->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function recordUpload(string $type, string $storageUri, ?string $version = null): array
    {
        $type = strtolower(trim($type));
        if ($type !== 'placement' && $type !== 'internship') {
            throw new \InvalidArgumentException('Invalid policy type.');
        }

        $current = $this->get();
        $patch = [];
        if ($type === 'placement') {
            $patch['placementPolicyStorageUri'] = $storageUri;
            $patch['placementPolicyVersion'] = $version !== null && trim($version) !== ''
                ? trim($version)
                : ('placement-' . gmdate('Y-m-d-His'));
        } else {
            $patch['internshipPolicyStorageUri'] = $storageUri;
            $patch['internshipPolicyVersion'] = $version !== null && trim($version) !== ''
                ? trim($version)
                : ('internship-' . gmdate('Y-m-d-His'));
        }

        return $this->save(array_merge($current, $patch));
    }

    /**
     * Public config for registration UI (no secrets).
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $cfg = $this->get();
        $storage = new ObjectStorageService();

        return [
            'placement' => [
                'version' => (string) ($cfg['placementPolicyVersion'] ?? self::DEFAULT_PLACEMENT_VERSION),
                'title'   => (string) ($cfg['placementPolicyTitle'] ?? 'Placement Policy'),
                'pdfUrl'  => $this->resolvePdfUrl(
                    (string) ($cfg['placementPolicyStorageUri'] ?? ''),
                    self::FALLBACK_PLACEMENT_PDF,
                    $storage
                ),
            ],
            'internship' => [
                'version' => (string) ($cfg['internshipPolicyVersion'] ?? self::DEFAULT_INTERNSHIP_VERSION),
                'title'   => (string) ($cfg['internshipPolicyTitle'] ?? 'Internship Policy'),
                'pdfUrl'  => $this->resolvePdfUrl(
                    (string) ($cfg['internshipPolicyStorageUri'] ?? ''),
                    self::FALLBACK_INTERNSHIP_PDF,
                    $storage
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $profile Student document
     * @return array<string, mixed>
     */
    public function registrationState(array $profile): array
    {
        $cfg = $this->get();
        $currentPlacement = (string) ($cfg['placementPolicyVersion'] ?? self::DEFAULT_PLACEMENT_VERSION);
        $currentInternship = (string) ($cfg['internshipPolicyVersion'] ?? self::DEFAULT_INTERNSHIP_VERSION);

        $placementOk = !empty($profile['placementPolicyAccepted'])
            && (string) ($profile['placementPolicyVersion'] ?? '') === $currentPlacement;
        $internshipOk = !empty($profile['internshipPolicyAccepted'])
            && (string) ($profile['internshipPolicyVersion'] ?? '') === $currentInternship
            && !empty($profile['policyAccepted']);

        return [
            'currentPlacementPolicyVersion'  => $currentPlacement,
            'currentInternshipPolicyVersion' => $currentInternship,
            'placementPolicyAccepted'        => $placementOk,
            'internshipPolicyAccepted'       => $internshipOk,
            'policyAccepted'                 => $internshipOk,
            'policyRegistrationRequired'     => !$internshipOk,
        ];
    }

    public function currentPlacementVersion(): string
    {
        return (string) ($this->get()['placementPolicyVersion'] ?? self::DEFAULT_PLACEMENT_VERSION);
    }

    public function currentInternshipVersion(): string
    {
        return (string) ($this->get()['internshipPolicyVersion'] ?? self::DEFAULT_INTERNSHIP_VERSION);
    }

    private function resolvePdfUrl(string $storageUri, string $fallbackAsset, ObjectStorageService $storage): string
    {
        $storageUri = trim($storageUri);
        if ($storageUri === '') {
            return $fallbackAsset;
        }

        $filename = $storage->storedNameFromUri($storageUri);
        if ($filename === '') {
            return $fallbackAsset;
        }

        return $storage->mediaUrl(ObjectStorageService::FOLDER_POLICIES, $filename);
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function normalize(array $doc): array
    {
        $defaults = [
            'placementPolicyVersion'      => self::DEFAULT_PLACEMENT_VERSION,
            'placementPolicyTitle'        => 'Placement Policy',
            'placementPolicyStorageUri'   => '',
            'internshipPolicyVersion'     => self::DEFAULT_INTERNSHIP_VERSION,
            'internshipPolicyTitle'       => 'Internship Policy',
            'internshipPolicyStorageUri'  => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $doc) || (is_string($doc[$key]) && trim((string) $doc[$key]) === '' && $key !== 'placementPolicyStorageUri' && $key !== 'internshipPolicyStorageUri')) {
                $doc[$key] = $value;
            }
        }

        unset($doc['_id'], $doc['key'], $doc['createdAt'], $doc['updatedAt']);

        return $doc;
    }
}
