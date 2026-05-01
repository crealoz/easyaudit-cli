<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\IndexerCircular;
use PHPUnit\Framework\TestCase;

class IndexerCircularTest extends TestCase
{
    private IndexerCircular $processor;

    protected function setUp(): void
    {
        $this->processor = new IndexerCircular();
    }

    public function testGetIdentifier(): void
    {
        $this->assertEquals('indexerCircular', $this->processor->getIdentifier());
    }

    public function testGetFileType(): void
    {
        $this->assertEquals('xml', $this->processor->getFileType());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testGetLongDescriptionMentionsCycle(): void
    {
        $this->assertStringContainsString('cycle', strtolower($this->processor->getLongDescription()));
    }

    public function testProcessIgnoresEmptyBucket(): void
    {
        ob_start();
        $this->processor->process(['xml' => []]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessFlagsTwoNodeCycle(): void
    {
        $base = __DIR__ . '/../../../../fixtures/IndexerCircular/Bad';
        $files = [
            'xml' => [
                $base . '/ModuleA/etc/indexer.xml',
                $base . '/ModuleB/etc/indexer.xml',
            ],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Cycle of 2 indexers: one result entry per participant.
        $this->assertEquals(2, $this->processor->getFoundCount());
        $this->assertNotEmpty($report[0]['files']);
    }

    public function testProcessDagHasNoFindings(): void
    {
        $base = __DIR__ . '/../../../../fixtures/IndexerCircular/Good';
        $files = [
            'xml' => [
                $base . '/ModuleA/etc/indexer.xml',
                $base . '/ModuleB/etc/indexer.xml',
            ],
        ];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testThreeNodeCycleDetected(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ic_' . uniqid();
        mkdir($tempDir . '/a', 0777, true);
        mkdir($tempDir . '/b', 0777, true);
        mkdir($tempDir . '/c', 0777, true);

        file_put_contents($tempDir . '/a/indexer.xml', <<<'X'
<?xml version="1.0"?>
<config><indexer id="a" view_id="v1" class="C1"><title>A</title><description>A</description>
<dependencies><indexer id="b"/></dependencies></indexer></config>
X);
        file_put_contents($tempDir . '/b/indexer.xml', <<<'X'
<?xml version="1.0"?>
<config><indexer id="b" view_id="v2" class="C2"><title>B</title><description>B</description>
<dependencies><indexer id="c"/></dependencies></indexer></config>
X);
        file_put_contents($tempDir . '/c/indexer.xml', <<<'X'
<?xml version="1.0"?>
<config><indexer id="c" view_id="v3" class="C3"><title>C</title><description>C</description>
<dependencies><indexer id="a"/></dependencies></indexer></config>
X);

        ob_start();
        $this->processor->process([
            'xml' => [
                $tempDir . '/a/indexer.xml',
                $tempDir . '/b/indexer.xml',
                $tempDir . '/c/indexer.xml',
            ],
        ]);
        ob_end_clean();

        // One 3-node cycle produces one result entry per participant.
        $this->assertEquals(3, $this->processor->getFoundCount());

        unlink($tempDir . '/a/indexer.xml');
        unlink($tempDir . '/b/indexer.xml');
        unlink($tempDir . '/c/indexer.xml');
        rmdir($tempDir . '/a');
        rmdir($tempDir . '/b');
        rmdir($tempDir . '/c');
        rmdir($tempDir);
    }

    public function testIgnoresNonIndexerXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ic_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/crontab.xml';
        file_put_contents($xml, '<?xml version="1.0"?><config/>');

        ob_start();
        $this->processor->process(['xml' => [$xml]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }

    public function testSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_ic_' . uniqid();
        mkdir($tempDir);
        $xml = $tempDir . '/indexer.xml';
        file_put_contents($xml, '<?xml version="1.0"?><config><indexer unclosed');

        ob_start();
        $this->processor->process(['xml' => [$xml]]);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());

        unlink($xml);
        rmdir($tempDir);
    }
}
