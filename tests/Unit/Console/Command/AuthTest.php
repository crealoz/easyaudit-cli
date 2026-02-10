<?php

namespace EasyAudit\Tests\Console\Command;

use EasyAudit\Console\Command\Auth;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private string $originalXdg;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->originalXdg = getenv('XDG_CONFIG_HOME') ?: '';
        $this->tmpDir = sys_get_temp_dir() . '/easyaudit-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        putenv('XDG_CONFIG_HOME=' . $this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Restore original env
        if ($this->originalXdg === '') {
            putenv('XDG_CONFIG_HOME');
        } else {
            putenv('XDG_CONFIG_HOME=' . $this->originalXdg);
        }

        // Clean up temp dir
        $files = glob($this->tmpDir . '/easyaudit/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tmpDir . '/easyaudit');
        @rmdir($this->tmpDir);
    }

    public function testGetDescriptionReturnsNonEmptyString(): void
    {
        $cmd = new Auth();
        $this->assertNotEmpty($cmd->getDescription());
        $this->assertStringContainsString('Authenticate', $cmd->getDescription());
    }

    public function testGetSynopsisContainsAuth(): void
    {
        $cmd = new Auth();
        $this->assertStringContainsString('auth', $cmd->getSynopsis());
    }

    public function testGetHelpContainsOptions(): void
    {
        $cmd = new Auth();
        $help = $cmd->getHelp();
        $this->assertStringContainsString('--key', $help);
        $this->assertStringContainsString('--hash', $help);
        $this->assertStringContainsString('config.json', $help);
    }

    public function testRunWithHelpFlagReturns0(): void
    {
        $cmd = new Auth();

        // fwrite(STDOUT) bypasses output buffering
        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['--help']);

        $this->assertSame(0, $result);
    }

    public function testRunWithKeyAndHash(): void
    {
        $cmd = new Auth();

        // fwrite(STDOUT, "Auth saved.\n") bypasses output buffering
        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['--key=testkey123', '--hash=testhash456']);

        $this->assertSame(0, $result);

        // Verify credentials were written to the temp config, not the real one
        $configFile = $this->tmpDir . '/easyaudit/config.json';
        $this->assertFileExists($configFile);
        $data = json_decode(file_get_contents($configFile), true);
        $this->assertSame('testkey123', $data['key']);
        $this->assertSame('testhash456', $data['hash']);
    }
}