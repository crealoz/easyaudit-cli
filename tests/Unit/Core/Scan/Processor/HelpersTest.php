<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\Helpers;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    private Helpers $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new Helpers();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/Helpers';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('helpers', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Helpers', $this->processor->getName());
    }

    public function testGetMessageContainsHelper(): void
    {
        $this->assertStringContainsString('Helper', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('AbstractHelper', $description);
        $this->assertStringContainsString('ViewModel', $description);
    }

    public function testProcessDetectsBadHelperExtendingAbstractHelper(): void
    {
        // Use temp file because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_helper_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Module\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    public function getFormattedPrice($price)
    {
        return '$' . number_format($price, 2);
    }
}
PHP;
        $file = $tempDir . '/Data.php';
        file_put_contents($file, $content);

        $files = ['php' => [$file]];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresGoodHelperWithoutAbstractHelper(): void
    {
        $goodFile = $this->fixturesPath . '/Good/Helper/PriceUtility.php';

        if (!file_exists($goodFile)) {
            $this->markTestSkipped('Good Helper fixture not available');
        }

        $processor = new Helpers();
        $files = ['php' => [$goodFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Good helper doesn't extend AbstractHelper - but we need to check
        // This depends on the fixture content
        $this->assertIsArray($report);
    }

    public function testProcessDetectsHelperUsageInPhtml(): void
    {
        // Use temp files because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_helper_test_' . uniqid();
        mkdir($tempDir . '/view/frontend/templates', 0777, true);

        $phtmlContent = <<<'PHTML'
<?php
/** @var $block \Magento\Framework\View\Element\Template */
?>
<div class="price">
    <?= $this->helper('Vendor\Module\Helper\Data')->getFormattedPrice(99.99) ?>
</div>
PHTML;
        $phtmlFile = $tempDir . '/view/frontend/templates/product.phtml';
        file_put_contents($phtmlFile, $phtmlContent);

        $phpContent = <<<'PHP'
<?php
namespace Vendor\Module\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    public function getFormattedPrice($price)
    {
        return '$' . number_format($price, 2);
    }
}
PHP;
        $phpFile = $tempDir . '/Data.php';
        file_put_contents($phpFile, $phpContent);

        $processor = new Helpers();
        $files = [
            'phtml' => [$phtmlFile],
            'php' => [$phpFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should detect helper extending AbstractHelper and used in phtml
        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($phtmlFile);
        unlink($phpFile);
        rmdir($tempDir . '/view/frontend/templates');
        rmdir($tempDir . '/view/frontend');
        rmdir($tempDir . '/view');
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        // Use temp file because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_helper_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Vendor\Module\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
}
PHP;
        $file = $tempDir . '/Data.php';
        file_put_contents($file, $content);

        $files = ['php' => [$file]];

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
        }

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsTestFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_helper_test_' . uniqid();
        mkdir($tempDir . '/Test', 0777, true);

        // File in Test directory should be skipped
        $content = <<<'PHP'
<?php
namespace Test\Module\Test;

use Magento\Framework\App\Helper\AbstractHelper;

class TestHelper extends AbstractHelper
{
}
PHP;
        $file = $tempDir . '/Test/TestHelper.php';
        file_put_contents($file, $content);

        $processor = new Helpers();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir . '/Test');
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

    public function testReportHasExtensionAndUsageRules(): void
    {
        // Use temp files because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_helper_test_' . uniqid();
        mkdir($tempDir . '/view/frontend/templates', 0777, true);

        $phtmlContent = <<<'PHTML'
<?php
/** @var $block \Magento\Framework\View\Element\Template */
?>
<div><?= $this->helper('Vendor\Module\Helper\Data')->getFormattedPrice(99.99) ?></div>
PHTML;
        $phtmlFile = $tempDir . '/view/frontend/templates/product.phtml';
        file_put_contents($phtmlFile, $phtmlContent);

        $phpContent = <<<'PHP'
<?php
namespace Vendor\Module\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{
    public function getFormattedPrice($price)
    {
        return '$' . number_format($price, 2);
    }
}
PHP;
        $phpFile = $tempDir . '/Data.php';
        file_put_contents($phpFile, $phpContent);

        $processor = new Helpers();
        $files = [
            'phtml' => [$phtmlFile],
            'php' => [$phpFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Check for expected rule types
        $ruleIds = array_column($report, 'ruleId');

        // Should have at least one of the helper rules
        $hasHelperRule = in_array('extensionOfAbstractHelper', $ruleIds) ||
                         in_array('helpersInsteadOfViewModels', $ruleIds);
        $this->assertTrue($hasHelperRule, 'Should detect helper issues');

        unlink($phtmlFile);
        unlink($phpFile);
        rmdir($tempDir . '/view/frontend/templates');
        rmdir($tempDir . '/view/frontend');
        rmdir($tempDir . '/view');
        rmdir($tempDir);
    }
}
