<?php

namespace EasyAudit\Tests\Core\Scan;

use EasyAudit\Core\Scan\ExternalToolMapping;
use PHPUnit\Framework\TestCase;

class ExternalToolMappingTest extends TestCase
{
    private const KNOWN_RULE = 'magento.code.useless-object-manager-import';
    private const UNKNOWN_RULE = 'non.existent.rule';

    public function testIsExternallyFixableReturnsTrueForKnownRule(): void
    {
        $this->assertTrue(ExternalToolMapping::isExternallyFixable(self::KNOWN_RULE));
    }

    public function testIsExternallyFixableReturnsFalseForUnknownRule(): void
    {
        $this->assertFalse(ExternalToolMapping::isExternallyFixable(self::UNKNOWN_RULE));
    }

    public function testGetCommandReturnsStringForKnownRule(): void
    {
        $command = ExternalToolMapping::getCommand(self::KNOWN_RULE);
        $this->assertNotNull($command);
        $this->assertStringContainsString('php-cs-fixer', $command);
    }

    public function testGetCommandReturnsNullForUnknownRule(): void
    {
        $this->assertNull(ExternalToolMapping::getCommand(self::UNKNOWN_RULE));
    }

    public function testGetToolReturnsStringForKnownRule(): void
    {
        $tool = ExternalToolMapping::getTool(self::KNOWN_RULE);
        $this->assertEquals('php-cs-fixer', $tool);
    }

    public function testGetToolReturnsNullForUnknownRule(): void
    {
        $this->assertNull(ExternalToolMapping::getTool(self::UNKNOWN_RULE));
    }

    public function testGetDescriptionReturnsStringForKnownRule(): void
    {
        $description = ExternalToolMapping::getDescription(self::KNOWN_RULE);
        $this->assertEquals('unused imports', $description);
    }

    public function testGetDescriptionReturnsNullForUnknownRule(): void
    {
        $this->assertNull(ExternalToolMapping::getDescription(self::UNKNOWN_RULE));
    }

    public function testMappingsConstantIsNotEmpty(): void
    {
        $this->assertNotEmpty(ExternalToolMapping::MAPPINGS);
    }
}
