<?php

namespace EasyAudit\Service;

use EasyAudit\Exception\GitHubAuthException;
use EasyAudit\Support\Env;
use RuntimeException;

/**
 * Class Api will contain methods to interact with an external API. https://api.crealoz.fr
 * @package EasyAudit\Service
 */
class Api
{
    private bool $selfSigned = false;
    private ?string $authHeader = null;

    public function __construct()
    {
        $this->selfSigned = Env::isSelfSigned();
    }

    /**
     * Request a fix for a single file from the API.
     *
     * @param string $filePath Path to the file being fixed
     * @param string $content File content
     * @param array $rules Object of {ruleId: metadata}
     * @return array Response including 'diff' (string), 'status', 'credits_remaining'
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function requestFilefix(string $filePath, string $content, array $rules): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/instant-pr';

        // API expects files as object keyed by path, even for single file
        $body = [
            'files'  => [
                $filePath => [
                    'content' => $content,
                    'rules'   => $rules,
                ],
            ],
            'format' => 'git',
        ];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode request body.');
        }

        $ch = curl_init(Env::getApiUrl() . $entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => !$this->selfSigned,
            CURLOPT_SSL_VERIFYHOST => $this->selfSigned ? 0 : 2,
        ]);

        $data = $this->manageResponse($ch, allowPartial: true);

        // API returns diffs as object keyed by file path
        if (!isset($data['diffs']) || !is_array($data['diffs'])) {
            throw new RuntimeException('Invalid response structure from API: missing diffs field.');
        }

        // Extract the diff for the single file we sent
        $diff = $data['diffs'][$filePath] ?? '';

        return [
            'diff' => $diff,
            'status' => $data['status'] ?? 'success',
            'credits_remaining' => $data['credits_remaining'] ?? null,
        ];
    }

    /**
     * Request a di.xml fix to add proxy configurations.
     *
     * @param string $diFilePath Path to the di.xml file
     * @param string $content Current di.xml content
     * @param array $proxies Proxies grouped by type: [type => [['argument' => x, 'proxy' => y], ...]]
     * @return array Response including 'diff' (string), 'status', 'credits_remaining'
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function requestDiFix(string $diFilePath, string $content, array $proxies): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/di-proxy-fix';

        $body = [
            'diFile'  => $diFilePath,
            'content' => $content,
            'proxies' => $proxies,
            'format'  => 'git',
        ];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode request body.');
        }

        $ch = curl_init(Env::getApiUrl() . $entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => !$this->selfSigned,
            CURLOPT_SSL_VERIFYHOST => $this->selfSigned ? 0 : 2,
        ]);

        $data = $this->manageResponse($ch, allowPartial: true);

        return [
            'diff' => $data['diff'] ?? '',
            'status' => $data['status'] ?? 'success',
            'credits_remaining' => $data['credits_remaining'] ?? null,
        ];
    }

    /**
     * Get remaining credits from the API.
     * @return array {credits: int, credit_expiration_date: string, licence_expiration_date: string}
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function getRemainingCredits(): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/get-remaining-credit';

        $ch = curl_init(Env::getApiUrl() . $entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => !$this->selfSigned,
            CURLOPT_SSL_VERIFYHOST => $this->selfSigned ? 0 : 2,
        ]);

        $data = $this->manageResponse($ch);

        return [
            'credits' => $data['credits'] ?? 0,
            'credit_expiration_date' => $data['credit_expiration_date'] ?? null,
            'licence_expiration_date' => $data['licence_expiration_date'] ?? null,
        ];
    }

    /**
     * Fetch the allowed types from the API.
     * @return array
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function getAllowedType(): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/allowed-types';

        $ch = curl_init(Env::getApiUrl().$entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_SSL_VERIFYPEER  => !$this->selfSigned,
            CURLOPT_SSL_VERIFYHOST  => $this->selfSigned ? 0 : 2,
        ]);

        echo BLUE . "calling API at " . Env::getApiUrl().$entryPoint . RESET . "\n";

        $data = $this->manageResponse($ch);
        $types = $data['types'] ?? null;
        if (!is_array($types)) {
            throw new RuntimeException('Invalid response structure from API.');
        }

        echo "EasyAudit can fix these types:\n";
        foreach ($types as $type => $cost) {
            echo "  - $type ($cost credit" . ($cost > 1 ? 's' : '') . ")\n";
        }

        return $types;
    }

    /**
     * Handle cURL response and validate status.
     *
     * @param \CurlHandle $ch cURL handle
     * @param bool $allowPartial When true, accepts "partial" status (insufficient credits)
     * @return array Decoded response data
     * @throws RuntimeException
     */
    private function manageResponse($ch, bool $allowPartial = false): array
    {
        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . ($err !== '' ? $err : 'unknown'));
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            $map = [
                400 => 'Bad request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not found',
                500 => 'Internal server error',
            ];
            $label = $map[$httpCode] ?? 'Unknown error';
            throw new RuntimeException("HTTP $httpCode: $label");
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from API.');
        }

        $status = $data['status'] ?? '';
        $validStatuses = $allowPartial ? ['success', 'partial'] : ['success'];

        if (!in_array($status, $validStatuses, true)) {
            $msg = (string) ($data['message'] ?? 'API error');
            throw new RuntimeException($msg);
        }

        return $data;
    }
}