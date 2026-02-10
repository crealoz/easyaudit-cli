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

    public function testDetectsChainedCountInPhtml(): void
    {
        $processor = new CountOnCollection();

        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // phtml with chained $block->getLatestFaq()->count()
        $phtmlFile = $tmpDir . '/chained.phtml';
        file_put_contents($phtmlFile, '<?php
/** @var \Vendor\Module\Block\LatestFaq $block */
?>
<?php if ($block->getLatestFaq()->count() > 0): ?>
    <div>Content</div>
<?php endif; ?>
');

        $files = [
            'php' => [$this->fixturesPath . '/Bad/PhtmlCountOnCollection/Block/LatestFaq.php'],
            'phtml' => [$phtmlFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($phtmlFile);
        rmdir($tmpDir);
    }

    public function testDetectsCountFunctionInPhtml(): void
    {
        $processor = new CountOnCollection();

        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // phtml with count($block->getLatestFaq())
        $phtmlFile = $tmpDir . '/count_func.phtml';
        file_put_contents($phtmlFile, '<?php
/** @var \Vendor\Module\Block\LatestFaq $block */
$total = count($block->getLatestFaq());
echo $total;
');

        $files = [
            'php' => [$this->fixturesPath . '/Bad/PhtmlCountOnCollection/Block/LatestFaq.php'],
            'phtml' => [$phtmlFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($phtmlFile);
        rmdir($tmpDir);
    }

    public function testPhtmlIgnoresUnmappedBlockMethods(): void
    {
        $processor = new CountOnCollection();

        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // phtml referencing a block method that doesn't return a collection
        $phtmlFile = $tmpDir . '/non_collection.phtml';
        file_put_contents($phtmlFile, '<?php
/** @var \Vendor\Module\Block\LatestFaq $block */
$items = $block->getSomeOtherData();
if ($items->count() > 0):
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

        // getSomeOtherData is not in collectionReturningMethods, so no phtml findings
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phtmlFile);
        rmdir($tmpDir);
    }

    public function testProcessWithOnlyPhtmlFilesNoPhp(): void
    {
        $processor = new CountOnCollection();

        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        $phtmlFile = $tmpDir . '/template.phtml';
        file_put_contents($phtmlFile, '<?php
/** @var \Some\Block $block */
$items = $block->getItems();
echo count($items);
');

        $files = [
            'phtml' => [$phtmlFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // No PHP files processed = no collectionReturningMethods mapped
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phtmlFile);
        rmdir($tmpDir);
    }

    public function testProcessWithNonCollectionConstructorParam(): void
    {
        $processor = new CountOnCollection();

        $tmpDir = sys_get_temp_dir() . '/easyaudit_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // Class with constructor params that are not collections or collection factories
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class SomeService
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private string $name
    ) {
    }

    public function doStuff(): int
    {
        return count([1, 2, 3]);
    }
}
PHP;
        $file = $tmpDir . '/SomeService.php';
        file_put_contents($file, $content);

        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tmpDir);
    }
}
