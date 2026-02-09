<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\CountOnCollection;
use PHPUnit\Framework\TestCase;

class CountOnCollectionTest extends TestCase
{
    private CountOnCollection $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new CountOnCollection();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/CountOnCollection';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('magento.performance.count-on-collection', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testDetectsCountOnInjectedCollection(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/CountOnInjectedCollection.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsCountMethodOnCollection(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/CountMethodOnCollection.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsCountMethodOnFactoryCollection(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/CountOnFactoryCollection.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsPhpCountOnFactoryCollection(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Bad/PhpCountOnFactoryCollection.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGetSizeOnFactoryCollection(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Good/GetSizeOnFactoryCollection.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGetSizeUsage(): void
    {
        $files = ['php' => [$this->fixturesPath . '/Good/GetSizeUsage.php']];

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
        $files = ['php' => [$this->fixturesPath . '/Bad/CountOnInjectedCollection.php']];

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

    public function testDetectsCountInPhtmlTemplate(): void
    {
        $files = [
            'php' => [$this->fixturesPath . '/Bad/PhtmlCountOnCollection/Block/LatestFaq.php'],
            'phtml' => [$this->fixturesPath . '/Bad/PhtmlCountOnCollection/templates/latest_faq.phtml'],
        ];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGetSizeInPhtmlTemplate(): void
    {
        $processor = new CountOnCollection();
        $files = [
            'php' => [$this->fixturesPath . '/Good/PhtmlGetSizeOnCollection/Block/LatestFaq.php'],
            'phtml' => [$this->fixturesPath . '/Good/PhtmlGetSizeOnCollection/templates/latest_faq.phtml'],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
    }

    public function testSkipsPhtmlWithoutVarAnnotation(): void
    {
        $processor = new CountOnCollection();

        // Create a temp phtml file without @var annotation
        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        $phtmlFile = $tmpDir . '/no_annotation.phtml';
        file_put_contents($phtmlFile, '<?php
$latestFaq = $block->getLatestFaq();
if ($latestFaq->count() > 0):
    echo "found";
endif;
');

        $files = [
            'php' => [$this->fixturesPath . '/Bad/PhtmlCountOnCollection/Block/LatestFaq.php'],
            'phtml' => [$phtmlFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Only PHP findings, no phtml findings (no @var annotation)
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phtmlFile);
        rmdir($tmpDir);
    }
}
