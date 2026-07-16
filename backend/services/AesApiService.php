<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;

/**
 * Client for AES institute data API (https://api.aesajce.in).
 */
final class AesApiService
{
    private string $apiUrl;
    private string $origin;
    private string $referer;
    private string $authKey;
    private string $refHost;
    private bool $sslVerify;

    /** @var list<array{code:string,name:string,short:string}>|null */
    private static ?array $departmentCache = null;

    /** @var array<string, array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}> */
    private static array $qualApiResponseCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $qualificationProfileCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $placementProfileCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $studInfoRecordCache = [];

    /** @var null|'query_admno'|'query_stud_admno'|'query_register'|'body'> */
    private static ?string $qualWinningTransport = null;

    public function __construct()
    {
        $aes = require dirname(__DIR__) . '/config/aes.php';
        $this->apiUrl = rtrim((string) ($aes['api_url'] ?? 'https://api.aesajce.in/'), '/') . '/';
        $this->origin = (string) ($aes['api_origin'] ?? 'https://www.aesajce.in');
        $this->referer = (string) ($aes['api_referer'] ?? 'https://www.aesajce.in/');
        $this->authKey = (string) ($aes['auth_key'] ?? '');
        $this->refHost = trim((string) ($aes['ref_host'] ?? ''));
        $sslVerify = $aes['ssl_verify'] ?? true;
        $this->sslVerify = is_bool($sslVerify) ? $sslVerify : filter_var((string) $sslVerify, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, scalar|null>
     */
    private function withAesAuthParams(array $params): array
    {
        $postData = $this->stringifyParams($params);
        if ($this->authKey !== '' && !isset($postData['authkey'])) {
            $postData['authkey'] = $this->authKey;
        }
        if ($this->refHost !== '' && $this->refHost !== 'localhost' && !isset($postData['refurl'])) {
            $postData['refurl'] = $this->refHost;
        }

        return $postData;
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    private function executeAesCurl(string $url, ?string $postBody, string $httpMethod = 'POST', array $extraHeaders = []): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'Could not initialize cURL.',
            ];
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: application/json, */*;q=0.1',
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: ' . $this->origin,
                'Referer: ' . $this->referer,
            ], $extraHeaders),
        ];

        if ($httpMethod === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            if ($postBody !== null) {
                $options[CURLOPT_POSTFIELDS] = $postBody;
            }
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'status'  => $statusCode ?: 0,
                'error'   => 'cURL error: ' . $curlErr,
            ];
        }

        $raw = (string) $response;
        if ($statusCode >= 400) {
            return [
                'success' => false,
                'status'  => $statusCode,
                'error'   => 'AES API HTTP ' . $statusCode,
                'raw'     => $raw,
            ];
        }

        if (trim($raw) === '') {
            return [
                'success' => false,
                'status'  => $statusCode,
                'raw'     => '',
                'error'   => 'AES API returned empty body',
            ];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => true,
                'status'  => $statusCode,
                'raw'     => $raw,
                'note'    => 'Upstream returned non-JSON payload',
            ];
        }

        return [
            'success' => true,
            'status'  => $statusCode,
            'data'    => $decoded,
        ];
    }

    /**
     * POST to https://api.aesajce.in/ with form-urlencoded body.
     *
     * @param array<string, scalar|null> $params
     * @param list<string> $extraHeaders
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function callAESApi(string $method, array $params = [], array $extraHeaders = []): array
    {
        $postData = array_merge(['method' => $method], $this->withAesAuthParams($params));

        return $this->executeAesCurl($this->apiUrl, http_build_query($postData), 'POST', $extraHeaders);
    }

    /**
     * AES ?method= routes often ignore the POST body — send params in the query string.
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    private function callAESApiWithMethodInQuery(string $method, array $params = [], bool $allowGetFallback = true): array
    {
        $queryData = array_merge(['method' => $method], $this->withAesAuthParams($params));
        $query = http_build_query($queryData);
        $url = $this->apiUrl . '?' . $query;
        $body = http_build_query($this->withAesAuthParams($params));

        $response = $this->executeAesCurl($url, $body, 'POST');
        if ($this->qualificationResponseHasRows($response) || !$allowGetFallback) {
            return $response;
        }

        return $this->executeAesCurl($url, null, 'GET');
    }

    /**
     * True when an AES qual response contains at least one education row or mark/CGPA field.
     *
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $response
     */
    private function qualificationResponseHasRows(array $response): bool
    {
        $raw = $this->extractQualificationRawRecord($response);
        if ($raw === []) {
            return false;
        }

        $edu = $raw['edu'] ?? null;
        if (is_array($edu) && $edu !== []) {
            return true;
        }

        foreach (['cgpa', 'marks10th', 'marks12th', 'sslc', 'hsc', 'totcgpa', 'curcgpa'] as $key) {
            if (isset($raw[$key]) && $raw[$key] !== '' && $raw[$key] !== null) {
                return true;
            }
        }

        return !empty($raw['qualifications']) && is_array($raw['qualifications']);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    private function callStudQual4PlacementTransport(string $transport, array $params): array
    {
        $admno = trim((string) ($params['admno'] ?? ''));
        return match ($transport) {
            'body' => $this->callAESApi('getStudQual4Placement', ['admno' => $admno]),
            'query_stud_admno' => $this->callAESApiWithMethodInQuery(
                'getStudQual4Placement',
                ['stud_admno' => $admno],
                false
            ),
            'query_register' => $this->callAESApiWithMethodInQuery(
                'getStudQual4Placement',
                $params,
                false
            ),
            default => $this->callAESApiWithMethodInQuery(
                'getStudQual4Placement',
                ['admno' => $admno],
                false
            ),
        };
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function getDepartments(array $params = []): array
    {
        return $this->callAESApi('getDepartments', $params);
    }

    /**
     * POST method=getStudInfo4Placement — student placement profile from AES.
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function getStudInfo4Placement(array $params = []): array
    {
        return $this->callAESApi('getStudInfo4Placement', $params);
    }

    /**
     * POST method=getAllStudInfo4Placement — department (or campus) student directory from AES.
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function getAllStudInfo4Placement(array $params = []): array
    {
        return $this->callPlacementFilterApi('getAllStudInfo4Placement', $params);
    }

    /**
     * Normalized student rows from getAllStudInfo4Placement.
     *
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAllStudInfo4Placement(array $params = []): array
    {
        $result = $this->getAllStudInfo4Placement($params);
        $records = $this->extractStudInfoRecords($result);
        if ($records === []) {
            return [];
        }

        $departments = $this->loadDepartmentsFromApi();
        $deptByAesId = [];
        foreach ($departments as $dept) {
            $aesId = trim((string) ($dept['aesId'] ?? ''));
            if ($aesId !== '' && preg_match('/^\d+$/', $aesId) === 1) {
                $deptByAesId[$aesId] = $dept;
            }
        }

        $out = [];
        $seen = [];
        foreach ($records as $record) {
            $normalized = $this->normalizePlacementStudentRecord($record);
            if ($normalized === []) {
                continue;
            }
            $deptAesId = trim((string) (
                $normalized['stud_deptcode']
                ?? $normalized['parentDepartmentCode']
                ?? ''
            ));
            if ($deptAesId !== '' && isset($deptByAesId[$deptAesId])) {
                $parent = $deptByAesId[$deptAesId];
                $normalized['parentDepartmentCode'] = $deptAesId;
                $normalized['parentDepartmentName'] = (string) ($parent['name'] ?? '');
                $normalized['parentDepartmentShort'] = (string) ($parent['short'] ?? $parent['code'] ?? '');
                // Prefer parent department name (getDepartments) over stud_branch.
                $parentName = trim((string) ($parent['name'] ?? ''));
                if ($parentName !== '') {
                    $normalized['departmentName'] = $parentName;
                    $normalized['deptName'] = $parentName;
                } elseif (empty($normalized['departmentName']) || $this->isCourseLevelShort((string) ($normalized['departmentName'] ?? ''))) {
                    $branchName = trim((string) ($normalized['stud_branch'] ?? $normalized['branch_name'] ?? ''));
                    if ($branchName !== '') {
                        $normalized['departmentName'] = $branchName;
                        $normalized['deptName'] = $branchName;
                    }
                }
                if ($this->isCourseLevelShort((string) ($normalized['department'] ?? $normalized['deptCode'] ?? ''))) {
                    $short = (string) ($parent['short'] ?? $parent['code'] ?? '');
                    if ($short !== '') {
                        $normalized['department'] = $short;
                        $normalized['deptCode'] = $short;
                        $normalized['department_code'] = $short;
                    }
                }
            }
            $key = strtoupper(trim((string) (
                $normalized['admno']
                ?? $normalized['stud_admno']
                ?? $normalized['registerNumber']
                ?? $normalized['registerno']
                ?? ''
            )));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /** Course-level AES shorts (B.Tech / M.Tech), not department codes. */
    public function isCourseLevelShort(string $code): bool
    {
        $needle = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');

        return in_array($needle, ['BT', 'MT', 'BTECH', 'MTECH'], true);
    }

    /** Human label for course-level shorts (BT → B.Tech). */
    public function courseLevelLabel(string $code): string
    {
        $needle = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');

        return match ($needle) {
            'BT', 'BTECH' => 'B.Tech',
            'MT', 'MTECH' => 'M.Tech',
            default => '',
        };
    }

    /**
     * POST method=getStudQual4Placement — student 10th/12th marks and CGPA from AES.
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function getStudQual4Placement(array $params = []): array
    {
        $admno = $this->resolveQualificationAdmissionNumber(
            $params,
            $this->resolveAdmissionNumber($params)
        );
        if ($admno === '' || !ctype_digit($admno)) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'Numeric admno is required for getStudQual4Placement.',
            ];
        }

        if (isset(self::$qualApiResponseCache[$admno])) {
            return self::$qualApiResponseCache[$admno];
        }

        $baseParams = ['admno' => $admno];
        $lastResponse = [
            'success' => false,
            'status'  => 0,
            'error'   => 'getStudQual4Placement returned no qualification data.',
        ];

        $registerNo = trim((string) ($params['registerno'] ?? $params['registerNumber'] ?? ''));
        if ($registerNo === '' || $registerNo === $admno) {
            $infoRecord = $this->cachedStudInfoRecord($admno);
            $registerNo = trim((string) ($infoRecord['registerno'] ?? $infoRecord['registerNumber'] ?? ''));
        }

        $transports = [];
        // Body transport is the reliable AES path for getStudQual4Placement.
        if (self::$qualWinningTransport !== null) {
            $transports[] = self::$qualWinningTransport;
        }
        foreach (['body', 'query_admno', 'query_stud_admno', 'query_register'] as $transport) {
            if (!in_array($transport, $transports, true)) {
                $transports[] = $transport;
            }
        }

        foreach ($transports as $transport) {
            $attemptParams = $baseParams;
            if ($transport === 'query_register' && $registerNo !== '' && $registerNo !== $admno) {
                $attemptParams['registerno'] = $registerNo;
                $attemptParams['registerNumber'] = $registerNo;
            }
            $response = $this->callStudQual4PlacementTransport($transport, $attemptParams);
            $lastResponse = $response;
            if ($this->qualificationResponseHasRows($response)) {
                self::$qualWinningTransport = $transport;
                self::$qualApiResponseCache[$admno] = $response;

                return $response;
            }
        }

        self::$qualApiResponseCache[$admno] = $lastResponse;

        return $lastResponse;
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedStudInfoRecord(string $admno): array
    {
        if (isset(self::$studInfoRecordCache[$admno])) {
            return self::$studInfoRecordCache[$admno];
        }

        $record = $this->extractRecord($this->getStudInfo4Placement(['admno' => $admno]));
        self::$studInfoRecordCache[$admno] = $record;

        return $record;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, scalar|null>
     */
    public function buildStudentRequestParams(array $params, string $registerNumber = ''): array
    {
        $register = $this->resolveAdmissionNumber($params, $registerNumber);

        $merged = array_merge([
            'admno'          => $register,
            'username'       => $register,
            'un'             => $register,
            'admission_no'   => $register,
            'registerNumber' => $register,
        ], $params);

        return $this->stringifyParams($merged);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function resolveAdmissionNumber(array $params, string $registerNumber = ''): string
    {
        if ($registerNumber !== '') {
            return trim($registerNumber);
        }

        return trim((string) (
            $params['admno']
            ?? $params['stud_admno']
            ?? $params['registerNumber']
            ?? $params['admission_no']
            ?? $params['un']
            ?? $params['username']
            ?? $params['studno']
            ?? ''
        ));
    }

    /**
     * Prefer numeric AES stud_admno for qualification lookups.
     *
     * @param array<string, mixed> $placement
     */
    public function resolveQualificationAdmissionNumber(array $placement, string $fallback = ''): string
    {
        foreach (['stud_admno', 'admno', 'registerNumber', 'admission_no'] as $key) {
            $value = trim((string) ($placement[$key] ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                return $value;
            }
        }

        $resolved = $this->resolveAdmissionNumber($placement, $fallback);

        return ctype_digit($resolved) ? $resolved : trim($fallback);
    }

    /**
     * POST getStudInfo4Placement with admno-first params (AES expects admno for numeric IDs).
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function postStudInfo4Placement(array $params, string $registerNumber = ''): array
    {
        $register = trim($registerNumber !== ''
            ? $registerNumber
            : (string) ($params['admno'] ?? $params['stud_admno'] ?? $params['registerNumber'] ?? $params['admission_no'] ?? $params['un'] ?? $params['username'] ?? ''));

        $numericAdmno = $this->resolveQualificationAdmissionNumber($params, $register);
        $attemptKeys = [];
        if ($numericAdmno !== '' && ctype_digit($numericAdmno)) {
            $attemptKeys[] = ['admno' => $numericAdmno];
            $attemptKeys[] = ['stud_admno' => $numericAdmno];
        }
        if ($register !== '') {
            $registerUpper = strtoupper($register);
            $attemptKeys[] = ['admno' => $registerUpper];
            $attemptKeys[] = ['admno' => $register];
            $attemptKeys[] = ['un' => $registerUpper];
            $attemptKeys[] = ['username' => $registerUpper];
            $attemptKeys[] = ['admission_no' => $registerUpper];
            $attemptKeys[] = ['registerNumber' => $registerUpper];
            $attemptKeys[] = ['studno' => $registerUpper];
        }

        foreach ($attemptKeys as $attempt) {
            $response = $this->getStudInfo4Placement($attempt);
            if ($this->extractRecord($response) !== []) {
                return $response;
            }
        }

        return $this->getStudInfo4Placement($this->buildStudentRequestParams($params, $register));
    }

    /**
     * Lightweight stud_course / stud_branch / stud_class for placement filters (no qual merge).
     *
     * @return array{stud_course:string,stud_branch:string,stud_class:string}
     */
    public function fetchStudInfoPlacementRow(string $register): array
    {
        $register = strtoupper(trim($register));
        if ($register === '') {
            return ['stud_course' => '', 'stud_branch' => '', 'stud_class' => ''];
        }

        foreach ([$register] as $cacheKey) {
            if (isset(self::$studInfoRecordCache[$cacheKey])) {
                return $this->pickStudInfoFilterFields(self::$studInfoRecordCache[$cacheKey]);
            }
        }

        $record = $this->normalizePlacementStudentRecord(
            $this->extractRecord($this->postStudInfo4Placement(['admno' => $register], $register))
        );
        if ($record !== []) {
            $infoAdmno = trim((string) ($record['stud_admno'] ?? $record['admno'] ?? ''));
            if ($infoAdmno !== '') {
                self::$studInfoRecordCache[$infoAdmno] = $record;
            }
            self::$studInfoRecordCache[$register] = $record;
        }

        return $this->pickStudInfoFilterFields($record);
    }

    /**
     * @param array<string, mixed> $record
     * @return array{stud_course:string,stud_branch:string,stud_class:string}
     */
    private function pickStudInfoFilterFields(array $record): array
    {
        return [
            'stud_course' => trim((string) ($record['stud_course'] ?? $record['stud_cource_short'] ?? '')),
            'stud_branch' => trim((string) ($record['stud_branch'] ?? $record['branch'] ?? '')),
            'stud_class'  => trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? '')),
        ];
    }

    /**
     * POST getStudQual4Placement — AES expects `admno` only.
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function postStudQual4Placement(array $params, string $registerNumber = ''): array
    {
        $admno = $this->resolveAdmissionNumber($params, $registerNumber);
        if ($admno === '') {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'admno is required for getStudQual4Placement.',
            ];
        }

        return $this->getStudQual4Placement(['admno' => $admno]);
    }

    /**
     * POST getDepartments and return parsed rows (cached).
     *
     * @param array<string, scalar|null> $params
     * @return list<array{code:string,name:string,short:string}>
     */
    public function loadDepartmentsFromApi(array $params = []): array
    {
        if ($params === [] && self::$departmentCache !== null) {
            return self::$departmentCache;
        }

        $rows = $this->normalizeDepartmentRows($this->getDepartments($params));
        if ($params === []) {
            self::$departmentCache = $rows;
        }

        return $rows;
    }

    /**
     * @return list<array{code:string,name:string,short:string}>
     */
    public function listDepartments(): array
    {
        return $this->loadDepartmentsFromApi();
    }

    /**
     * Resolve an AES department code or name to a canonical {code, name} pair.
     *
     * @return array{code:string,name:string}|null
     */
    public function findDepartment(string $codeOrName): ?array
    {
        $resolved = $this->matchDepartmentRow($this->loadDepartmentsFromApi(), $codeOrName);
        if ($resolved === null) {
            return null;
        }

        return ['code' => $resolved['code'], 'name' => $resolved['name']];
    }

    /**
     * @param list<array{code:string,name:string,short:string}> $departments
     * @return array{code:string,name:string,short:string}|null
     */
    private function matchDepartmentRow(array $departments, string $codeOrName): ?array
    {
        $needle = strtoupper(trim($codeOrName));
        if ($needle === '') {
            return null;
        }

        foreach ($departments as $row) {
            $code = strtoupper($row['code']);
            $name = strtoupper($row['name']);
            $short = strtoupper($row['short'] ?? '');
            $aesId = strtoupper(trim((string) ($row['aesId'] ?? '')));
            if ($code === $needle || $name === $needle || ($short !== '' && $short === $needle)
                || ($aesId !== '' && $aesId === $needle)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Programme branch from placement API (MCA, BCA, INMCA) vs parent department (Computer Applications).
     *
     * @param array<string, mixed> $record
     * @param list<array{code:string,name:string,short:string}> $departments
     * @return array{branch:string,parentCode:string,parentName:string}
     */
    private function extractPlacementBranch(array $record, array $departments = []): array
    {
        $branch = strtoupper(trim((string) (
            $record['stud_cource_short']
            ?? $record['stud_course']
            ?? $record['branch']
            ?? $record['programme']
            ?? $record['program']
            ?? ''
        )));
        $parentCode = strtoupper(trim((string) (
            $record['stud_deptcode']
            ?? $record['parentDepartmentCode']
            ?? $record['deptCode']
            ?? ''
        )));
        $parentName = '';
        if ($parentCode !== '' && $departments !== []) {
            $resolved = $this->matchDepartmentRow($departments, $parentCode);
            if ($resolved !== null) {
                $parentName = $resolved['name'];
            }
        }

        return [
            'branch'     => $branch,
            'parentCode' => $parentCode,
            'parentName' => $parentName,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{code:string,name:string}
     */
    private function placementBranchDepartment(array $record, array $departments = []): array
    {
        $info = $this->extractPlacementBranch($record, $departments);
        if ($info['branch'] !== '') {
            return ['code' => $info['branch'], 'name' => $info['branch']];
        }
        if ($info['parentCode'] !== '') {
            $resolved = $this->matchDepartmentRow($departments, $info['parentCode']);
            if ($resolved !== null) {
                return ['code' => $resolved['code'], 'name' => $resolved['name']];
            }

            return ['code' => $info['parentCode'], 'name' => $info['parentName']];
        }

        return ['code' => '', 'name' => ''];
    }

    /**
     * Fetch student department using AES POST APIs:
     * 1) getStudInfo4Placement — student record (deptCode / deptshort)
     * 2) getDepartments — master list to resolve department name
     *
     * @param array<string, scalar|null> $params
     * @return array{code:string,name:string}
     */
    public function resolveStudentDepartment(array $params, string $registerNumber = ''): array
    {
        $request = $this->buildStudentRequestParams($params, $registerNumber);

        // POST method=getStudInfo4Placement (admno-first)
        $placementPost = $this->postStudInfo4Placement($params, $registerNumber);
        $profile = $this->normalizePlacementStudentRecord($this->extractRecord($placementPost));

        // POST method=getDepartments
        $departments = $this->loadDepartmentsFromApi();

        if ($profile !== []) {
            return $this->placementBranchDepartment($profile, $departments);
        }

        return ['code' => '', 'name' => ''];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    /**
     * AES class / batch label from getStudInfo4Placement → stud_class.
     */
    public function studClassFromPlacementInfo(string $admnoOrRegister): string
    {
        $admnoOrRegister = trim($admnoOrRegister);
        if ($admnoOrRegister === '') {
            return '';
        }

        try {
            $profile = $this->fetchStudentPlacementProfile(['admno' => $admnoOrRegister]);
        } catch (\Throwable) {
            return '';
        }

        return trim((string) ($profile['stud_class'] ?? $profile['classBatch'] ?? ''));
    }

    public function fetchStudentPlacementProfile(array $params): array
    {
        $request = $this->buildStudentRequestParams($params);
        $cacheKey = $this->placementProfileCacheKey($params, (string) ($request['admno'] ?? ''));
        if ($cacheKey !== '' && isset(self::$placementProfileCache[$cacheKey])) {
            return self::$placementProfileCache[$cacheKey];
        }

        $record = $this->normalizePlacementStudentRecord(
            $this->extractRecord($this->postStudInfo4Placement($params, (string) ($request['admno'] ?? '')))
        );
        if ($record === []) {
            return [];
        }

        $infoAdmno = trim((string) ($record['stud_admno'] ?? $record['admno'] ?? ''));
        if ($infoAdmno !== '' && ctype_digit($infoAdmno)) {
            self::$studInfoRecordCache[$infoAdmno] = $record;
        }

        $departments = $this->loadDepartmentsFromApi();
        $branchInfo = $this->extractPlacementBranch($record, $departments);
        if ($branchInfo['parentCode'] !== '') {
            $record['parentDepartmentCode'] = $branchInfo['parentCode'];
            $record['parentDepartmentName'] = $branchInfo['parentName'];
            $record['stud_deptcode'] = $branchInfo['parentCode'];
        }
        if ($branchInfo['branch'] !== '') {
            $record['branch'] = $branchInfo['branch'];
            $record['programme'] = $branchInfo['branch'];
            $record['department'] = $branchInfo['branch'];
            $record['department_code'] = $branchInfo['branch'];
            $branchLabel = DepartmentProgrammeCatalog::programmeLabel($branchInfo['branch']);
            $readableName = $branchLabel !== ''
                ? $branchLabel
                : ($branchInfo['parentName'] !== '' ? $branchInfo['parentName'] : $branchInfo['branch']);
            $record['departmentName'] = $readableName;
            $record['deptName'] = $readableName;
            $record['deptCode'] = $branchInfo['branch'];
        } elseif ($branchInfo['parentCode'] !== '' && $branchInfo['parentName'] !== '') {
            $record['department'] = $branchInfo['parentCode'];
            $record['department_code'] = $branchInfo['parentCode'];
            $record['department_name'] = $branchInfo['parentName'];
            $record['dept_name'] = $branchInfo['parentName'];
            $record['deptName'] = $branchInfo['parentName'];
            $record['deptCode'] = $branchInfo['parentCode'];
        }

        // CGPA, school marks, and edu rows come from getStudQual4Placement only.
        $record = $this->stripInfoQualificationFields($record);

        $admno = $this->resolveQualificationAdmissionNumber($record, $this->resolveAdmissionNumber($params, (string) ($request['admno'] ?? '')));
        if ($admno !== '' && ctype_digit($admno)) {
            $qualParams = ['admno' => $admno, 'stud_admno' => $admno];
            $regNo = trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? ''));
            if ($regNo !== '' && $regNo !== $admno) {
                $qualParams['registerno'] = $regNo;
                $qualParams['registerNumber'] = $regNo;
            }
            $qual = $this->fetchStudentQualificationProfile($qualParams);
            if ($qual !== []) {
                $record = $this->mergeQualificationIntoPlacement($record, $qual);
            }
        }

        if ($cacheKey !== '') {
            self::$placementProfileCache[$cacheKey] = $record;
        }

        return $record;
    }

    /**
     * Qualification fields already merged into a placement profile (avoids duplicate AES qual calls).
     *
     * @param array<string, mixed> $placement
     * @return array<string, mixed>
     */
    public function extractQualificationFromPlacement(array $placement): array
    {
        $quals = is_array($placement['qualifications'] ?? null) ? $placement['qualifications'] : [];
        $cgpa = isset($placement['cgpa']) && is_numeric($placement['cgpa']) && (float) $placement['cgpa'] > 0
            ? (float) $placement['cgpa']
            : null;
        $marks10 = isset($placement['marks10th']) && is_numeric($placement['marks10th']) && (float) $placement['marks10th'] > 0
            ? (float) $placement['marks10th']
            : null;
        $marks12 = isset($placement['marks12th']) && is_numeric($placement['marks12th']) && (float) $placement['marks12th'] > 0
            ? (float) $placement['marks12th']
            : null;

        if ($quals === [] && $cgpa === null && $marks10 === null && $marks12 === null) {
            return [];
        }

        $out = [];
        if ($cgpa !== null) {
            $out['cgpa'] = $cgpa;
        }
        if ($marks10 !== null) {
            $out['marks10th'] = $marks10;
        }
        if ($marks12 !== null) {
            $out['marks12th'] = $marks12;
            $out['ugMarks'] = $marks12;
        }
        if ($quals !== []) {
            $out['qualifications'] = $quals;
        }

        return $out;
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function placementProfileCacheKey(array $params, string $register): string
    {
        $admno = $this->resolveQualificationAdmissionNumber($params, $register);

        return $admno !== '' ? $admno : strtoupper(trim($register));
    }

    /**
     * Fetch normalized 10th/12th marks, CGPA, and edu qualifications from getStudQual4Placement.
     *
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function fetchStudentQualificationProfile(array $params): array
    {
        $admno = $this->resolveQualificationAdmissionNumber($params, $this->resolveAdmissionNumber($params));
        if ($admno === '' || !ctype_digit($admno)) {
            return [];
        }

        if (isset(self::$qualificationProfileCache[$admno])) {
            return self::$qualificationProfileCache[$admno];
        }

        $qualParams = ['admno' => $admno, 'stud_admno' => $admno];
        $registerNo = trim((string) ($params['registerno'] ?? $params['registerNumber'] ?? ''));
        if ($registerNo !== '' && $registerNo !== $admno) {
            $qualParams['registerno'] = $registerNo;
            $qualParams['registerNumber'] = $registerNo;
        }

        $raw = $this->extractQualificationRawRecord(
            $this->postStudQual4Placement($qualParams, $admno)
        );
        $out = $this->normalizeQualificationRecord($raw);
        if (!is_array($out['qualifications'] ?? null) || $out['qualifications'] === []) {
            self::$qualificationProfileCache[$admno] = $out;

            return $out;
        }

        $infoPlacement = $this->normalizePlacementStudentRecord(
            $this->extractRecord($this->postStudInfo4Placement(['admno' => $admno], $admno))
        );
        $out['qualifications'] = $this->mergeInfoPlacementOntoQualRows($out['qualifications'], $infoPlacement);

        self::$qualificationProfileCache[$admno] = $out;

        return $out;
    }

    /**
     * Education table rows from getStudQual4Placement only (edu list or marks/CGPA fields).
     *
     * @param array<string, scalar|null> $params
     * @return list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}>
     */
    public function fetchStudentQualificationTableRows(array $params): array
    {
        $qual = $this->fetchStudentQualificationProfile($params);
        if (!is_array($qual['qualifications'] ?? null)) {
            return [];
        }

        return $qual['qualifications'];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function normalizeQualificationRecord(array $record): array
    {
        if ($record === []) {
            return [];
        }

        $normalized = $this->normalizePlacementStudentRecord($record);
        // Use only real AES edu / qualification rows — never synthesize SSLC/HSC/CGPA
        // tiles from top-level marks (those belong as scalar fields, not table rows).
        $qualifications = !empty($normalized['qualifications']) && is_array($normalized['qualifications'])
            ? $normalized['qualifications']
            : $this->parseEducationQualifications($normalized);
        if ($qualifications !== []) {
            $normalized = $this->applySchoolMarksFromQualificationRows($normalized, $qualifications);
            $normalized['qualifications'] = $qualifications;
        }

        if ((empty($normalized['cgpa']) || (float) $normalized['cgpa'] <= 0) && $qualifications !== []) {
            $fromRows = $this->pickCgpaFromQualificationRows($qualifications);
            if ($fromRows !== null) {
                $normalized['cgpa'] = $fromRows;
            }
        }

        $out = [];
        if (isset($normalized['cgpa']) && (float) $normalized['cgpa'] > 0) {
            $out['cgpa'] = (float) $normalized['cgpa'];
        }
        if (isset($normalized['marks10th']) && (float) $normalized['marks10th'] > 0) {
            $out['marks10th'] = (float) $normalized['marks10th'];
        }
        if (isset($normalized['marks12th']) && (float) $normalized['marks12th'] > 0) {
            $out['marks12th'] = (float) $normalized['marks12th'];
            $out['ugMarks'] = (float) $normalized['marks12th'];
        }
        if (isset($normalized['backlogs']) || isset($normalized['backlog'])) {
            $out['backlogs'] = (int) ($normalized['backlogs'] ?? $normalized['backlog'] ?? 0);
        }
        if ($qualifications !== []) {
            $out['qualifications'] = $qualifications;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $placement
     * @param array<string, mixed> $qual
     * @return array<string, mixed>
     */
    private function mergeQualificationIntoPlacement(array $placement, array $qual): array
    {
        if ($qual === []) {
            return $placement;
        }

        foreach (['cgpa', 'marks10th', 'marks12th'] as $key) {
            $incoming = isset($qual[$key]) && is_numeric($qual[$key]) ? (float) $qual[$key] : 0.0;
            if ($incoming > 0) {
                $placement[$key] = $incoming;
            }
        }

        if ((!isset($placement['backlogs']) && !isset($placement['backlog'])) && isset($qual['backlogs'])) {
            $placement['backlogs'] = (int) $qual['backlogs'];
        }

        if (
            (!isset($placement['ugMarks']) || (float) $placement['ugMarks'] <= 0)
            && isset($placement['marks12th'])
            && (float) $placement['marks12th'] > 0
        ) {
            $placement['ugMarks'] = (float) $placement['marks12th'];
        }

        $incomingQuals = !empty($qual['qualifications']) && is_array($qual['qualifications']) ? $qual['qualifications'] : [];
        if ($incomingQuals !== []) {
            $placement['qualifications'] = $incomingQuals;
        }

        return $placement;
    }

    /**
     * Remove academic fields that must be sourced from getStudQual4Placement, not getStudInfo4Placement.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function stripInfoQualificationFields(array $record): array
    {
        unset(
            $record['cgpa'],
            $record['marks10th'],
            $record['marks12th'],
            $record['ugMarks'],
            $record['qualifications'],
            $record['edu']
        );

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}> $qualifications
     * @return array<string, mixed>
     */
    private function applySchoolMarksFromQualificationRows(array $record, array $qualifications): array
    {
        foreach ($qualifications as $q) {
            if (!is_array($q)) {
                continue;
            }
            $label = strtoupper((string) ($q['qualification'] ?? ''));
            $mark = isset($q['mark']) && is_numeric($q['mark']) ? (float) $q['mark'] : null;
            $maxMark = isset($q['maxMark']) && is_numeric($q['maxMark']) ? (float) $q['maxMark'] : null;
            $isCgpaRow = preg_match('/\b(CGPA|CURRENT)\b/', $label) === 1
                || (
                    $label === ''
                    && $mark !== null
                    && $mark > 0
                    && $mark <= 10
                    && ($maxMark === null || $maxMark <= 10)
                );
            if (
                (empty($record['cgpa']) || (float) $record['cgpa'] <= 0)
                && $isCgpaRow
                && $mark !== null
                && $mark > 0
                && $mark <= 10
            ) {
                $record['cgpa'] = $mark;
            }

            $pct = isset($q['percentage']) && is_numeric($q['percentage']) ? (float) $q['percentage'] : null;
            if ($pct === null || $pct <= 0) {
                continue;
            }
            if (empty($record['marks10th']) && preg_match('/\b(SSLC|SSC|10TH|10\s*STD|CLASS\s*X|SECONDARY)\b/', $label)) {
                $record['marks10th'] = $pct;
            }
            if (empty($record['marks12th']) && preg_match('/\b(HSC|12TH|12\s*STD|PLUS\s*TWO|PLUS2|PUC|CLASS\s*XII|HIGHER\s*SECONDARY)\b/', $label)) {
                $record['marks12th'] = $pct;
            }
        }

        return $record;
    }

    /**
     * Map AES placement API fields (deptCode, deptName, …) to profile keys.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function normalizePlacementStudentRecord(array $record): array
    {
        if ($record === []) {
            return [];
        }

        $parentCode = strtoupper(trim((string) (
            $record['stud_deptcode']
            ?? $record['dept_code']
            ?? $record['department_code']
            ?? ''
        )));
        $courseShort = strtoupper(trim((string) (
            $record['stud_cource_short']
            ?? $record['stud_course']
            ?? ''
        )));
        $branchName = trim((string) (
            $record['stud_branch']
            ?? $record['branch_name']
            ?? $record['branchName']
            ?? ''
        ));

        if ($parentCode !== '') {
            $record['parentDepartmentCode'] = $parentCode;
            $record['stud_deptcode'] = $parentCode;
        }

        // Keep stud_branch as branch label; prefer parent department name for departmentName.
        if ($branchName !== '') {
            $record['stud_branch'] = $branchName;
            $record['branch_name'] = $branchName;
        }

        // Course shorts like BT (B.Tech) / MT (M.Tech) are not department codes.
        if ($courseShort !== '' && !$this->isCourseLevelShort($courseShort)) {
            $record['branch'] = $courseShort;
            $record['programme'] = $courseShort;
            $record['deptCode'] = $courseShort;
            $record['department'] = $courseShort;
        } elseif ($parentCode !== '' && empty($record['department'])) {
            $record['deptCode'] = $parentCode;
            $record['department'] = $parentCode;
        }

        $legacyName = trim((string) (
            $record['parentDepartmentName']
            ?? $record['dept_name']
            ?? $record['department_name']
            ?? ''
        ));
        if ($legacyName !== '') {
            $record['departmentName'] = $legacyName;
            $record['deptName'] = $legacyName;
        } elseif ($branchName !== '' && empty($record['departmentName'])) {
            $record['departmentName'] = $branchName;
            $record['deptName'] = $branchName;
        } elseif (empty($record['departmentName'])) {
            $branchLabel = ($courseShort !== '' && !$this->isCourseLevelShort($courseShort))
                ? DepartmentProgrammeCatalog::programmeLabel($courseShort)
                : '';
            if ($branchLabel !== '') {
                $record['departmentName'] = $branchLabel;
                $record['deptName'] = $branchLabel;
            }
        }

        if (!empty($record['stud_admno'])) {
            $admno = trim((string) $record['stud_admno']);
            $record['registerNumber'] = $admno;
            $record['admission_no'] = $admno;
            $record['admno'] = $admno;
        }
        if (!empty($record['registerno'])) {
            $record['registerno'] = trim((string) $record['registerno']);
        }
        if (!empty($record['stud_name'])) {
            $record['name'] = trim((string) $record['stud_name']);
        }
        if (!empty($record['stud_mobiles'])) {
            $record['phone'] = trim((string) $record['stud_mobiles']);
        }
        if (!empty($record['stud_ajce_mails'])) {
            $record['collegeEmail'] = strtolower(trim((string) $record['stud_ajce_mails']));
        }
        if (!empty($record['stud_personal_mails'])) {
            $record['personalEmail'] = strtolower(trim((string) $record['stud_personal_mails']));
        }
        $cgpa = $this->pickCgpaFromRecord($record);
        if ($cgpa !== null) {
            $record['cgpa'] = $cgpa;
        }
        $photoUrl = trim((string) (
            $record['stud_photo']
            ?? $record['photoUrl']
            ?? $record['photo_url']
            ?? $record['profile_photo']
            ?? ''
        ));
        $resolvedEarlyPhoto = $this->resolvePhotoUrl($photoUrl);
        if ($resolvedEarlyPhoto !== '') {
            $record['stud_photo'] = $resolvedEarlyPhoto;
            $record['photoUrl'] = $resolvedEarlyPhoto;
        }
        if (!empty($record['stud_class'])) {
            $record['classBatch'] = trim((string) $record['stud_class']);
        }
        if (isset($record['stud_year']) && $record['stud_year'] !== '') {
            $record['year'] = trim((string) $record['stud_year']);
        }
        if (isset($record['stud_semester']) && $record['stud_semester'] !== '') {
            $record['semester'] = trim((string) $record['stud_semester']);
        }
        if (isset($record['backlog']) && $record['backlog'] !== '' && is_numeric($record['backlog'])) {
            $record['backlogs'] = (int) $record['backlog'];
        }

        $courseId = $this->pickScalarId($record, [
            'courseId', 'course_id', 'CourseId', 'courseid', 'courseID',
            'stud_courseid', 'stud_course_id', 'stud_courseId',
            // AES stud-info has no dedicated courseId; parent dept code is the
            // closest stable numeric course/department key on the student row.
            'stud_deptcode', 'deptCode', 'dept_code', 'parentDepartmentCode',
        ]);
        if ($courseId !== '') {
            $record['courseId'] = $courseId;
            $record['course_id'] = $courseId;
        }
        $branchId = $this->pickScalarId($record, [
            'branchId', 'branch_id', 'BranchId', 'branchid', 'branchID',
            'stud_branchid', 'stud_branch_id', 'stud_branchId',
        ]);
        if ($branchId !== '') {
            $record['branchId'] = $branchId;
            $record['branch_id'] = $branchId;
            $record['stud_branchid'] = $branchId;
        }

        $marks10 = $this->pickMarkPercentFromRecord($record, [
            'marks10th', 'marks_10th', 'mark10th', 'mark_10th', 'sslc', 'sslc_marks', 'sslcMarks',
            'sslc_percentage', 'sslcPercent', 'sslc_percent', 'sslc_per', 'sslcper', 'sslcpercent',
            'stud_sslc', 'stud_sslc_marks', 'stud_sslc_percent', 'stud_sslcper', 'stud_sslc_per',
            'tenth_marks', 'tenth_percentage', 'tenthPercent', 'percent_10', 'percent10',
            '10th_marks', '10th_percentage', 'mark_10', 'mark10', 'stud_10th', 'stud_10th_marks',
            'ssc', 'ssc_marks', 'ssc_percent', 'ssc_percentage', 'stud_ssc', 'stud_ssc_marks',
            'x_marks', 'x_percent', 'x_percentage', 'stud_x', 'secondary_percentage', 'secondary_marks',
        ]);
        if ($marks10 !== null) {
            $record['marks10th'] = $marks10;
        }
        $marks12 = $this->pickMarkPercentFromRecord($record, [
            'marks12th', 'marks_12th', 'mark12th', 'mark_12th', 'hsc', 'hsc_marks', 'hscMarks',
            'hsc_percentage', 'hscPercent', 'hsc_percent', 'hsc_per', 'hscper', 'hscpercent',
            'stud_hsc', 'stud_hsc_marks', 'stud_hsc_percent', 'stud_hscper', 'stud_hsc_per',
            'twelfth_marks', 'twelfth_percentage', 'twelfthPercent', 'percent_12', 'percent12',
            '12th_marks', '12th_percentage', 'mark_12', 'mark12', 'stud_12th', 'stud_12th_marks',
            'plus2', 'plus_two', 'plus2_marks', 'plus2_percentage', 'plus_two_marks', 'plus2_per',
            'ug_marks', 'ugMarks', 'ug_percent', 'ug_percentage',
            'puc', 'puc_marks', 'puc_percent', 'xii_marks', 'xii_percent', 'stud_xii', 'higher_secondary',
        ]);
        if ($marks12 !== null) {
            $record['marks12th'] = $marks12;
            if (!isset($record['ugMarks']) || (float) $record['ugMarks'] <= 0) {
                $record['ugMarks'] = $marks12;
            }
        }

        $deepMarks = $this->extractSchoolMarksDeep($record);
        if (($record['marks10th'] ?? null) === null && $deepMarks['marks10th'] !== null) {
            $record['marks10th'] = $deepMarks['marks10th'];
        }
        if (($record['marks12th'] ?? null) === null && $deepMarks['marks12th'] !== null) {
            $record['marks12th'] = $deepMarks['marks12th'];
            if (!isset($record['ugMarks']) || (float) $record['ugMarks'] <= 0) {
                $record['ugMarks'] = $deepMarks['marks12th'];
            }
        }

        return $record;
    }

    /**
     * Build qualification table rows from getStudQual4Placement marks/CGPA when `edu` is absent.
     *
     * @param array<string, mixed> $record
     * @return list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}>
     */
    public function buildQualificationTableRowsFromMarks(array $record): array
    {
        $rows = [];
        $regNo = trim((string) ($record['registerno'] ?? $record['registerNumber'] ?? $record['admission_no'] ?? $record['admno'] ?? ''));

        if (isset($record['marks10th']) && is_numeric($record['marks10th']) && (float) $record['marks10th'] > 0) {
            $rows[] = [
                'qualification'  => 'SSLC / 10th',
                'institution'    => '',
                'registerNumber' => $regNo,
                'monthYear'      => '',
                'mark'           => null,
                'maxMark'        => null,
                'percentage'     => (float) $record['marks10th'],
            ];
        }
        if (isset($record['marks12th']) && is_numeric($record['marks12th']) && (float) $record['marks12th'] > 0) {
            $rows[] = [
                'qualification'  => 'HSC / 12th',
                'institution'    => '',
                'registerNumber' => $regNo,
                'monthYear'      => '',
                'mark'           => null,
                'maxMark'        => null,
                'percentage'     => (float) $record['marks12th'],
            ];
        }
        if (isset($record['cgpa']) && is_numeric($record['cgpa']) && (float) $record['cgpa'] > 0) {
            $rows[] = [
                'qualification'  => 'CGPA',
                'institution'    => '',
                'registerNumber' => $regNo,
                'monthYear'      => '',
                'mark'           => (float) $record['cgpa'],
                'maxMark'        => 10.0,
                'percentage'     => null,
            ];
        }

        return $rows;
    }

    /**
     * Normalize AES getStudInfo4Placement `edu` rows.
     *
     * @param array<string, mixed> $record
     * @return list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}>
     */
    public function parseEducationQualifications(array $record): array
    {
        $edu = $record['edu'] ?? $record['education'] ?? $record['edudetails'] ?? null;
        if (!is_array($edu) || $edu === []) {
            return [];
        }

        $rows = array_is_list($edu) ? $edu : array_values($edu);
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $qualification = trim((string) ($row['qualification'] ?? $row['qual'] ?? $row['degree'] ?? ''));
            $institution = trim((string) (
                $row['instname']
                ?? $row['inst_name']
                ?? $row['instName']
                ?? $row['institution']
                ?? ''
            ));
            $registerNumber = trim((string) (
                $row['regno']
                ?? $row['reg_no']
                ?? $row['registerno']
                ?? $row['registerNumber']
                ?? $row['register_no']
                ?? $row['registration_no']
                ?? $row['univ_regno']
                ?? $row['university_regno']
                ?? ''
            ));
            $monthYear = trim((string) ($row['monthyear'] ?? $row['month_year'] ?? $row['passedYear'] ?? ''));

            $mark = null;
            if (isset($row['mark']) && $row['mark'] !== '' && is_numeric($row['mark'])) {
                $mark = (float) $row['mark'];
            }
            $maxMark = null;
            if (isset($row['maxmark']) && $row['maxmark'] !== '' && is_numeric($row['maxmark'])) {
                $maxMark = (float) $row['maxmark'];
            } elseif (isset($row['max_mark']) && $row['max_mark'] !== '' && is_numeric($row['max_mark'])) {
                $maxMark = (float) $row['max_mark'];
            }

            $percentage = null;
            if (isset($row['percentage']) && $row['percentage'] !== '' && is_numeric($row['percentage'])) {
                $pct = (float) $row['percentage'];
                if ($pct > 0 && $pct <= 100) {
                    $percentage = $pct;
                }
            }
            if ($mark === null && isset($row['mark']) && is_scalar($row['mark'])) {
                $pctFromMark = $this->parsePercentMarkString((string) $row['mark']);
                if ($pctFromMark !== null) {
                    $mark = $pctFromMark;
                    if ($maxMark === null) {
                        $maxMark = 100.0;
                    }
                    $percentage = $pctFromMark;
                }
            }
            if ($percentage === null && $mark !== null && $maxMark !== null && $maxMark > 0) {
                $pct = round(($mark / $maxMark) * 100, 2);
                if ($pct > 0 && $pct <= 100) {
                    $percentage = $pct;
                }
            }

            if ($qualification === '' && $institution === '' && $registerNumber === '' && $monthYear === '' && $mark === null && $percentage === null) {
                continue;
            }

            // AES often returns current CGPA with blank qualification and maxmark=10.
            if ($qualification === '' && $maxMark !== null && $maxMark <= 10.0) {
                $qualification = 'Current CGPA';
            } elseif ($qualification !== '' && preg_match('/^tenth$/i', $qualification) === 1) {
                $qualification = 'SSLC / 10th';
            } elseif ($qualification !== '' && preg_match('/^plus\s*two$/i', $qualification) === 1) {
                $qualification = 'Plus Two / 12th';
            }

            $out[] = [
                'qualification'  => $qualification,
                'institution'    => $institution,
                'registerNumber' => $registerNumber,
                'monthYear'      => $monthYear,
                'mark'           => $mark,
                'maxMark'        => $maxMark,
                'percentage'     => $percentage,
            ];
        }

        return $out;
    }

    /**
     * Parse AES school mark strings such as "68%" or "94%" when mark is not numeric.
     */
    private function parsePercentMarkString(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $value, $matches) === 1) {
            $pct = (float) $matches[1];

            return ($pct > 0 && $pct <= 100) ? $pct : null;
        }

        return null;
    }

    /**
     * Merge institution / registerNumber / monthYear from getStudInfo4Placement onto qual-API rows.
     * Qual-API values win when present; info-API edu rows fill gaps matched by qualification kind.
     * Current CGPA rows also receive top-level registerno / stud_class when still empty.
     *
     * @param list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}> $qualRows
     * @param array<string, mixed> $infoPlacement Normalized getStudInfo4Placement record.
     * @return list<array{qualification: string, institution: string, registerNumber: string, monthYear: string, mark: ?float, maxMark: ?float, percentage: ?float}>
     */
    private function mergeInfoPlacementOntoQualRows(array $qualRows, array $infoPlacement): array
    {
        if ($qualRows === []) {
            return [];
        }

        $infoByKey = [];
        foreach ($this->parseEducationQualifications($infoPlacement) as $infoRow) {
            $key = $this->qualificationMatchKey(
                (string) ($infoRow['qualification'] ?? ''),
                isset($infoRow['mark']) && is_numeric($infoRow['mark']) ? (float) $infoRow['mark'] : null,
                isset($infoRow['maxMark']) && is_numeric($infoRow['maxMark']) ? (float) $infoRow['maxMark'] : null
            );
            $institution = trim((string) ($infoRow['institution'] ?? ''));
            $reg = trim((string) ($infoRow['registerNumber'] ?? ''));
            $monthYear = trim((string) ($infoRow['monthYear'] ?? ''));
            if ($institution === '' && $reg === '' && $monthYear === '') {
                continue;
            }
            if (!isset($infoByKey[$key])) {
                $infoByKey[$key] = ['institution' => '', 'registerNumber' => '', 'monthYear' => ''];
            }
            if ($institution !== '' && $infoByKey[$key]['institution'] === '') {
                $infoByKey[$key]['institution'] = $institution;
            }
            if ($reg !== '' && $infoByKey[$key]['registerNumber'] === '') {
                $infoByKey[$key]['registerNumber'] = $reg;
            }
            if ($monthYear !== '' && $infoByKey[$key]['monthYear'] === '') {
                $infoByKey[$key]['monthYear'] = $monthYear;
            }
        }

        $topRegister = trim((string) ($infoPlacement['registerno'] ?? $infoPlacement['registerNumber'] ?? ''));
        $topBatch = trim((string) ($infoPlacement['stud_class'] ?? $infoPlacement['classBatch'] ?? ''));

        $out = [];
        foreach ($qualRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $institution = trim((string) ($row['institution'] ?? ''));
            $registerNumber = trim((string) ($row['registerNumber'] ?? ''));
            $monthYear = trim((string) ($row['monthYear'] ?? ''));
            $key = $this->qualificationMatchKey(
                (string) ($row['qualification'] ?? ''),
                isset($row['mark']) && is_numeric($row['mark']) ? (float) $row['mark'] : null,
                isset($row['maxMark']) && is_numeric($row['maxMark']) ? (float) $row['maxMark'] : null
            );

            if (isset($infoByKey[$key])) {
                if ($institution === '' && $infoByKey[$key]['institution'] !== '') {
                    $institution = $infoByKey[$key]['institution'];
                }
                if ($registerNumber === '' && $infoByKey[$key]['registerNumber'] !== '') {
                    $registerNumber = $infoByKey[$key]['registerNumber'];
                }
                if ($monthYear === '' && $infoByKey[$key]['monthYear'] !== '') {
                    $monthYear = $infoByKey[$key]['monthYear'];
                }
            }

            if ($key === 'current_cgpa') {
                if ($registerNumber === '' && $topRegister !== '') {
                    $registerNumber = $topRegister;
                }
                if ($monthYear === '' && $topBatch !== '') {
                    $monthYear = $topBatch;
                }
            }

            $qualificationLabel = trim((string) ($row['qualification'] ?? ''));
            if ($key === 'current_cgpa' && $qualificationLabel === '') {
                $qualificationLabel = $this->resolveCurrentProgramQualificationLabel($infoPlacement);
            }

            $out[] = [
                'qualification'  => $qualificationLabel,
                'institution'    => $institution,
                'registerNumber' => $registerNumber,
                'monthYear'      => $monthYear,
                'mark'           => isset($row['mark']) && is_numeric($row['mark']) ? (float) $row['mark'] : null,
                'maxMark'        => isset($row['maxMark']) && is_numeric($row['maxMark']) ? (float) $row['maxMark'] : null,
                'percentage'     => isset($row['percentage']) && is_numeric($row['percentage']) ? (float) $row['percentage'] : null,
            ];
        }

        return $out;
    }

    /**
     * Normalized key for matching qual-API and info-API edu rows (labels differ per endpoint).
     */
    private function qualificationMatchKey(string $qualification, ?float $mark, ?float $maxMark): string
    {
        $upper = strtoupper(trim($qualification));
        if (preg_match('/\b(SSLC|SSC|10TH|10\s*STD|CLASS\s*X|SECONDARY|TENTH)\b/', $upper)) {
            return 'sslc';
        }
        if (preg_match('/\b(HSC|12TH|12\s*STD|PLUS\s*TWO|PLUS2|PLUS-2|PUC|CLASS\s*XII|HIGHER\s*SECONDARY|TWELFTH)\b/', $upper)) {
            return 'hsc';
        }
        if (
            preg_match('/\bBCA\b/', $upper)
            || preg_match('/\bB\.?\s*C\.?\s*A\.?\b/', $upper)
            || preg_match('/BACHELOR\s+OF\s+COMPUTER\s+APPLICATIONS?/i', $qualification)
        ) {
            return 'bca';
        }
        if (preg_match('/\b(CGPA|CURRENT)\b/', $upper)) {
            return 'current_cgpa';
        }
        if (
            $upper === ''
            && $mark !== null
            && $mark > 0
            && $mark <= 10
            && ($maxMark === null || $maxMark <= 10)
        ) {
            return 'current_cgpa';
        }
        if (preg_match('/\b(B\.?\s*TECH|BTECH|MCA|BE|B\.?\s*SC|BSC|BACHELOR|UG)\b/', $upper)) {
            return 'degree:' . preg_replace('/\s+/', '', $upper);
        }

        return 'label:' . $upper;
    }

    /**
     * @param array<string, mixed> $infoPlacement
     */
    private function resolveCurrentProgramQualificationLabel(array $infoPlacement): string
    {
        $label = trim((string) (
            $infoPlacement['stud_cource_short']
            ?? $infoPlacement['stud_course']
            ?? $infoPlacement['programme']
            ?? $infoPlacement['branch']
            ?? ''
        ));
        if ($label !== '') {
            return $label;
        }

        return 'CGPA';
    }

    public function resolvePhotoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            foreach (['https://login.aesajce.in', 'https://www.aesajce.in', 'https://api.aesajce.in'] as $base) {
                $candidate = $base . $url;
                if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $record
     * @return array{marks10th: ?float, marks12th: ?float}
     */
    private function extractSchoolMarksDeep(array $record): array
    {
        $found = ['marks10th' => null, 'marks12th' => null];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $lower = strtolower((string) $key);
                if ($this->isTenthMarkFieldKey($lower)) {
                    $mark = $this->parseMarkScalar($value);
                    if ($mark !== null && $found['marks10th'] === null) {
                        $found['marks10th'] = $mark;
                    }
                }
                if ($this->isTwelfthMarkFieldKey($lower)) {
                    $mark = $this->parseMarkScalar($value);
                    if ($mark !== null && $found['marks12th'] === null) {
                        $found['marks12th'] = $mark;
                    }
                }
            }
        };
        $walk($record);

        return $found;
    }

    private function isTenthMarkFieldKey(string $lower): bool
    {
        if (str_contains($lower, '12') || str_contains($lower, 'hsc') || str_contains($lower, 'plus')
            || str_contains($lower, 'xii') || str_contains($lower, 'puc') || str_contains($lower, 'ug_')) {
            return false;
        }

        return str_contains($lower, 'sslc') || str_contains($lower, 'ssc')
            || str_contains($lower, 'tenth') || str_contains($lower, '10th')
            || str_contains($lower, 'secondary') || preg_match('/(^|_)10($|_)/', $lower) === 1
            || $lower === 'x_marks' || $lower === 'x_percent' || $lower === 'stud_x';
    }

    private function isTwelfthMarkFieldKey(string $lower): bool
    {
        return str_contains($lower, 'hsc') || str_contains($lower, 'twelfth') || str_contains($lower, '12th')
            || str_contains($lower, 'plus2') || str_contains($lower, 'plus_two') || str_contains($lower, 'plus-2')
            || str_contains($lower, 'puc') || str_contains($lower, 'xii') || str_contains($lower, 'ug_mark')
            || preg_match('/(^|_)12($|_)/', $lower) === 1;
    }

    /**
     * @param mixed $value
     */
    private function parseMarkScalar(mixed $value): ?float
    {
        if (is_numeric($value)) {
            $n = (float) $value;

            return ($n > 0 && $n <= 100) ? $n : null;
        }
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
            return null;
        }
        $n = (float) $m[1];

        return ($n > 0 && $n <= 100) ? $n : null;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $keys
     */
    private function pickMarkPercentFromRecord(array $record, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record) || $record[$key] === '' || $record[$key] === null) {
                continue;
            }
            $value = $record[$key];
            if (is_numeric($value)) {
                $n = (float) $value;
                if ($n > 0 && $n <= 100) {
                    return $n;
                }
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '' && preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
                $n = (float) $m[1];
                if ($n > 0 && $n <= 100) {
                    return $n;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $keys
     */
    private function pickScalarId(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record) || $record[$key] === null || $record[$key] === '') {
                continue;
            }
            $value = trim((string) $record[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve CGPA from getStudQual4Placement top-level aliases (totcgpa, curcgpa, …).
     *
     * @param array<string, mixed> $record
     */
    private function pickCgpaFromRecord(array $record): ?float
    {
        $keys = [
            'cgpa', 'CGPA', 'gpa', 'GPA', 'current_cgpa', 'currentCgpa', 'cumulative_cgpa', 'overall_cgpa',
            'totcgpa', 'tot_cgpa', 'totCgpa', 'curcgpa', 'cur_cgpa', 'curCgpa', 'grade_point', 'gradePoint',
            'stud_cgpa', 'stud_curcgpa', 'stud_totcgpa', 'sgpa', 'SGPA',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record) || $record[$key] === '' || $record[$key] === null) {
                continue;
            }
            $value = $record[$key];
            if (is_array($value)) {
                $nested = $this->pickCgpaFromRecord($value);
                if ($nested !== null) {
                    return $nested;
                }
                continue;
            }
            if (is_numeric($value)) {
                $n = (float) $value;
                if ($n > 0 && $n <= 10) {
                    return $n;
                }
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '' && preg_match('/(\d+(?:\.\d+)?)/', $text, $m)) {
                $n = (float) $m[1];
                if ($n > 0 && $n <= 10) {
                    return $n;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{qualification?: string, mark?: ?float, maxMark?: ?float}> $qualifications
     */
    private function pickCgpaFromQualificationRows(array $qualifications): ?float
    {
        foreach ($qualifications as $q) {
            if (!is_array($q)) {
                continue;
            }
            $label = strtoupper((string) ($q['qualification'] ?? ''));
            $mark = isset($q['mark']) && is_numeric($q['mark']) ? (float) $q['mark'] : null;
            $maxMark = isset($q['maxMark']) && is_numeric($q['maxMark']) ? (float) $q['maxMark'] : null;
            $isCgpaRow = preg_match('/\b(CGPA|CURRENT)\b/', $label) === 1
                || (
                    $label === ''
                    && $mark !== null
                    && $mark > 0
                    && $mark <= 10
                    && ($maxMark === null || $maxMark <= 10)
                );
            if ($isCgpaRow && $mark !== null && $mark > 0 && $mark <= 10) {
                return $mark;
            }
        }

        return null;
    }

    /**
     * Pull AES departments into the local departments collection and reconcile numeric AES ids to short codes.
     */
    public function syncDepartmentsToLocal(): int
    {
        self::$departmentCache = null;
        $rows = $this->listDepartments();
        if ($rows === []) {
            return 0;
        }

        $model = new DepartmentModel();
        $changed = 0;
        foreach ($rows as $row) {
            $code = strtoupper(trim($row['code']));
            $name = trim($row['name']);
            $aesId = trim((string) ($row['aesId'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            // Keep numeric AES ids out; sync academic programmes and staff/support units alike.
            if (preg_match('/^\d+$/', $code) === 1) {
                continue;
            }

            $existing = $model->findByCode($code);
            if ($existing !== null) {
                $patch = [];
                if (trim((string) ($existing['name'] ?? '')) !== $name) {
                    $patch['name'] = $name;
                }
                if ($aesId !== '' && trim((string) ($existing['aesId'] ?? '')) !== $aesId) {
                    $patch['aesId'] = $aesId;
                }
                if ($patch !== []) {
                    $model->update((string) $existing['_id'], $patch);
                    $changed++;
                }
                if ($aesId !== '') {
                    $numeric = $model->findByCode($aesId);
                    if ($numeric !== null && (string) ($numeric['_id'] ?? '') !== (string) ($existing['_id'] ?? '')) {
                        $model->delete((string) $numeric['_id']);
                        $changed++;
                    }
                }
                continue;
            }

            if ($aesId !== '') {
                $numeric = $model->findByCode($aesId) ?? $model->findByAesId($aesId);
                if ($numeric !== null) {
                    $model->update((string) $numeric['_id'], [
                        'code'  => $code,
                        'name'  => $name,
                        'aesId' => $aesId,
                    ]);
                    $changed++;
                    continue;
                }
            }

            $model->createDepartment(['code' => $code, 'name' => $name, 'aesId' => $aesId]);
            $changed++;
        }

        return $changed;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, scalar|null>
     */
    private function stringifyParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $out[(string) $key] = $value ? '1' : '0';
                continue;
            }
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $out[(string) $key] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return array<string, mixed>
     */
    private function extractRecord(array $result): array
    {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        $payload = $result['data'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $this->normalizePlacementStudentRecord($payload[0]);
        }

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $record = $payload['data'] ?? $payload['student'] ?? $payload['profile'] ?? $payload['user'] ?? null;
        if (is_array($record) && $record !== []) {
            return $record;
        }

        $scalarKeys = [
            'name', 'student_name', 'stud_name', 'email', 'cgpa', 'department', 'dept', 'branch', 'admission_no',
            'stud_admno', 'deptCode', 'deptName', 'dept_code', 'dept_name', 'deptshort', 'stud_deptcode',
            'stud_course', 'stud_cource_short', 'registerNumber',
        ];
        foreach ($scalarKeys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return $payload;
            }
        }

        return [];
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return array<string, mixed>
     */
    private function extractQualificationRawRecord(array $result): array
    {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        $payload = $result['data'] ?? null;
        if ($payload === null && isset($result['raw']) && is_string($result['raw']) && trim($result['raw']) !== '') {
            $decoded = json_decode($result['raw'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            return ['edu' => $payload];
        }

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $record = $payload['data']
            ?? $payload['student']
            ?? $payload['profile']
            ?? $payload['qual']
            ?? $payload['qualification']
            ?? null;
        if (is_array($record) && $record !== []) {
            if (array_is_list($record) && isset($record[0]) && is_array($record[0])) {
                return ['edu' => $record];
            }

            return $record;
        }

        // Do not treat empty data:[] as a successful qualification payload
        // (that wrongly short-circuits the body transport fallback).
        if (
            isset($payload['data'])
            && is_array($payload['data'])
            && array_is_list($payload['data'])
            && $payload['data'] !== []
        ) {
            return ['edu' => $payload['data']];
        }

        $qualKeys = [
            'cgpa', 'totcgpa', 'tot_cgpa', 'curcgpa', 'cur_cgpa', 'current_cgpa', 'gpa', 'stud_cgpa',
            'edu', 'qualifications', 'marks10th', 'marks12th', 'sslc', 'hsc', 'sslc_marks', 'hsc_marks',
            'mark10th', 'mark12th', 'stud_sslc', 'stud_hsc', 'backlog', 'backlogs', 'ugMarks', 'ug_marks',
        ];
        foreach ($qualKeys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload;
            }
        }

        return [];
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return list<array{code:string,name:string}>
     */
    private function normalizeDepartmentRows(array $result): array
    {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        $payload = $result['data'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        $list = $payload['data'] ?? $payload['departments'] ?? $payload['dept'] ?? $payload;
        if (!is_array($list)) {
            return [];
        }

        if ($this->isAssoc($list)) {
            $list = [$list];
        }

        $rows = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $parsed = $this->parseDepartmentRow($item);
            if ($parsed === null) {
                continue;
            }
            $rows[] = $parsed;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{code:string,name:string,short:string,aesId:string}|null
     */
    private function parseDepartmentRow(array $item): ?array
    {
        $aesId = trim((string) (
            $item['deptCode']
                ?? $item['dept_code']
            ?? $item['deptId']
            ?? $item['dept_id']
            ?? $item['id']
            ?? ''
        ));
        $short = strtoupper(trim((string) (
            $item['deptshort']
            ?? $item['dept_short']
            ?? $item['short']
                ?? $item['br']
            ?? $item['branch_code']
            ?? ''
        )));
        $legacyCode = strtoupper(trim((string) (
            $item['code']
            ?? $item['department_code']
                ?? ''
            )));
            $name = trim((string) (
            $item['deptName']
                ?? $item['dept_name']
            ?? $item['dept_shortName']
            ?? $item['name']
                ?? $item['department_name']
                ?? $item['department']
                ?? $item['branch_name']
                ?? $item['branch']
                ?? ''
            ));

        $canonical = '';
        if ($short !== '' && preg_match('/[A-Z]/i', $short) === 1) {
            $canonical = strtoupper(preg_replace('/[^A-Z0-9]/', '', $short) ?: $short);
        } elseif ($legacyCode !== '' && preg_match('/^\d+$/', $legacyCode) !== 1 && preg_match('/[A-Z]/i', $legacyCode) === 1) {
            $canonical = $legacyCode;
        }

        if ($canonical === '' || $name === '') {
            return null;
        }

        return [
            'code'  => $canonical,
            'name'  => $name,
            'short' => $short !== '' ? $short : $canonical,
            'aesId' => preg_match('/^\d+$/', $aesId) === 1 ? $aesId : '',
        ];
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Placement courses/programmes for a parent AES department.
     *
     * @return list<string>
     */
    public function fetchPlacementCourses(string $deptAesId): array
    {
        $deptAesId = trim($deptAesId);
        if ($deptAesId === '') {
            return [];
        }

        $methods = ['getCourses4Placement', 'getCourse4Placement'];
        foreach ($methods as $method) {
            $result = $this->callPlacementFilterApi($method, ['stud_deptcode' => $deptAesId]);
            $labels = $this->normalizePlacementScalarLabels($result, [
                'stud_course', 'stud_cource_short', 'course', 'course_name', 'courseName', 'programme', 'program', 'code', 'name',
            ]);
            if ($labels !== []) {
                return $labels;
            }
        }

        return [];
    }

    /**
     * Placement branches — distinct stud_branch from getStudInfo4Placement for the programme.
     *
     * @return list<string>
     */
    public function fetchPlacementBranches(string $deptAesId, string $programmeCode): array
    {
        $deptAesId = trim($deptAesId);
        $programmeCode = trim($programmeCode);
        if ($deptAesId === '' || $programmeCode === '') {
            return [];
        }

        foreach ($this->placementCourseParamVariants($programmeCode) as $courseParams) {
            $params = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
            $labels = $this->fetchStudInfoFieldLabels($params, 'stud_branch', $programmeCode);
            if ($labels !== []) {
                return $labels;
            }
        }

        $methods = ['getBranches4Placement', 'getBranch4Placement'];
        foreach ($this->placementCourseParamVariants($programmeCode) as $courseParams) {
            $params = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
            foreach ($methods as $method) {
                $result = $this->callPlacementFilterApi($method, $params);
                $labels = $this->normalizePlacementScalarLabels($result, [
                    'stud_branch', 'branch_name', 'branchName', 'branch', 'name',
                ]);
                if ($labels !== []) {
                    return $labels;
                }
            }
        }

        return [];
    }

    /**
     * Placement class/batch list — distinct stud_class from getStudInfo4Placement.
     *
     * @return list<string>
     */
    public function fetchPlacementClassBatches(string $deptAesId, string $programmeCode = '', string $branch = ''): array
    {
        $deptAesId = trim($deptAesId);
        if ($deptAesId === '') {
            return [];
        }

        $programmeCode = trim($programmeCode);
        $branch = trim($branch);
        $courseVariants = $programmeCode !== ''
            ? $this->placementCourseParamVariants($programmeCode)
            : [[]];

        foreach ($courseVariants as $courseParams) {
            $params = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
            if ($branch !== '') {
                $params['stud_branch'] = $branch;
            }

            $labels = $this->fetchStudInfoFieldLabels($params, 'stud_class', $programmeCode, $branch);
            if ($labels !== []) {
                return $labels;
            }

            if ($branch !== '') {
                $withoutBranch = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
                $labels = $this->fetchStudInfoFieldLabels($withoutBranch, 'stud_class', $programmeCode);
                if ($labels !== []) {
                    return $labels;
                }
            }
        }

        return $this->fetchLegacyPlacementClassBatches($deptAesId, $programmeCode, $branch);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<string>
     */
    private function fetchStudInfoFieldLabels(
        array $params,
        string $field,
        string $programmeCode = '',
        string $branchFilter = ''
    ): array {
        $result = $this->callPlacementFilterApi('getStudInfo4Placement', $params);

        return $this->normalizeStudInfoFieldLabels($result, $field, $programmeCode, $branchFilter);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<string>
     */
    private function fetchStudClassBatchesFromStudInfo(array $params, string $programmeCode = '', string $branch = ''): array
    {
        return $this->fetchStudInfoFieldLabels($params, 'stud_class', $programmeCode, $branch);
    }

    /**
     * @return list<string>
     */
    private function fetchLegacyPlacementClassBatches(string $deptAesId, string $programmeCode, string $branch): array
    {
        $methods = ['getClasses4Placement', 'getClass4Placement', 'getBatches4Placement', 'getBatch4Placement'];
        $courseVariants = $programmeCode !== ''
            ? $this->placementCourseParamVariants($programmeCode)
            : [[]];

        foreach ($courseVariants as $courseParams) {
            $params = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
            if ($branch !== '') {
                $params['stud_branch'] = $branch;
            }

            foreach ($methods as $method) {
                $result = $this->callPlacementFilterApi($method, $params);
                $labels = $this->normalizeStudClassBatchLabels($result, $programmeCode, $branch);
                if ($labels !== []) {
                    return $labels;
                }
            }

            if ($branch !== '') {
                $withoutBranch = array_merge(['stud_deptcode' => $deptAesId], $courseParams);
                foreach ($methods as $method) {
                    $result = $this->callPlacementFilterApi($method, $withoutBranch);
                    $labels = $this->normalizeStudClassBatchLabels($result, $programmeCode, $branch);
                    if ($labels !== []) {
                        return $labels;
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    private function callPlacementFilterApi(string $method, array $params): array
    {
        try {
            $result = $this->callAESApi($method, $params);
            if (($result['success'] ?? false) === true) {
                return $result;
            }
        } catch (\Throwable) {
            // Fall through to query-string transport.
        }

        return $this->callAESApiWithMethodInQuery($method, $params);
    }

    /**
     * AES placement filters expect the course label from getCourses4Placement (e.g. M.Tech, PG Certificate).
     *
     * @return list<array<string, string>>
     */
    private function placementCourseParamVariants(string $programmeCode): array
    {
        $programmeCode = trim($programmeCode);
        if ($programmeCode === '') {
            return [[]];
        }

        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($programmeCode);
        $variants = [];
        $seen = [];
        foreach ([$programmeCode, $resolved] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            $variants[] = ['stud_course' => $candidate];
            $variants[] = ['stud_cource_short' => $candidate];
        }

        return $variants;
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @param list<string> $fieldKeys
     * @return list<string>
     */
    private function normalizePlacementScalarLabels(array $result, array $fieldKeys): array
    {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        $payload = $result['data'] ?? null;
        if (!is_array($payload) && isset($result['raw']) && is_string($result['raw']) && trim($result['raw']) !== '') {
            $decoded = json_decode($result['raw'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            return [];
        }

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $list = $payload['data'] ?? $payload['courses'] ?? $payload['branches'] ?? $payload;
        $labels = [];
        $this->collectPlacementScalarLabels($list, $labels, $fieldKeys);

        return array_values(array_unique(array_filter($labels, static fn (string $label) => $label !== '')));
    }

    /**
     * @param list<string> $fieldKeys
     * @param list<string> $out
     */
    private function collectPlacementScalarLabels(mixed $node, array &$out, array $fieldKeys): void
    {
        if (is_string($node)) {
            $label = trim($node);
            if ($label !== '') {
                if (str_contains($label, ',')) {
                    foreach (preg_split('/\s*,\s*/', $label) ?: [] as $part) {
                        $this->collectPlacementScalarLabels($part, $out, $fieldKeys);
                    }

                    return;
                }
                $out[] = $label;
            }

            return;
        }

        if (!is_array($node)) {
            return;
        }

        if ($this->isAssoc($node)) {
            foreach ($fieldKeys as $key) {
                $value = trim((string) ($node[$key] ?? ''));
                if ($value !== '') {
                    $out[] = $value;

                    return;
                }
            }
        }

        foreach ($node as $item) {
            $this->collectPlacementScalarLabels($item, $out, $fieldKeys);
        }
    }

    /**
     * Distinct stud_branch / stud_class labels from getStudInfo4Placement.
     *
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return list<string>
     */
    private function normalizeStudInfoFieldLabels(
        array $result,
        string $field,
        string $programmeCode = '',
        string $branchFilter = ''
    ): array {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        $payload = $result['data'] ?? null;
        if (!is_array($payload) && isset($result['raw']) && is_string($result['raw']) && trim($result['raw']) !== '') {
            $decoded = json_decode($result['raw'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (!is_array($payload)) {
            return [];
        }

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $list = $this->studInfoListFromPayload($payload);
        $labels = [];
        $this->collectStudInfoFieldLabels($list, $labels, $field, $programmeCode, $branchFilter);

        return array_values(array_unique(array_filter($labels, static fn (string $label) => $label !== '')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function studInfoListFromPayload(array $payload): mixed
    {
        return $payload['data']
            ?? $payload['students']
            ?? $payload['student_list']
            ?? $payload['studentList']
            ?? $payload['classes']
            ?? $payload['batches']
            ?? $payload['class_list']
            ?? $payload['classList']
            ?? $payload;
    }

    /**
     * @param list<string> $out
     */
    private function collectStudInfoFieldLabels(
        mixed $node,
        array &$out,
        string $field,
        string $programmeCode,
        string $branchFilter
    ): void {
        if (!is_array($node)) {
            return;
        }

        if ($this->isAssoc($node)) {
            if ($this->isStudInfoRow($node) && !$this->rowMatchesStudInfoScope($node, $programmeCode, $branchFilter)) {
                return;
            }

            $value = $this->studInfoFieldValue($node, $field);
            if ($value !== '' && ($field !== 'stud_branch' || $this->rowMatchesProgramme($node, $programmeCode))) {
                if ($field === 'stud_class') {
                    $course = strtoupper(trim((string) (
                        $node['stud_course']
                        ?? $node['stud_cource_short']
                        ?? $node['course']
                        ?? $node['programme']
                        ?? $programmeCode
                    )));
                    $out[] = $this->tagBatchLabelWithProgramme($value, $course !== '' ? $course : $programmeCode);
                } else {
                    $out[] = $value;
                }

                return;
            }
        }

        foreach ($node as $item) {
            $this->collectStudInfoFieldLabels($item, $out, $field, $programmeCode, $branchFilter);
        }
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return list<array<string, mixed>>
     */
    private function extractStudInfoRecords(array $result): array
    {
        $payload = $result['data'] ?? null;
        if (!is_array($payload)) {
            $raw = trim((string) ($result['raw'] ?? ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }
        if (!is_array($payload)) {
            return [];
        }

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $list = $this->studInfoListFromPayload($payload);
        $rows = [];
        $this->collectStudInfoRecords($list, $rows);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $out
     */
    private function collectStudInfoRecords(mixed $node, array &$out): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isAssoc($node)) {
            if ($this->isStudInfoRow($node)) {
                $out[] = $node;

                return;
            }
        }

        foreach ($node as $item) {
            $this->collectStudInfoRecords($item, $out);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isStudInfoRow(array $row): bool
    {
        foreach (['stud_class', 'stud_branch', 'stud_course', 'stud_cource_short', 'registerno', 'admno', 'stud_admno'] as $key) {
            if (!empty($row[$key]) && is_scalar($row[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatchesStudInfoScope(array $row, string $programmeCode, string $branchFilter): bool
    {
        if (!$this->rowMatchesProgramme($row, $programmeCode)) {
            return false;
        }

        if ($branchFilter === '') {
            return true;
        }

        $rowBranch = $this->studInfoFieldValue($row, 'stud_branch');

        return $rowBranch !== '' && strcasecmp($rowBranch, $branchFilter) === 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatchesProgramme(array $row, string $programmeCode): bool
    {
        if ($programmeCode === '') {
            return true;
        }

        $rowCourse = trim((string) (
            $row['stud_course']
            ?? $row['stud_cource_short']
            ?? $row['course']
            ?? $row['programme']
            ?? ''
        ));
        if ($rowCourse === '') {
            return true;
        }

        $targets = array_values(array_unique(array_filter([
            trim($programmeCode),
            DepartmentProgrammeCatalog::resolveProgrammeCode($programmeCode),
        ], static fn (string $code) => $code !== '')));

        foreach ($targets as $target) {
            if (strcasecmp($rowCourse, $target) === 0) {
                return true;
            }
            if (strcasecmp(
                DepartmentProgrammeCatalog::normalizeCode($rowCourse),
                DepartmentProgrammeCatalog::normalizeCode($target)
            ) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function studInfoFieldValue(array $row, string $field): string
    {
        if ($field === 'stud_branch') {
            return trim((string) (
                $row['stud_branch']
                ?? $row['branch_name']
                ?? $row['branchName']
                ?? $row['branch']
                ?? ''
            ));
        }

        return trim((string) ($row['stud_class'] ?? ''));
    }

    /**
     * Distinct stud_class labels from getStudInfo4Placement (or compatible list payloads).
     *
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return list<string>
     */
    private function normalizeStudClassBatchLabels(array $result, string $programmeCode = '', string $branchFilter = ''): array
    {
        return $this->normalizeStudInfoFieldLabels($result, 'stud_class', $programmeCode, $branchFilter);
    }

    /**
     * @param array{success?:bool,status?:int,data?:mixed,raw?:string,error?:string,note?:string} $result
     * @return list<string>
     */
    private function normalizePlacementClassBatchLabels(array $result, string $programmeCode = '', string $branchFilter = ''): array
    {
        return $this->normalizeStudClassBatchLabels($result, $programmeCode, $branchFilter);
    }

    /**
     * @param list<string> $out
     */
    private function collectPlacementClassLabels(mixed $node, array &$out, string $programmeCode): void
    {
        $this->collectStudInfoFieldLabels($node, $out, 'stud_class', $programmeCode, '');
    }

    /**
     * @param list<string> $out
     */
    private function collectStudClassBatchLabels(mixed $node, array &$out, string $programmeCode, string $branchFilter = ''): void
    {
        $this->collectStudInfoFieldLabels($node, $out, 'stud_class', $programmeCode, $branchFilter);
    }

    private function tagBatchLabelWithProgramme(string $label, string $programmeCode): string
    {
        $programmeCode = DepartmentProgrammeCatalog::resolveProgrammeCode($programmeCode);
        if ($programmeCode === '') {
            return $label;
        }

        $upper = strtoupper($label);
        foreach (DepartmentProgrammeCatalog::programmeCodesForGroup(
            DepartmentProgrammeCatalog::findGroupByProgramme($programmeCode)
            ?? ['parent' => '', 'programmes' => [['code' => $programmeCode, 'label' => $programmeCode, 'aliases' => []]]]
        ) as $prefix) {
            if ($prefix !== '' && str_contains($upper, $prefix)) {
                return $label;
            }
        }

        if (preg_match('/^\d{4}/', trim($label)) === 1 || preg_match('/\d{4}\s*[-–/]\s*\d{2,4}/', $label) === 1) {
            return $programmeCode . preg_replace('/\s+/', '', $label);
        }

        return $label;
    }
}
