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

    public function testGetMessageContainsNPlus1(): void
    {
        $this->assertStringContainsString('N+1', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('N+1', $description);
        $this->assertStringContainsString('loop', strtolower($description));
    }

    public function testDetectsGetFirstItemInLoop(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cil_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Module\Model;

class BadProcessor
{
    public function process(array $ids)
    {
        foreach ($ids as $id) {
            $item = $this->collection->addFieldToFilter('id', $id)->getFirstItem();
            $item->doSomething();
        }
    }
}
PHP;
        $file = $tempDir . '/BadProcessor.php';
        file_put_contents($file, $content);

        $processor = new CollectionInLoop();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testIgnoresLoadOutsideLoop(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cil_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Module\Model;

class GoodProcessor
{
    public function process(int $id)
    {
        $item = $this->repository->getById($id);
        foreach ($item->getItems() as $subItem) {
            echo $subItem->getName();
        }
    }
}
PHP;
        $file = $tempDir . '/GoodProcessor.php';
        file_put_contents($file, $content);

        $processor = new CollectionInLoop();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportHasEmptyFilesWhenNoIssues(): void
    {
        $processor = new CollectionInLoop();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        $this->assertEmpty($report[0]['files']);
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
