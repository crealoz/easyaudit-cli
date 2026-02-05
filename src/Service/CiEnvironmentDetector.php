<?php

namespace EasyAudit\Service;

/**
 * Detects CI/CD environment and provides identity information for API headers.
 *
 * Supports: GitHub Actions, GitLab CI, Bitbucket Pipelines, Azure DevOps,
 * CircleCI, Jenkins, Travis CI.
 */
class CiEnvironmentDetector
{
    private const PROVIDERS = [
        'github' => [
            'detect' => 'GITHUB_ACTIONS',
            'detectValue' => 'true',
            'identity' => ['GITHUB_REPOSITORY', 'GITHUB_WORKFLOW', 'GITHUB_RUN_ID'],
        ],
        'gitlab' => [
            'detect' => 'GITLAB_CI',
            'detectValue' => 'true',
            'identity' => ['CI_PROJECT_PATH', 'CI_PIPELINE_ID', 'CI_JOB_ID'],
        ],
        'bitbucket' => [
            'detect' => 'BITBUCKET_PIPELINE_UUID',
            'detectValue' => null, // exists check only
            'identity' => ['BITBUCKET_REPO_FULL_NAME', 'BITBUCKET_PIPELINE_UUID'],
        ],
        'azure' => [
            'detect' => 'TF_BUILD',
            'detectValue' => 'True',
            'identity' => ['BUILD_REPOSITORY_NAME', 'BUILD_BUILDID'],
        ],
        'circleci' => [
            'detect' => 'CIRCLECI',
            'detectValue' => 'true',
            'identity' => ['CIRCLE_PROJECT_REPONAME', 'CIRCLE_WORKFLOW_ID'],
        ],
        'jenkins' => [
            'detect' => 'JENKINS_URL',
            'detectValue' => null, // exists check only
            'identity' => ['JOB_NAME', 'BUILD_ID'],
        ],
        'travis' => [
            'detect' => 'TRAVIS',
            'detectValue' => 'true',
            'identity' => ['TRAVIS_REPO_SLUG', 'TRAVIS_BUILD_ID'],
        ],
    ];

    private ?string $detectedProvider = null;
    private bool $detected = false;

    public function __construct()
    {
        $this->detect();
    }

    /**
     * Check if running in a CI/CD environment.
     */
    public function isRunningInCi(): bool
    {
        return $this->detected;
    }

    /**
     * Get the detected CI provider name.
     *
     * @return string|null Provider name (github, gitlab, etc.) or null if not in CI
     */
    public function getProvider(): ?string
    {
        return $this->detectedProvider;
    }

    /**
     * Get the identity string for the current CI run.
     *
     * Format varies by provider but typically includes repo/workflow/run info.
     *
     * @return string|null Identity string or null if not in CI
     */
    public function getIdentity(): ?string
    {
        if ($this->detectedProvider === null) {
            return null;
        }

        $config = self::PROVIDERS[$this->detectedProvider];
        $parts = [];

        foreach ($config['identity'] as $envVar) {
            $value = $this->getEnv($envVar);
            if ($value !== null && $value !== '') {
                $parts[] = $value;
            }
        }

        return empty($parts) ? null : implode('/', $parts);
    }

    /**
     * Get headers array for API requests.
     *
     * @return array Headers in format ['X-CI-Provider: value', 'X-CI-Identity: value']
     */
    public function getHeaders(): array
    {
        if (!$this->detected) {
            return [];
        }

        $headers = [];

        if ($this->detectedProvider !== null) {
            $headers[] = 'X-CI-Provider: ' . $this->detectedProvider;
        }

        $identity = $this->getIdentity();
        if ($identity !== null) {
            $headers[] = 'X-CI-Identity: ' . $identity;
        }

        return $headers;
    }

    /**
     * Detect CI environment from environment variables.
     */
    private function detect(): void
    {
        foreach (self::PROVIDERS as $provider => $config) {
            $envValue = $this->getEnv($config['detect']);

            if ($envValue === null) {
                continue;
            }

            // If detectValue is null, we just check existence
            // Otherwise, we check for exact match
            if ($config['detectValue'] === null || $envValue === $config['detectValue']) {
                $this->detected = true;
                $this->detectedProvider = $provider;
                return;
            }
        }
    }

    /**
     * Get environment variable value.
     *
     * @param  string $name Environment variable name
     * @return string|null Value or null if not set
     */
    private function getEnv(string $name): ?string
    {
        $value = getenv($name);
        return $value === false ? null : $value;
    }
}
