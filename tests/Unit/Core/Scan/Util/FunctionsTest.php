<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Functions;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    // --- getFunctionContent() tests ---

    public function testGetFunctionContentSingleLineFunction(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public function bar() { return 1; }
}
PHP;
        $result = Functions::getFunctionContent($code, 4);
        $this->assertStringContainsString('return 1', $result['content']);
        $this->assertEquals(4, $result['endLine']);
    }

    public function testGetFunctionContentMultiLineFunction(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public function bar()
    {
        $a = 1;
        $b = 2;
        return $a + $b;
    }
}
PHP;
        $result = Functions::getFunctionContent($code, 4);
        $this->assertStringContainsString('$a = 1', $result['content']);
        $this->assertStringContainsString('return $a + $b', $result['content']);
        $this->assertEquals(9, $result['endLine']);
    }

    public function testGetFunctionContentWithNestedBraces(): void
    {
        $code = <<<'PHP'
<?php
class Foo
{
    public function bar()
    {
        if (true) {
            return 1;
        }
        return 0;
    }
}
PHP;
        $result = Functions::getFunctionContent($code, 4);
        $this->assertStringContainsString('if (true)', $result['content']);
        $this->assertStringContainsString('return 0', $result['content']);
        $this->assertEquals(10, $result['endLine']);
    }

    // --- getFunctionInnerContent() tests ---

    public function testGetFunctionInnerContentBraceOnSameLine(): void
    {
        $code = <<<'PHP'
    public function bar() {
        $a = 1;
        return $a;
    }
PHP;
        $inner = Functions::getFunctionInnerContent($code);
        $this->assertStringContainsString('$a = 1', $inner);
        $this->assertStringContainsString('return $a', $inner);
    }

    public function testGetFunctionInnerContentBraceOnNextLine(): void
    {
        $code = <<<'PHP'
    public function bar()
    {
        $a = 1;
        return $a;
    }
PHP;
        $inner = Functions::getFunctionInnerContent($code);
        $this->assertStringContainsString('$a = 1', $inner);
        $this->assertStringContainsString('return $a', $inner);
    }

    public function testGetFunctionInnerContentOneLiner(): void
    {
        $code = 'public function bar() { return 1; }';
        $inner = Functions::getFunctionInnerContent($code);
        $this->assertEquals('return 1;', $inner);
    }

    // --- getOccuringLineInFunction() tests ---

    public function testGetOccuringLineInFunctionFound(): void
    {
        $code = "line one\nline two\nline three with target\nline four";
        $this->assertEquals(3, Functions::getOccuringLineInFunction($code, 'target'));
    }

    public function testGetOccuringLineInFunctionNotFound(): void
    {
        $code = "line one\nline two";
        $this->assertNull(Functions::getOccuringLineInFunction($code, 'missing'));
    }

    public function testGetOccuringLineInFunctionFindsFirstOccurrence(): void
    {
        $code = "first target\nsecond target";
        $this->assertEquals(1, Functions::getOccuringLineInFunction($code, 'target'));
    }

    // --- extractBraceBlock() tests ---

    public function testExtractBraceBlockReturnsContent(): void
    {
        $code = 'function foo() { return 1; }';
        $result = Functions::extractBraceBlock($code, 0);
        $this->assertStringContainsString('return 1;', $result);
    }

    public function testExtractBraceBlockReturnsNullWhenNoBrace(): void
    {
        $code = 'function foo() return 1;';
        $result = Functions::extractBraceBlock($code, 0);
        $this->assertNull($result);
    }

    public function testExtractBraceBlockReturnsNullWhenUnbalanced(): void
    {
        $code = 'function foo() { if (true) { return 1;';
        $result = Functions::extractBraceBlock($code, 0);
        $this->assertNull($result);
    }

    public function testExtractBraceBlockWithNestedBraces(): void
    {
        $code = 'function foo() { if (true) { return 1; } return 0; }';
        $result = Functions::extractBraceBlock($code, 0);
        $this->assertStringContainsString('if (true)', $result);
        $this->assertStringContainsString('return 0;', $result);
    }
}
