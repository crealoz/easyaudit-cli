<?php

namespace EasyAudit\Tests\Console\Command;

use EasyAudit\Console\Command\ActivateSelfSigned;
use PHPUnit\Framework\TestCase;

class ActivateSelfSignedTest extends TestCase
{
    public function testGetDescriptionReturnsNonEmptyString(): void
    {
        $cmd = new ActivateSelfSigned();
        $this->assertNotEmpty($cmd->getDescription());
        $this->assertStringContainsString('self-signed', $cmd->getDescription());
    }

    public function testGetSynopsisContainsActivateSelfSigned(): void
    {
        $cmd = new ActivateSelfSigned();
        $this->assertStringContainsString('activate-self-signed', $cmd->getSynopsis());
    }

    public function testGetHelpContainsOptions(): void
    {
        $cmd = new ActivateSelfSigned();
        $help = $cmd->getHelp();
        $this->assertStringContainsString('self-signed', $help);
        $this->assertStringContainsString('--help', $help);
    }

    public function testRunWithHelpFlagReturns0(): void
    {
        $cmd = new ActivateSelfSigned();

        // fwrite(STDOUT) bypasses output buffering
        $this->expectOutputRegex('/.*/s');
        $result = $cmd->run(['--help']);

        $this->assertSame(0, $result);
    }
}
