<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Formater;
use PHPUnit\Framework\TestCase;

class FormaterTest extends TestCase
{
    public function testFormatErrorReturnsCorrectKeys(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'test message', 'warning');

        $this->assertArrayHasKey('file', $result);
        $this->assertArrayHasKey('startLine', $result);
        $this->assertArrayHasKey('endLine', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('severity', $result);
    }

    public function testFormatErrorSetsCorrectValues(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'test message', 'error');

        $this->assertEquals(10, $result['startLine']);
        $this->assertEquals('test message', $result['message']);
        $this->assertEquals('error', $result['severity']);
    }

    public function testFormatErrorDefaultEndLineEqualsStartLine(): void
    {
        $result = Formater::formatError('/path/to/file.php', 15, 'msg');
        $this->assertEquals(15, $result['endLine']);
    }

    public function testFormatErrorCustomEndLine(): void
    {
        $result = Formater::formatError('/path/to/file.php', 10, 'msg', 'warning', 20);
        $this->assertEquals(20, $result['endLine']);
    }

    public function testFormatErrorDefaultSeverityIsWarning(): void
    {
        $result = Formater::formatError('/path/to/file.php', 1);
        $this->assertEquals('warning', $result['severity']);
    }

    public function testFormatErrorWithMetadata(): void
    {
        $metadata = ['className' => 'MyClass', 'method' => 'execute'];
        $result = Formater::formatError('/path/to/file.php', 5, 'msg', 'error', 0, $metadata);

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
        $result = Formater::formatError('/path/to/file.php', 5, 'msg', 'warning', 0, []);
        $this->assertArrayNotHasKey('metadata', $result);
    }
}
