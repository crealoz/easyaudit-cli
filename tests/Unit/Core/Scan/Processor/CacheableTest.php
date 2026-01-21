<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\Cacheable;
use PHPUnit\Framework\TestCase;

class CacheableTest extends TestCase
{
    private Cacheable $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new Cacheable();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/Cacheable';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('useCacheable', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsXml(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Cacheable Blocks', $this->processor->getName());
    }

    public function testGetMessageContainsCacheable(): void
    {
        $this->assertStringContainsString('cacheable', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('Full Page Cache', $description);
        $this->assertStringContainsString('performance', $description);
    }

    public function testProcessDetectsBadCacheableBlocks(): void
    {
        $badFile = $this->fixturesPath . '/bad_cacheable.xml';
        $this->assertFileExists($badFile, 'Bad fixture file should exist');

        $files = ['xml' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect cacheable="false" blocks (excluding customer/sales blocks)
        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresGoodCacheableFile(): void
    {
        $goodFile = $this->fixturesPath . '/good_cacheable.xml';
        $this->assertFileExists($goodFile, 'Good fixture file should exist');

        $files = ['xml' => [$goodFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessAllowsCustomerRelatedBlocks(): void
    {
        // Create inline fixture with only customer block
        $tempDir = sys_get_temp_dir() . '/easyaudit_cacheable_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $xml = <<<XML
<?xml version="1.0"?>
<page>
    <body>
        <block name="customer.info" cacheable="false"/>
    </body>
</page>
XML;
        $file = $tempDir . '/customer_layout.xml';
        file_put_contents($file, $xml);

        $files = ['xml' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Customer blocks should be allowed to have cacheable="false"
        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessAllowsSalesRelatedBlocks(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cacheable_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $xml = <<<XML
<?xml version="1.0"?>
<page>
    <body>
        <block name="sales.order.info" cacheable="false"/>
    </body>
</page>
XML;
        $file = $tempDir . '/sales_layout.xml';
        file_put_contents($file, $xml);

        $files = ['xml' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Sales blocks should be allowed
        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsDiXmlFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cacheable_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml should be skipped even if it has cacheable attribute
        $xml = <<<XML
<?xml version="1.0"?>
<config>
    <type name="Test">
        <block name="bad.block" cacheable="false"/>
    </type>
</config>
XML;
        $file = $tempDir . '/di.xml';
        file_put_contents($file, $xml);

        $files = ['xml' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $badFile = $this->fixturesPath . '/bad_cacheable.xml';
        $files = ['xml' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('shortDescription', $firstRule);
            $this->assertArrayHasKey('longDescription', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
            $this->assertEquals('useCacheable', $firstRule['ruleId']);
        }
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['xml' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoXmlFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessHandlesMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cacheable_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Malformed XML should be skipped gracefully
        $xml = '<?xml version="1.0"?><page><body><block name="test" cacheable="false"';
        $file = $tempDir . '/malformed.xml';
        file_put_contents($file, $xml);

        $files = ['xml' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should not crash, just skip the file
        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }
}
