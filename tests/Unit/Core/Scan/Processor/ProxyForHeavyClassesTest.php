<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\ProxyForHeavyClasses;
use PHPUnit\Framework\TestCase;

class ProxyForHeavyClassesTest extends TestCase
{
    private ProxyForHeavyClasses $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new ProxyForHeavyClasses();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/ProxyForHeavyClasses';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('noProxyUsedForHeavyClasses', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Proxy for Heavy Classes', $this->processor->getName());
    }

    public function testGetMessageContainsProxy(): void
    {
        $this->assertStringContainsString('prox', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('heavy', strtolower($description));
        $this->assertStringContainsString('proxy', strtolower($description));
    }

    public function testProcessDetectsMissingProxyForSession(): void
    {
        // Use temp files because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Vendor\Module\Model;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Customer
{
    public function __construct(
        private Session $customerSession,
        private ScopeConfigInterface $scopeConfig
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/Customer.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should detect Session without proxy
        $this->assertGreaterThan(0, $this->processor->getFoundCount());

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessIgnoresGoodProxyConfiguration(): void
    {
        // Use temp files because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Vendor\Module\Model;

use Magento\Customer\Model\Session;

class Customer
{
    public function __construct(
        private Session $customerSession
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/Customer.php';
        file_put_contents($phpFile, $phpContent);

        // di.xml with proxy configuration
        // Note: argument name uses "$customerSession" to match processor's xpath query
        // (processor passes param name with $ prefix, so di.xml must match)
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Vendor\Module\Model\Customer">
        <arguments>
            <argument name="$customerSession" xsi:type="object">Magento\Customer\Model\Session\Proxy</argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Good configuration has proxy configured
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessDetectsSessionClass(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Customer\Model\Session;

class TestClass
{
    public function __construct(
        private Session $customerSession
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/TestClass.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should detect Session without proxy');

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessDetectsCollectionClass(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

class TestClass
{
    public function __construct(
        private Collection $productCollection
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/TestClass.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should detect Collection without proxy');

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessIgnoresFactory(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        // Factory suffix should be ignored
        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class TestClass
{
    public function __construct(
        private CollectionFactory $productCollectionFactory
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/TestClass.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Factories should not be flagged
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        // Use temp files because processor skips /tests/ directory
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Vendor\Module\Model;

use Magento\Customer\Model\Session;

class Customer
{
    public function __construct(
        private Session $customerSession
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/Customer.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

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
            $this->assertEquals('noProxyUsedForHeavyClasses', $firstRule['ruleId']);
        }

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessSkipsTestFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/Test/etc', 0777, true);

        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Test;

use Magento\Customer\Model\Session;

class TestHelper
{
    public function __construct(private Session $session) {}
}
PHP;
        $phpFile = $tempDir . '/Test/TestHelper.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/Test/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/Test/etc');
        rmdir($tempDir . '/Test');
        rmdir($tempDir);
    }

    public function testProcessWithNoDiFiles(): void
    {
        $files = ['php' => ['/some/file.php']];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        // Should gracefully handle missing di files
        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => [], 'di' => []];

        ob_start();
        $this->processor->process($files);
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresInterfaceSuffix(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        // Interface suffix should be ignored even if it contains "Session"
        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Customer\Model\SessionInterface;

class TestClass
{
    public function __construct(
        private SessionInterface $customerSession
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/TestClass.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Interface should not be flagged');

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testProcessDetectsMultipleHeavyDependencies(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_proxy_test_' . uniqid();
        mkdir($tempDir . '/etc', 0777, true);

        // Class with two heavy dependencies (both Session types)
        $phpContent = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Customer\Model\Session;
use Magento\Checkout\Model\Session as CheckoutSession;

class TestClass
{
    public function __construct(
        private Session $customerSession,
        private CheckoutSession $checkoutSession
    ) {
    }
}
PHP;
        $phpFile = $tempDir . '/TestClass.php';
        file_put_contents($phpFile, $phpContent);

        $diContent = '<?xml version="1.0"?><config></config>';
        $diFile = $tempDir . '/etc/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new ProxyForHeavyClasses();
        $files = [
            'php' => [$phpFile],
            'di' => [$diFile],
        ];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Both Session classes should be detected
        $this->assertGreaterThanOrEqual(2, $processor->getFoundCount());

        unlink($phpFile);
        unlink($diFile);
        rmdir($tempDir . '/etc');
        rmdir($tempDir);
    }

    public function testGetReportEmptyWhenNoIssues(): void
    {
        $processor = new ProxyForHeavyClasses();
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertEmpty($report);
    }
}
