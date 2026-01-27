<?php

namespace EasyAudit\Tests\Service;

use EasyAudit\Service\CiEnvironmentDetector;
use PHPUnit\Framework\TestCase;

class CiEnvironmentDetectorTest extends TestCase
{
    private array $savedEnvVars = [];

    private const CI_ENV_VARS = [
        'GITHUB_ACTIONS', 'GITHUB_REPOSITORY', 'GITHUB_WORKFLOW', 'GITHUB_RUN_ID',
        'GITLAB_CI', 'CI_PROJECT_PATH', 'CI_PIPELINE_ID', 'CI_JOB_ID',
        'BITBUCKET_PIPELINE_UUID', 'BITBUCKET_REPO_FULL_NAME',
        'TF_BUILD', 'BUILD_REPOSITORY_NAME', 'BUILD_BUILDID',
        'CIRCLECI', 'CIRCLE_PROJECT_REPONAME', 'CIRCLE_WORKFLOW_ID',
        'JENKINS_URL', 'JOB_NAME', 'BUILD_ID',
        'TRAVIS', 'TRAVIS_REPO_SLUG', 'TRAVIS_BUILD_ID',
    ];

    protected function setUp(): void
    {
        // Save current env vars
        foreach (self::CI_ENV_VARS as $var) {
            $value = getenv($var);
            $this->savedEnvVars[$var] = $value === false ? null : $value;
        }
        // Clear all CI env vars for clean test state
        $this->clearAllCiEnvVars();
    }

    protected function tearDown(): void
    {
        // Restore original env vars
        foreach (self::CI_ENV_VARS as $var) {
            if ($this->savedEnvVars[$var] === null) {
                putenv($var);  // unset
            } else {
                putenv("$var={$this->savedEnvVars[$var]}");
            }
        }
    }

    private function clearAllCiEnvVars(): void
    {
        foreach (self::CI_ENV_VARS as $var) {
            putenv($var);  // unset
        }
    }

    public function testNotRunningInCiWhenNoEnvVars(): void
    {
        $detector = new CiEnvironmentDetector();

        $this->assertFalse($detector->isRunningInCi());
        $this->assertNull($detector->getProvider());
        $this->assertNull($detector->getIdentity());
        $this->assertEmpty($detector->getHeaders());
    }

    public function testDetectsGitHubActions(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_REPOSITORY=crealoz/easyaudit-cli');
        putenv('GITHUB_WORKFLOW=CI');
        putenv('GITHUB_RUN_ID=12345');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('github', $detector->getProvider());
        $this->assertEquals('crealoz/easyaudit-cli/CI/12345', $detector->getIdentity());
    }

    public function testDetectsGitLabCi(): void
    {
        putenv('GITLAB_CI=true');
        putenv('CI_PROJECT_PATH=crealoz/easyaudit');
        putenv('CI_PIPELINE_ID=67890');
        putenv('CI_JOB_ID=111');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('gitlab', $detector->getProvider());
        $this->assertEquals('crealoz/easyaudit/67890/111', $detector->getIdentity());
    }

    public function testDetectsBitbucket(): void
    {
        putenv('BITBUCKET_PIPELINE_UUID={uuid-1234}');
        putenv('BITBUCKET_REPO_FULL_NAME=crealoz/easyaudit');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('bitbucket', $detector->getProvider());
        $this->assertEquals('crealoz/easyaudit/{uuid-1234}', $detector->getIdentity());
    }

    public function testDetectsAzureDevOps(): void
    {
        putenv('TF_BUILD=True');
        putenv('BUILD_REPOSITORY_NAME=easyaudit');
        putenv('BUILD_BUILDID=555');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('azure', $detector->getProvider());
        $this->assertEquals('easyaudit/555', $detector->getIdentity());
    }

    public function testDetectsCircleCi(): void
    {
        putenv('CIRCLECI=true');
        putenv('CIRCLE_PROJECT_REPONAME=easyaudit-cli');
        putenv('CIRCLE_WORKFLOW_ID=workflow-abc');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('circleci', $detector->getProvider());
        $this->assertEquals('easyaudit-cli/workflow-abc', $detector->getIdentity());
    }

