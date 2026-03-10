<?php

namespace EasyAudit\Tests\Core\Scan\Util;

use EasyAudit\Core\Scan\Util\Content;
use PHPUnit\Framework\TestCase;

class ContentTest extends TestCase
{
    public function testGetLineNumberFindsOnFirstLine(): void
    {
        $content = "first line\nsecond line\nthird line";
        $this->assertEquals(1, Content::getLineNumber($content, 'first'));
    }

    public function testGetLineNumberFindsOnMiddleLine(): void
    {
        $content = "first line\nsecond line\nthird line";
        $this->assertEquals(2, Content::getLineNumber($content, 'second'));
    }

    public function testGetLineNumberFindsOnLastLine(): void
    {
        $content = "first line\nsecond line\nthird line";
        $this->assertEquals(3, Content::getLineNumber($content, 'third'));
    }

    public function testGetLineNumberReturnsNegativeOneWhenNotFound(): void
    {
        $content = "first line\nsecond line\nthird line";
        $this->assertEquals(-1, Content::getLineNumber($content, 'missing'));
    }

    public function testGetLineNumberWithEmptyContent(): void
    {
        $this->assertEquals(-1, Content::getLineNumber('', 'anything'));
    }

    public function testGetLineNumberWithAfterLineSkipsEarlierMatches(): void
    {
        $content = "use MyClass;\nother line\n__construct(MyClass \$myClass)\n{\n\$this->myClass = \$myClass;";
        // Without afterLine, finds 'MyClass' on line 1 (use statement)
        $this->assertEquals(1, Content::getLineNumber($content, 'MyClass'));
        // With afterLine=2, skips line 1 and 2, finds 'MyClass' on line 3 (constructor)
        $this->assertEquals(3, Content::getLineNumber($content, 'MyClass', 2));
    }

    public function testGetLineNumberWithAfterLineReturnsNegativeOneWhenNoLaterMatch(): void
    {
        $content = "first line\nsecond line\nthird line";
        $this->assertEquals(-1, Content::getLineNumber($content, 'first', 1));
    }

    public function testGetLineNumberWithAfterLineZeroBehavesLikeDefault(): void
    {
        $content = "first line\nsecond line";
        $this->assertEquals(1, Content::getLineNumber($content, 'first', 0));
    }

    public function testExtractContentSingleLine(): void
    {
        $content = "line 1\nline 2\nline 3\nline 4\nline 5";
        $result = Content::extractContent($content, 3, 3);
        $this->assertEquals('line 3', $result);
    }

    public function testExtractContentRange(): void
    {
        $content = "line 1\nline 2\nline 3\nline 4\nline 5";
        $result = Content::extractContent($content, 2, 4);
        $this->assertEquals("line 2\nline 3\nline 4", $result);
    }

    public function testExtractContentFromBeginning(): void
    {
        $content = "line 1\nline 2\nline 3";
        $result = Content::extractContent($content, 1, 2);
        $this->assertEquals("line 1\nline 2", $result);
    }

    public function testExtractContentToEnd(): void
    {
        $content = "line 1\nline 2\nline 3";
        $result = Content::extractContent($content, 2, 3);
        $this->assertEquals("line 2\nline 3", $result);
    }
}
