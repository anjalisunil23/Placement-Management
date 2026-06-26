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
    private function callAESApiWithMethodInQuery(string $method, array $params = []): array
    {
        $queryData = array_merge(['method' => $method], $this->withAesAuthParams($params));
        $query = http_build_query($queryData);
        $url = $this->apiUrl . '?' . $query;
        $body = http_build_query($this->withAesAuthParams($params));

        $response = $this->executeAesCurl($url, $body, 'POST');
        if ($this->extractQualificationRawRecord($response) !== []) {
            return $response;
        }

        return $this->executeAesCurl($url, null, 'GET');
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

        $baseParams = ['admno' => $admno];
        $lastResponse = [
            'success' => false,
            'status'  => 0,
            'error'   => 'getStudQual4Placement returned no qualification data.',
        ];

        $attempts = [
            fn () => $this->callAESApi('getStudQual4Placement', $baseParams),
            fn () => $this->callAESApiWithMethodInQuery('getStudQual4Placement', $baseParams),
            fn () => $this->callAESApiWithMethodInQuery('getStudQual4Placement', ['stud_admno' => $admno]),
        ];

        $infoRecord = $this->extractRecord($this->getStudInfo4Placement($baseParams));
        $registerNo = trim((string) ($infoRecord['registerno'] ?? $infoRecord['registerNumber'] ?? ''));
        if ($registerNo !== '' && $registerNo !== $admno) {
            $attempts[] = fn () => $this->callAESApiWithMethodInQuery(
                'getStudQual4Placement',
                array_merge($baseParams, ['registerno' => $registerNo, 'registerNumber' => $registerNo])
            );
        }

        foreach ($attempts as $attempt) {
            $response = $attempt();
            $lastResponse = $response;
            if ($this->extractQualificationRawRecord($response) !== []) {
                return $response;
            }
        }

        return $lastResponse;
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
            if ($code === $needle || $name === $needle || ($short !== '' && $short === $needle)) {
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
    public function fetchStudentPlacementProfile(array $params): array
    {
        $request = $this->buildStudentRequestParams($params);
        $record = $this->normalizePlacementStudentRecord(
            $this->extractRecord($this->postStudInfo4Placement($params, (string) ($request['admno'] ?? '')))
        );
        if ($record === []) {
            return [];
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
            $record['departmentName'] = $branchInfo['branch'];
            $record['deptName'] = $branchInfo['branch'];
            $record['deptCode'] = $branchInfo['branch'];
        } elseif ($branchInfo['parentCode'] !== '' && $branchInfo['parentName'] !== '') {
            $record['department'] = $branchInfo['parentCode'];
            $record['department_code'] = $branchInfo['parentCode'];
            $record['department_name'] = $branchInfo['parentName'];
            $record['dept_name'] = $branchInfo['parentName'];
            $record['deptName'] = $branchInfo['parentName'];
            $record['deptCode'] = $branchInfo['parentCode'];
        }

        $admno = $this->resolveQualificationAdmissionNumber($record, $this->resolveAdmissionNumber($params, (string) ($request['admno'] ?? '')));
        if ($admno !== '' && ctype_digit($admno)) {
            $qual = $this->fetchStudentQualificationProfile(['admno' => $admno, 'stud_admno' => $admno]);
            if ($qual !== []) {
                $record = $this->mergeQualificationIntoPlacement($record, $qual);
            }
        }

        return $record;
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

        $raw = $this->extractQualificationRawRecord(
            $this->postStudQual4Placement(['admno' => $admno, 'stud_admno' => $admno], $admno)
        );

        return $this->normalizeQualificationRecord($raw);
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
        $qualifications = !empty($normalized['qualifications']) && is_array($normalized['qualifications'])
            ? $normalized['qualifications']
            : $this->parseEducationQualifications($normalized);
        if ($qualifications === []) {
            $qualifications = $this->buildQualificationTableRowsFromMarks($normalized);
        }
        if ($qualifications !== []) {
            $normalized = $this->applySchoolMarksFromQualificationRows($normalized, $qualifications);
            $normalized['qualifications'] = $qualifications;
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
            $current = isset($placement[$key]) && is_numeric($placement[$key]) ? (float) $placement[$key] : 0.0;
            $incoming = isset($qual[$key]) && is_numeric($qual[$key]) ? (float) $qual[$key] : 0.0;
            if ($current <= 0 && $incoming > 0) {
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
            $pct = isset($q['percentage']) && is_numeric($q['percentage']) ? (float) $q['percentage'] : null;
            if ($pct === null || $pct <= 0) {
                continue;
            }
            $label = strtoupper((string) ($q['qualification'] ?? ''));
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
        $branch = strtoupper(trim((string) (
            $record['stud_cource_short']
            ?? $record['stud_course']
            ?? ''
        )));

        if ($parentCode !== '') {
            $record['parentDepartmentCode'] = $parentCode;
            $record['stud_deptcode'] = $parentCode;
        }
        if ($branch !== '') {
            $record['branch'] = $branch;
            $record['programme'] = $branch;
            $record['deptCode'] = $branch;
            $record['department'] = $branch;
            $record['departmentName'] = $branch;
            $record['deptName'] = $branch;
        } elseif ($parentCode !== '') {
            $record['deptCode'] = $parentCode;
            $record['department'] = $parentCode;
        }

        $legacyName = trim((string) (
            $record['dept_name']
            ?? $record['department_name']
            ?? $record['branch_name']
            ?? ''
        ));
        if ($branch === '' && $legacyName !== '') {
            $record['departmentName'] = $legacyName;
            $record['deptName'] = $legacyName;
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
        if (isset($record['cgpa']) && $record['cgpa'] !== '' && is_numeric($record['cgpa'])) {
            $cgpa = (float) $record['cgpa'];
            if ($cgpa > 0) {
                $record['cgpa'] = $cgpa;
            }
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

        $qualifications = $this->parseEducationQualifications($record);
        if ($qualifications !== []) {
            $record['qualifications'] = $qualifications;
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
        $edu = $record['edu'] ?? null;
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
            $institution = trim((string) ($row['instname'] ?? $row['inst_name'] ?? $row['institution'] ?? ''));
            $registerNumber = trim((string) ($row['regno'] ?? $row['reg_no'] ?? $row['registerNumber'] ?? ''));
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
            if ($percentage === null && $mark !== null && $maxMark !== null && $maxMark > 0) {
                $pct = round(($mark / $maxMark) * 100, 2);
                if ($pct > 0 && $pct <= 100) {
                    $percentage = $pct;
                }
            }

            if ($qualification === '' && $institution === '' && $registerNumber === '' && $monthYear === '' && $mark === null && $percentage === null) {
                continue;
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

            $existing = $model->findByCode($code);
            if ($existing !== null) {
                if (trim((string) ($existing['name'] ?? '')) !== $name) {
                    $model->update((string) $existing['_id'], ['name' => $name]);
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
                $numeric = $model->findByCode($aesId);
                if ($numeric !== null) {
                    $model->update((string) $numeric['_id'], ['code' => $code, 'name' => $name]);
                    $changed++;
                    continue;
                }
            }

            $model->createDepartment(['code' => $code, 'name' => $name]);
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

        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            return ['edu' => $payload['data']];
        }

        $qualKeys = [
            'cgpa', 'edu', 'qualifications', 'marks10th', 'marks12th', 'sslc', 'hsc', 'sslc_marks', 'hsc_marks',
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
}
