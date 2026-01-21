<?php

namespace EasyAudit\Tests\Support;

use EasyAudit\Exception\EnvAuthException;
use EasyAudit\Exception\GitHubAuthException;
use EasyAudit\Support\Env;
use EasyAudit\Support\Paths;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    private string $tempConfigDir;
    private ?string $originalXdgHome = null;
    private ?string $originalGithubActions = null;
    private ?string $originalEasyauditAuth = null;

    protected function setUp(): void
    {
        $this->tempConfigDir = sys_get_temp_dir() . '/easyaudit_env_test_' . uniqid();
        mkdir($this->tempConfigDir, 0777, true);

        // Save original env values
        $this->originalXdgHome = getenv('XDG_CONFIG_HOME') ?: null;
        $this->originalGithubActions = getenv('GITHUB_ACTIONS') ?: null;
        $this->originalEasyauditAuth = getenv('EASYAUDIT_AUTH') ?: null;

        // Set test config dir
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        putenv('GITHUB_ACTIONS=false');
        putenv('EASYAUDIT_AUTH');
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $easyauditDir = $this->tempConfigDir . '/easyaudit';
        if (is_dir($easyauditDir)) {
            $files = glob($easyauditDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($easyauditDir);
        }
        @rmdir($this->tempConfigDir);

        // Restore original env values
        if ($this->originalXdgHome !== null) {
            putenv('XDG_CONFIG_HOME=' . $this->originalXdgHome);
        } else {
            putenv('XDG_CONFIG_HOME');
        }
        if ($this->originalGithubActions !== null) {
            putenv('GITHUB_ACTIONS=' . $this->originalGithubActions);
        } else {
            putenv('GITHUB_ACTIONS');
        }
        if ($this->originalEasyauditAuth !== null) {
            putenv('EASYAUDIT_AUTH=' . $this->originalEasyauditAuth);
        } else {
            putenv('EASYAUDIT_AUTH');
        }
    }

    public function testGetAuthHeaderUsesStoredCredentials(): void
    {
        putenv('GITHUB_ACTIONS=false');
        Paths::configDir();
        Paths::updateConfigFile([
            'key' => 'test-key',
            'hash' => 'test-hash',
        ]);

        $result = Env::getAuthHeader();

        $this->assertEquals('Bearer test-key:test-hash', $result);
    }

    public function testGetAuthHeaderThrowsWhenMissingCredentials(): void
    {
        putenv('GITHUB_ACTIONS=false');
        Paths::configDir();
        // Don't create config file

        $this->expectException(EnvAuthException::class);

        ob_start();
        try {
            Env::getAuthHeader();
        } finally {
            ob_end_clean();
        }
    }

    public function testGetAuthHeaderUsesGitHubEnvVar(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH=github-key:github-hash');

        $result = Env::getAuthHeader();

        $this->assertEquals('Bearer github-key:github-hash', $result);
    }

    public function testGetAuthHeaderAddsBearerPrefixForGitHub(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH=key:hash');  // Without Bearer prefix

        $result = Env::getAuthHeader();

        $this->assertEquals('Bearer key:hash', $result);
    }

    public function testGetAuthHeaderDoesNotDuplicateBearerPrefix(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH=Bearer key:hash');  // Already has Bearer prefix

        $result = Env::getAuthHeader();

        $this->assertEquals('Bearer key:hash', $result);
        $this->assertStringNotContainsString('Bearer Bearer', $result);
    }

    public function testGetAuthHeaderThrowsWhenGitHubEnvVarMissing(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH');  // Unset

        $this->expectException(GitHubAuthException::class);
        $this->expectExceptionMessage('EASYAUDIT_AUTH environment variable is not set or empty');

        Env::getAuthHeader();
    }

    public function testGetAuthHeaderThrowsWhenGitHubEnvVarEmpty(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH=');  // Empty

        $this->expectException(GitHubAuthException::class);

        Env::getAuthHeader();
    }

    public function testGetAuthHeaderThrowsWhenGitHubEnvVarMalformed(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('EASYAUDIT_AUTH=no-colon-separator');  // Missing colon

        $this->expectException(GitHubAuthException::class);
        $this->expectExceptionMessage('malformed');

        Env::getAuthHeader();
    }

    public function testStoreCredentialsWritesToConfigFile(): void
    {
        Paths::configDir();

        Env::storeCredentials('my-key', 'my-hash');

        $configFile = Paths::configFile();
        $content = json_decode(file_get_contents($configFile), true);

        $this->assertEquals('my-key', $content['key']);
        $this->assertEquals('my-hash', $content['hash']);
    }

    public function testGetStoredCredentialsReturnsKeyHash(): void
    {
        Paths::configDir();
        Paths::updateConfigFile([
            'key' => 'stored-key',
            'hash' => 'stored-hash',
        ]);

        $result = Env::getStoredCredentials();

        $this->assertEquals('stored-key:stored-hash', $result);
    }

    public function testGetStoredCredentialsReturnsNullWhenFileMissing(): void
    {
        Paths::configDir();
        // Don't create config file

        ob_start();
        $result = Env::getStoredCredentials();
        ob_end_clean();

        $this->assertNull($result);
    }

    public function testGetStoredCredentialsReturnsNullWhenMalformed(): void
    {
        Paths::configDir();
        // Write invalid JSON
        file_put_contents(Paths::configDir() . '/config.json', 'not valid json');

        ob_start();
        $result = Env::getStoredCredentials();
        ob_end_clean();

        $this->assertNull($result);
    }

    public function testGetStoredCredentialsReturnsNullWhenMissingKeys(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['other' => 'data']);  // Missing key and hash

        ob_start();
        $result = Env::getStoredCredentials();
        ob_end_clean();

        $this->assertNull($result);
    }

    public function testIsSelfSignedReturnsFalseByDefault(): void
    {
        Paths::configDir();
        // No config for use-self-signed

        $result = Env::isSelfSigned();

        $this->assertFalse($result);
    }

    public function testIsSelfSignedReturnsTrueWhenConfigured(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['use-self-signed' => 'true']);

        $result = Env::isSelfSigned();

        $this->assertTrue($result);
    }

    public function testIsSelfSignedReturnsFalseWhenConfiguredFalse(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['use-self-signed' => 'false']);

        $result = Env::isSelfSigned();

        $this->assertFalse($result);
    }

    public function testIsGithubActionsReturnsTrueWhenSet(): void
    {
        putenv('GITHUB_ACTIONS=true');

        $result = Env::isGithubActions();

        $this->assertTrue($result);
    }

    public function testIsGithubActionsReturnsFalseWhenNotSet(): void
    {
        putenv('GITHUB_ACTIONS');  // Unset

        $result = Env::isGithubActions();

        $this->assertFalse($result);
    }

    public function testIsGithubActionsReturnsFalseWhenSetToFalse(): void
    {
        putenv('GITHUB_ACTIONS=false');

        $result = Env::isGithubActions();

        $this->assertFalse($result);
    }

    public function testGetApiUrlReturnsDefault(): void
    {
        Paths::configDir();
        // No custom api-url configured

        $result = Env::getApiUrl();

        $this->assertEquals('https://api.crealoz.fr:8443/', $result);
    }

    public function testGetApiUrlReturnsCustomUrl(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['api-url' => 'https://custom.api.com']);

        $result = Env::getApiUrl();

        $this->assertEquals('https://custom.api.com/', $result);
    }

    public function testGetApiUrlAppendsTrailingSlash(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['api-url' => 'https://custom.api.com/path']);

        $result = Env::getApiUrl();

        $this->assertStringEndsWith('/', $result);
    }

    public function testGetApiUrlNormalizesMultipleSlashes(): void
    {
        Paths::configDir();
        Paths::updateConfigFile(['api-url' => 'https://custom.api.com///']);

        $result = Env::getApiUrl();

        $this->assertEquals('https://custom.api.com/', $result);
    }
}
