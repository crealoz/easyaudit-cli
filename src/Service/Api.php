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
     * Request a pull request from the API.
     * @param array $files
     * @param string $type
     * @return string The diff as a string.
     * @throws RuntimeException
     * @throws GitHubAuthException
     */
    public function requestPR(array $files, string $type): string
    {
        $this->authHeader = Env::getAuthHeader();

        $entryPoint = 'api/instant-pr';
        if ($type === '' || empty($files)) {
            throw new RuntimeException('Invalid input: "type" must be non-empty and "files" must not be empty.');
        }

        $body = [
            'type'       => $type,
            'patch_type' => 'git',
            'files'      => $files,
        ];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode request body.');
        }

        $ch = curl_init(Env::getApiUrl().$entryPoint);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->authHeader,
            'User-Agent: easyaudit-cli-api-client/1.0',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 60,
            CURLOPT_SSL_VERIFYPEER  => !$this->selfSigned,
            CURLOPT_SSL_VERIFYHOST  => $this->selfSigned ? 0 : 2,
        ]);

        echo BLUE . "calling API at " . Env::getApiUrl().$entryPoint . RESET . "\n";

        $data = $this->manageResponse($ch);

        if (!isset($data['diff']) || !is_string($data['diff'])) {
            echo RED . "The response from the API did not contain a valid 'diff' field." . RESET . "\n";
            echo "Response data: " . print_r($data, true) . "\n";

            throw new RuntimeException('Invalid response structure from API.');
        }

        return $data['diff'];
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
        echo "EasyAudit will try to fix this types : " . implode(', ', $types) . "\n";

        return $data['types'];
    }

    private function manageResponse($ch): array
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
        if (($data['status'] ?? '') !== 'success') {
            $msg = (string) ($data['message'] ?? 'API error');
            throw new RuntimeException($msg);
        }
        return $data;
    }
}