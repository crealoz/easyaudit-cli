<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Formater;
use PHPUnit\Framework\TestCase;

class FormaterTest extends TestCase
{
    public function testFormatErrorReturnsCorrectKeys(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'test message', 'medium');

        $this->assertArrayHasKey('file', $result);
        $this->assertArrayHasKey('startLine', $result);
        $this->assertArrayHasKey('endLine', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('severity', $result);
    }

    public function testFormatErrorSetsCorrectValues(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'test message', 'high');

        $this->assertEquals(10, $result['startLine']);
        $this->assertEquals('test message', $result['message']);
        $this->assertEquals('high', $result['severity']);
    }

    public function testFormatErrorDefaultEndLineEqualsStartLine(): void
    {
        $result = Formater::formatError('/path/to/file.php', 15, 'msg');
        $this->assertEquals(15, $result['endLine']);
    }

    public function testFormatErrorCustomEndLine(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'msg', 'medium', 20);
        $this->assertEquals(20, $result['endLine']);
    }

    public function testFormatErrorDefaultSeverityIsMedium(): void
    {
        $result = Formater::formatError('/path/to/file.php', 1);
        $this->assertEquals('medium', $result['severity']);
    }

    public function testFormatErrorWithMetadata(): void
    {
        $metadata = ['className' => 'MyClass', 'method' => 'execute'];
        $result = Formater::formatError('/path/to/file.php', 5, 'msg', 'high', 0, $metadata);

        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('MyClass', $result['metadata']['className']);
        $this->assertEquals('execute', $result['metadata']['method']);
    }

    public function testFormatErrorWithoutMetadataDoesNotIncludeKey(): void
    {
        $result = Formater::formatError('/path/to/file.php', 5, 'msg');
        $this->assertArrayNotHasKey('metadata', $result);
    }

    public function testFormatErrorWithEmptyMetadataDoesNotIncludeKey(): void
    {
        $result = Formater::formatError('/path/to/file.php', 5, 'msg', 'medium', 0, []);
        $this->assertArrayNotHasKey('metadata', $result);
    }
}
