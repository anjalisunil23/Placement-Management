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

    /** @var list<array{code:string,name:string,short:string}>|null */
    private static ?array $departmentCache = null;

    public function __construct()
    {
        $aes = require dirname(__DIR__) . '/config/aes.php';
        $this->apiUrl = rtrim((string) ($aes['api_url'] ?? 'https://api.aesajce.in/'), '/') . '/';
        $this->origin = (string) ($aes['api_origin'] ?? 'https://www.aesajce.in');
        $this->referer = (string) ($aes['api_referer'] ?? 'https://www.aesajce.in/');
        $this->authKey = (string) ($aes['auth_key'] ?? '');
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
        $postData = array_merge(['method' => $method], $this->stringifyParams($params));
        if ($this->authKey !== '' && !isset($postData['authkey'])) {
            $postData['authkey'] = $this->authKey;
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'Could not initialize cURL.',
            ];
        }

        curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Accept: application/json, */*;q=0.1',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $this->origin,
            'Referer: ' . $this->referer,
            'X-Requested-With: XMLHttpRequest',
        ], $extraHeaders));

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

        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => true,
                'status'  => $statusCode,
                'raw'     => (string) $response,
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
     * @param array<string, scalar|null> $params
     * @return array<string, scalar|null>
     */
    public function buildStudentRequestParams(array $params, string $registerNumber = ''): array
    {
        $register = strtoupper(trim($registerNumber !== ''
            ? $registerNumber
            : (string) ($params['registerNumber'] ?? $params['admission_no'] ?? $params['un'] ?? $params['username'] ?? '')));

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
     * POST getStudInfo4Placement with admno-first params (AES expects admno for numeric IDs).
     *
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function postStudInfo4Placement(array $params, string $registerNumber = ''): array
    {
        $register = strtoupper(trim($registerNumber !== ''
            ? $registerNumber
            : (string) ($params['admno'] ?? $params['registerNumber'] ?? $params['admission_no'] ?? $params['un'] ?? $params['username'] ?? '')));

        if ($register !== '') {
            foreach ([
                ['admno' => $register],
                ['un' => $register],
                ['username' => $register],
                ['admission_no' => $register],
                ['registerNumber' => $register],
                ['studno' => $register],
            ] as $attempt) {
                $response = $this->getStudInfo4Placement($attempt);
                if ($this->extractRecord($response) !== []) {
                    return $response;
                }
            }
        }

        return $this->getStudInfo4Placement($this->buildStudentRequestParams($params, $register));
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
            $admno = strtoupper(trim((string) $record['stud_admno']));
            $record['registerNumber'] = $admno;
            $record['admission_no'] = $admno;
            $record['admno'] = $admno;
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
        if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            $record['stud_photo'] = $photoUrl;
            $record['photoUrl'] = $photoUrl;
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
            'sslc_percentage', 'sslcPercent', 'sslc_percent', 'stud_sslc', 'stud_sslc_marks', 'stud_sslc_percent',
            'tenth_marks', 'tenth_percentage', 'tenthPercent', 'percent_10', 'percent10',
            '10th_marks', '10th_percentage', 'mark_10', 'mark10', 'stud_10th', 'stud_10th_marks',
        ]);
        if ($marks10 !== null) {
            $record['marks10th'] = $marks10;
        }
        $marks12 = $this->pickMarkPercentFromRecord($record, [
            'marks12th', 'marks_12th', 'mark12th', 'mark_12th', 'hsc', 'hsc_marks', 'hscMarks',
            'hsc_percentage', 'hscPercent', 'hsc_percent', 'stud_hsc', 'stud_hsc_marks', 'stud_hsc_percent',
            'twelfth_marks', 'twelfth_percentage', 'twelfthPercent', 'percent_12', 'percent12',
            '12th_marks', '12th_percentage', 'mark_12', 'mark12', 'stud_12th', 'stud_12th_marks',
            'plus2', 'plus_two', 'plus2_marks', 'plus2_percentage', 'plus_two_marks',
            'ug_marks', 'ugMarks', 'ug_percent', 'ug_percentage',
        ]);
        if ($marks12 !== null) {
            $record['marks12th'] = $marks12;
            if (!isset($record['ugMarks']) || (float) $record['ugMarks'] <= 0) {
                $record['ugMarks'] = $marks12;
            }
        }

        return $record;
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
