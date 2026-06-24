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
     * @param array<string, scalar|null> $params
     * @return array{success:bool,status:int,data?:mixed,raw?:string,error?:string,note?:string}
     */
    public function getStudInfo4Placement(array $params = []): array
    {
        return $this->callAESApi('getStudInfo4Placement', $params);
    }

    /**
     * @return list<array{code:string,name:string}>
     */
    public function listDepartments(): array
    {
        return $this->normalizeDepartmentRows($this->getDepartments());
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>
     */
    public function fetchStudentPlacementProfile(array $params): array
    {
        return $this->normalizePlacementStudentRecord(
            $this->extractRecord($this->getStudInfo4Placement($params))
        );
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

        $code = strtoupper(trim((string) (
            $record['deptCode']
            ?? $record['dept_code']
            ?? $record['department_code']
            ?? $record['branch_code']
            ?? $record['deptshort']
            ?? $record['dept_short']
            ?? $record['dept_shortName']
            ?? $record['br']
            ?? $record['department']
            ?? $record['dept']
            ?? $record['branch']
            ?? ''
        )));
        $name = trim((string) (
            $record['deptName']
            ?? $record['dept_name']
            ?? $record['department_name']
            ?? $record['branch_name']
            ?? $record['departmentName']
            ?? ''
        ));

        if ($code !== '') {
            $record['deptCode'] = $code;
            $record['department'] = $code;
        }
        if ($name !== '') {
            $record['deptName'] = $name;
            $record['departmentName'] = $name;
            if ($code === '') {
                $record['department'] = strtoupper($name);
            }
        }

        return $record;
    }

    /**
     * Pull AES departments into the local departments collection (insert missing codes only).
     */
    public function syncDepartmentsToLocal(): int
    {
        $rows = $this->listDepartments();
        if ($rows === []) {
            return 0;
        }

        $model = new DepartmentModel();
        $created = 0;
        foreach ($rows as $row) {
            $code = strtoupper(trim($row['code']));
            $name = trim($row['name']);
            if ($code === '' || $name === '') {
                continue;
            }
            if ($model->findByCode($code) !== null) {
                continue;
            }
            $model->createDepartment(['code' => $code, 'name' => $name]);
            $created++;
        }

        return $created;
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

        if (($payload['status'] ?? true) === false || ($payload['status'] ?? null) === 'false') {
            return [];
        }

        $record = $payload['data'] ?? $payload['student'] ?? $payload['profile'] ?? $payload['user'] ?? null;
        if (is_array($record) && $record !== []) {
            return $record;
        }

        $scalarKeys = [
            'name', 'student_name', 'email', 'cgpa', 'department', 'dept', 'branch', 'admission_no',
            'deptCode', 'deptName', 'dept_code', 'dept_name', 'deptshort', 'registerNumber',
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
            $code = strtoupper(trim((string) (
                $item['code']
                ?? $item['dept_code']
                ?? $item['deptCode']
                ?? $item['department_code']
                ?? $item['branch_code']
                ?? $item['br']
                ?? ''
            )));
            $name = trim((string) (
                $item['name']
                ?? $item['dept_name']
                ?? $item['deptName']
                ?? $item['department_name']
                ?? $item['department']
                ?? $item['branch_name']
                ?? $item['branch']
                ?? ''
            ));
            if ($code === '' && $name !== '') {
                $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $name) ?? $name);
            }
            if ($code === '' || $name === '') {
                continue;
            }
            $rows[] = ['code' => $code, 'name' => $name];
        }

        return $rows;
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
