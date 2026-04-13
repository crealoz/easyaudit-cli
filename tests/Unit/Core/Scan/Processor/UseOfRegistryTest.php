<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\UseOfRegistry;
use PHPUnit\Framework\TestCase;

class UseOfRegistryTest extends TestCase
{
    private UseOfRegistry $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new UseOfRegistry();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/UseOfRegistry';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('use_of_registry', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Use of Registry', $this->processor->getName());
    }

    public function testGetMessageContainsRegistry(): void
    {
        $this->assertStringContainsString('Registry', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('deprecated', $description);
        $this->assertStringContainsString('constructor injection', $description);
    }

    public function testProcessDetectsBadRegistryUsage(): void
    {
        $badFile = $this->fixturesPath . '/BadRegistryUsage.php';
        $this->assertFileExists($badFile, 'Bad fixture file should exist');

        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresGoodCode(): void
    {
        $goodFile = $this->fixturesPath . '/GoodWithoutRegistry.php';
        $this->assertFileExists($goodFile, 'Good fixture file should exist');

        $processor = new UseOfRegistry();
        $files = ['php' => [$goodFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());
    }

    public function testProcessDetectsMultipleUsages(): void
    {
        $multipleFile = $this->fixturesPath . '/MultipleRegistryUsages.php';

        if (!file_exists($multipleFile)) {
            $this->markTestSkipped('MultipleRegistryUsages fixture not available');
        }

        $processor = new UseOfRegistry();
        $files = ['php' => [$multipleFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should detect multiple usages in the file
        $this->assertGreaterThan(0, $processor->getFoundCount());
    }

    public function testProcessIgnoresFilesWithoutConstructor(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // File without constructor
        $content = <<<'PHP'
<?php
namespace Test;

use Magento\Framework\Registry;

class NoConstructor
{
    public function test()
    {
        // No constructor, so Registry import doesn't matter
        return 'test';
    }
}
PHP;
        $file = $tempDir . '/NoConstructor.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $badFile = $this->fixturesPath . '/BadRegistryUsage.php';
        $files = ['php' => [$badFile]];

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
            $this->assertEquals('magento.code.use-of-registry', $firstRule['ruleId']);
        }
    }

    public function testReportContainsClassName(): void
    {
        // Use temp file because fixtures are in /tests/ path and may cause issues
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\Registry;

class BadRegistryUsage
{
    public function __construct(
        private Registry $registry
    ) {
    }
}
PHP;
        $file = $tempDir . '/BadRegistryUsage.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertNotEmpty($report);

        // Check that the error message contains class name information
        $reportFiles = $report[0]['files'];
        $this->assertNotEmpty($reportFiles);

        $errorMessage = $reportFiles[0]['message'] ?? '';
        $this->assertStringContainsString('BadRegistryUsage', $errorMessage);

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessDetectsRegistryInConstructorOnly(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Registry injected in constructor
        $content = <<<'PHP'
<?php
namespace Test;

use Magento\Framework\Registry;

class WithRegistry
{
    public function __construct(
        private Registry $registry
    ) {
    }
}
PHP;
        $file = $tempDir . '/WithRegistry.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(1, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportReturnsEmptyForFreshProcessor(): void
    {
        $processor = new UseOfRegistry();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertEmpty($report);
    }

    public function testProcessIgnoresFileWithConstructorButNoRegistry(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module;

use Magento\Framework\App\Config\ScopeConfigInterface;

class GoodClass
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }
}
PHP;
        $file = $tempDir . '/GoodClass.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testSkipsParentPassthrough(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\Registry;

class ChildModel extends ParentModel
{
    public function __construct(
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($registry, $data);
    }
}
PHP;
        $file = $tempDir . '/ChildModel.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Parent passthrough should not be flagged');

        unlink($file);
        rmdir($tempDir);
    }

    public function testFlagsActiveRegistryUsage(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\Registry;

class ActiveUsage extends ParentModel
{
    public function __construct(
        private Registry $registry,
        array $data = []
    ) {
        parent::__construct($registry, $data);
    }

    public function getCurrentProduct()
    {
        return $this->registry->registry('current_product');
    }
}
PHP;
        $file = $tempDir . '/ActiveUsage.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Active registry usage should be flagged');

        unlink($file);
        rmdir($tempDir);
    }

    public function testEmptyReportWhenNoIssues(): void
    {
        $goodFile = $this->fixturesPath . '/GoodWithoutRegistry.php';
        $processor = new UseOfRegistry();
        $files = ['php' => [$goodFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEmpty($report);
    }

    public function testLineNumberPointsToConstructorNotProperty(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_registry_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // $registry appears on line 9 (property) and line 12 (constructor param)
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\Registry;

class ProductRepository
{
    /** @var Registry */
    private $registry;

    public function __construct(
        Registry $registry
    ) {
        $this->registry = $registry;
    }
}
PHP;
        $file = $tempDir . '/ProductRepository.php';
        file_put_contents($file, $content);

        $processor = new UseOfRegistry();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertNotEmpty($report);
        // Line number should point to the constructor parameter (line 12), not the property (line 9)
        $lineNumber = $report[0]['files'][0]['startLine'];
        $this->assertEquals(12, $lineNumber, 'Line number should point to constructor parameter, not property declaration');

        unlink($file);
        rmdir($tempDir);
    }
}
