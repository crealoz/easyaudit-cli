<?php

namespace EasyAudit\Service;

use EasyAudit\Exception\Fixer\CurlResponseException;
use EasyAudit\Exception\GitHubAuthException;
use EasyAudit\Exception\RateLimitedException;
use EasyAudit\Exception\UpgradeRequiredException;
use EasyAudit\Version;
use RuntimeException;

/**
 * Class Api will contain methods to interact with an external API. https://api.crealoz.fr
 *
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
     * @param  string $filePath  Path to the file being fixed
     * @param  string $content   File content
     * @param  array  $rules     Object of {ruleId: metadata}
     * @param  string $projectId Project identifier for grouping requests
     * @return array Response including 'diff' (string), 'status', 'credits_remaining'
     * @throws CurlResponseException
     * @throws GitHubAuthException
     */
    public function requestFilefix(string $filePath, string $content, array $rules, string $projectId): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/instant-pr';

        // API expects files as object keyed by path, even for single file
        $body = [
            'project_id' => $projectId,
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

        $ch = $this->initCurl(
            $entryPoint,
            [
                'Content-Type: application/json'
            ],
            $json,
            120
        );

        $data = $this->manageResponse($ch, allowPartial: true);

        // API returns diffs as object keyed by file path
        if (!isset($data['diffs']) || !is_array($data['diffs'])) {
            throw new CurlResponseException('Invalid response structure from API: missing diffs field.');
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
     * Get remaining credits from the API.
     * Also validates the project_id with middleware.
     *
     * @param  string $projectId Project identifier for validation
     * @return array {credits: int, credit_expiration_date: string, licence_expiration_date: string, project_id: string}
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function getRemainingCredits(string $projectId): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/get-remaining-credit';

        $body = ['project_id' => $projectId];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);

        $ch = $this->initCurl(
            $entryPoint,
            [
                'Content-Type: application/json'
            ],
            $json
        );

        $data = $this->manageResponse($ch);

        return [
            'credits' => $data['credits'] ?? 0,
            'credit_expiration_date' => $data['credit_expiration_date'] ?? null,
            'licence_expiration_date' => $data['licence_expiration_date'] ?? null,
            'project_id' => $data['project_id'] ?? $projectId,
        ];
    }

    /**
     * Fetch the allowed types from the API.
     *
     * @return array
     * @throws CurlResponseException
     * @throws GitHubAuthException
     */
    public function getAllowedType(): array
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/allowed-types';

        $ch = $this->initCurl($entryPoint);

        echo BLUE . "calling API at " . Env::getApiUrl() . $entryPoint . RESET . "\n";

        $data = $this->manageResponse($ch);
        $types = $data['types'] ?? null;
        if (!is_array($types)) {
            throw new CurlResponseException('Invalid response structure from API.');
        }

        echo "EasyAudit can fix these types:\n";
        foreach ($types as $type => $cost) {
            echo "  - $type ($cost credit" . ($cost > 1 ? 's' : '') . ")\n";
        }

        return $types;
    }

    private function initCurl(string $entryPoint, array $additionalHeaders = [], ?string $postFields = null, int $timeout = 30): \CurlHandle
    {
        $ch = curl_init(Env::getApiUrl() . $entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = $this->buildHeaders($additionalHeaders);

        $optArray = [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => !$this->selfSigned,
                CURLOPT_SSL_VERIFYHOST => $this->selfSigned ? 0 : 2,
            ];
        if ($postFields !== null) {
            $optArray[CURLOPT_POSTFIELDS] = $postFields;
        }

        curl_setopt_array(
            $ch,
            $optArray
        );
        return $ch;
    }

    /**
     * Build headers array with version information.
     *
     * @param  array $additionalHeaders Extra headers to include
     * @return array Complete headers array
     */
    private function buildHeaders(array $additionalHeaders = []): array
    {
        $headers = [
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
            'X-CLI-Version: ' . Version::VERSION,
            'X-CLI-Hash: ' . Version::HASH,
        ];

        // Add CI/CD headers if running in CI environment
        $ciDetector = new CiEnvironmentDetector();
        if ($ciDetector->isRunningInCi()) {
            $headers = array_merge($headers, $ciDetector->getHeaders());
        }

        return array_merge($headers, $additionalHeaders);
    }

    /**
     * Handle cURL response and validate status.
     *
     * @param  \CurlHandle $ch           cURL handle
     * @param  bool        $allowPartial When true, accepts "partial" status (insufficient credits)
     * @return array Decoded response data
     * @throws RuntimeException
     * @throws UpgradeRequiredException
     * @throws RateLimitedException
     */
    private function manageResponse($ch, bool $allowPartial = false): array
    {
        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new CurlResponseException('cURL error: ' . ($err !== '' ? $err : 'unknown'));
        }
        curl_close($ch);

        // Handle HTTP 426 Upgrade Required
        if ($httpCode === 426) {
            $data = json_decode($responseBody, true);
            $minVersion = $data['minimum_version'] ?? null;
            $message = $data['message'] ?? '';
            throw new UpgradeRequiredException(Version::VERSION, $minVersion, $message);
        }

        // Handle HTTP 429 Too Many Requests (Rate Limited / Suspended)
        if ($httpCode === 429) {
            $data = json_decode($responseBody, true);
            $retryAfter = isset($data['retry_after']) ? (int) $data['retry_after'] : null;
            $message = $data['message'] ?? '';
            throw new RateLimitedException($retryAfter, $message);
        }

        if ($httpCode !== 200) {
            $map = [
                400 => 'Bad request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not found',
                500 => 'Internal server error',
            ];
            $label = $map[$httpCode] ?? 'Unknown error';
            throw new CurlResponseException("HTTP $httpCode: $label");
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new CurlResponseException('Invalid JSON from API.');
        }

        $status = $data['status'] ?? '';
        $validStatuses = $allowPartial ? ['success', 'partial'] : ['success'];

        if (!in_array($status, $validStatuses, true)) {
            $msg = (string) ($data['message'] ?? 'API error');
            throw new CurlResponseException($msg);
        }

        return $data;
    }
}
