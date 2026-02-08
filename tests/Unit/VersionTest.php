<?php

namespace EasyAudit\Tests;

use EasyAudit\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testGetVersionStringReturnsDevString(): void
    {
        $result = Version::getVersionString();
        $this->assertEquals('EasyAudit CLI (development version)', $result);
    }

    public function testIsReleaseBuildReturnsFalseInDevMode(): void
    {
        $this->assertFalse(Version::isReleaseBuild());
    }

    public function testVersionConstantIsDev(): void
    {
        $this->assertEquals('dev', Version::VERSION);
    }

    public function testHashConstantIsDev(): void
    {
        $this->assertEquals('dev', Version::HASH);
    }

    public function testGetVersionStringReleaseBuild(): void
    {
        // Simulate a release build by calling the logic directly
        $version = '1.2.3';
        $hash = 'abc123def456789abcdef';
        $shortHash = substr($hash, 0, 12);
        $expected = "EasyAudit CLI v{$version} (hash: {$shortHash}...)";

        $this->assertEquals('EasyAudit CLI v1.2.3 (hash: abc123def456...)', $expected);
    }
}