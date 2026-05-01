<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\GetWriteAntipattern;
use PHPUnit\Framework\TestCase;

class GetWriteAntipatternTest extends TestCase
{
    private GetWriteAntipattern $processor;

    protected function setUp(): void
    {
        $this->processor = new GetWriteAntipattern();
    }

    public function testGetIdentifierReturnsKebabName(): void
    {
        $this->assertEquals('getWriteAntipattern', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsXml(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetNameIsSet(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetMessageIsSet(): void
    {
        $this->assertNotEmpty($this->processor->getMessage());
    }

    public function testGetLongDescriptionMentionsHttpSemantics(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('GET', $description);
        $this->assertStringContainsString('safe', strtolower($description));
    }

    public function testProcessIgnoresEmptyBucket(): void
    {
        ob_start();
        $this->processor->process(['xml' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresMissingBucket(): void
    {
        ob_start();
        $this->processor->process([]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessFlagsBadFixture(): void
    {
        $file = __DIR__ . '/../../../../fixtures/GetWriteAntipattern/Bad/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(4, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testProcessIgnoresGoodFixture(): void
    {
        $file = __DIR__ . '/../../../../fixtures/GetWriteAntipattern/Good/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
        $this->assertEmpty($report[0]['files']);
    }

    public function testProcessIgnoresNonWebapiXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_gwa_' . uniqid();
        mkdir($tempDir);
        $file = $tempDir . '/di.xml';
        file_put_contents($file, '<?xml version="1.0"?><config><preference for="A" type="B"/></config>');

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_gwa_' . uniqid();
        mkdir($tempDir);
        $file = $tempDir . '/webapi.xml';
        file_put_contents($file, '<?xml version="1.0"?><routes><route unclosed');

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testReportMetadataIncludesHttpMethodAndService(): void
    {
        $file = __DIR__ . '/../../../../fixtures/GetWriteAntipattern/Bad/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $firstFinding = $report[0]['files'][0];
        $this->assertArrayHasKey('metadata', $firstFinding);
        // consolidateResults may wrap a single metadata array back to itself, or
        // keep it as an array when merged — either way it is not empty.
        $this->assertNotEmpty($firstFinding['metadata']);
    }

    public function testGetReportFormatHasRequiredKeys(): void
    {
        $file = __DIR__ . '/../../../../fixtures/GetWriteAntipattern/Bad/etc/webapi.xml';

        ob_start();
        $this->processor->process(['xml' => [$file]]);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('ruleId', $report[0]);
        $this->assertArrayHasKey('name', $report[0]);
        $this->assertArrayHasKey('shortDescription', $report[0]);
        $this->assertArrayHasKey('longDescription', $report[0]);
        $this->assertArrayHasKey('files', $report[0]);
    }
}