    public function testDetectsJenkins(): void
    {
        putenv('JENKINS_URL=https://jenkins.example.com/');
        putenv('JOB_NAME=my-job');
        putenv('BUILD_ID=999');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('jenkins', $detector->getProvider());
        $this->assertEquals('my-job/999', $detector->getIdentity());
    }

    public function testDetectsTravis(): void
    {
        putenv('TRAVIS=true');
        putenv('TRAVIS_REPO_SLUG=crealoz/easyaudit-cli');
        putenv('TRAVIS_BUILD_ID=777');

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('travis', $detector->getProvider());
        $this->assertEquals('crealoz/easyaudit-cli/777', $detector->getIdentity());
    }

    public function testGetIdentityFormatsCorrectly(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_REPOSITORY=owner/repo');
        putenv('GITHUB_WORKFLOW=test-workflow');
        putenv('GITHUB_RUN_ID=42');

        $detector = new CiEnvironmentDetector();
        $identity = $detector->getIdentity();

        $this->assertStringContainsString('owner/repo', $identity);
        $this->assertStringContainsString('test-workflow', $identity);
        $this->assertStringContainsString('42', $identity);
        $this->assertEquals('owner/repo/test-workflow/42', $identity);
    }

    public function testGetHeadersReturnsCorrectFormat(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_REPOSITORY=crealoz/test');
        putenv('GITHUB_WORKFLOW=CI');
        putenv('GITHUB_RUN_ID=100');

        $detector = new CiEnvironmentDetector();
        $headers = $detector->getHeaders();

        $this->assertCount(2, $headers);
        $this->assertEquals('X-CI-Provider: github', $headers[0]);
        $this->assertEquals('X-CI-Identity: crealoz/test/CI/100', $headers[1]);
    }

    public function testPartialIdentityWhenEnvVarsMissing(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_REPOSITORY=crealoz/easyaudit');
        // GITHUB_WORKFLOW and GITHUB_RUN_ID are not set

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('github', $detector->getProvider());
        $this->assertEquals('crealoz/easyaudit', $detector->getIdentity());
    }

    public function testIdentityIsNullWhenAllIdentityEnvVarsMissing(): void
    {
        putenv('GITHUB_ACTIONS=true');
        // No identity env vars set (GITHUB_REPOSITORY, GITHUB_WORKFLOW, GITHUB_RUN_ID)

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('github', $detector->getProvider());
        $this->assertNull($detector->getIdentity());
    }

    public function testHeadersWithoutIdentity(): void
    {
        putenv('GITHUB_ACTIONS=true');
        // No identity env vars

        $detector = new CiEnvironmentDetector();
        $headers = $detector->getHeaders();

        $this->assertCount(1, $headers);
        $this->assertEquals('X-CI-Provider: github', $headers[0]);
    }

    public function testGitHubActionsNotDetectedWithWrongValue(): void
    {
        putenv('GITHUB_ACTIONS=false');

        $detector = new CiEnvironmentDetector();

        $this->assertFalse($detector->isRunningInCi());
        $this->assertNull($detector->getProvider());
    }

    public function testAzureDevOpsRequiresTrueWithCapitalT(): void
    {
        putenv('TF_BUILD=true');  // lowercase, should not match

        $detector = new CiEnvironmentDetector();

        $this->assertFalse($detector->isRunningInCi());
        $this->assertNull($detector->getProvider());
    }

    public function testAzureDevOpsDetectedWithCapitalTrue(): void
    {
        putenv('TF_BUILD=True');  // Capital T as Azure sets it

        $detector = new CiEnvironmentDetector();

        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('azure', $detector->getProvider());
    }

    public function testFirstMatchingProviderWins(): void
    {
        // Set multiple CI environment variables
        putenv('GITHUB_ACTIONS=true');
        putenv('GITLAB_CI=true');

        $detector = new CiEnvironmentDetector();

        // GitHub is first in the PROVIDERS array, so it should win
        $this->assertTrue($detector->isRunningInCi());
        $this->assertEquals('github', $detector->getProvider());
    }

    public function testEmptyEnvVarsAreIgnored(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_REPOSITORY=');  // Empty value
        putenv('GITHUB_WORKFLOW=my-workflow');
        putenv('GITHUB_RUN_ID=');  // Empty value

        $detector = new CiEnvironmentDetector();

        // Empty values should be excluded from identity
        $this->assertEquals('my-workflow', $detector->getIdentity());
    }
}
