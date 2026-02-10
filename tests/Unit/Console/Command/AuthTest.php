<?php

namespace EasyAudit\Tests\Console\Command;

use EasyAudit\Console\Command\Auth;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
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
    }
}
