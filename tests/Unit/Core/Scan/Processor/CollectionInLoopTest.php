<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\CollectionInLoop;
use PHPUnit\Framework\TestCase;

class CollectionInLoopTest extends TestCase
{
    private CollectionInLoop $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new CollectionInLoop();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/CollectionInLoop';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('magento.performance.collection-in-loop', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testDetectsLoadInForeach(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/LoadInForeach.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsGetByIdInLoop(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/GetByIdInLoop.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsStaticLoadInLoop(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/StaticLoadInLoop.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGoodPatterns(): void
    {
        $files = ['php' => [
            $this->fixturesPath . '/Good/CollectionLoadBeforeLoop.php',
            $this->fixturesPath . '/Good/BatchProcessing.php',
        ]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithEmptyFiles(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetReportFormat(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/LoadInForeach.php']];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
        }
    }
}
