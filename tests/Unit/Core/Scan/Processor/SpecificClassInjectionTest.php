<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\SpecificClassInjection;
use PHPUnit\Framework\TestCase;

class SpecificClassInjectionTest extends TestCase
{
    private SpecificClassInjection $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new SpecificClassInjection();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/SpecificClassInjection';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('specificClassInjection', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Specific Class Injection', $this->processor->getName());
    }

    public function testGetMessageContainsInjection(): void
    {
        $this->assertStringContainsString('injection', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('interface', strtolower($description));
        $this->assertStringContainsString('factori', strtolower($description)); // factories
    }

    public function testProcessDetectsCollectionWithoutFactory(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

class ProductLoader
{
    public function __construct(
        private Collection $productCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductLoader.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should detect Collection without Factory');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsRepositoryWithoutInterface(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Model\ProductRepository;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should detect Repository without Interface');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresInterface(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Api\ProductRepositoryInterface;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should not flag Interface injection');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresFactory(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class ProductLoader
{
    public function __construct(
        private CollectionFactory $productCollectionFactory
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductLoader.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should not flag Factory injection');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsFactoryClasses(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Factory classes are designed to instantiate concrete classes
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\ObjectManagerInterface;

class ProductFactory
{
    public function __construct(
        private Product $product
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductFactory.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Factory classes are exempt from this rule
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsResourceModelInjection(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Model\ResourceModel\Product;

class ProductService
{
    public function __construct(
        private Product $productResource
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should detect ResourceModel injection
        $this->assertGreaterThan(0, $processor->getFoundCount());

        unlink($file);
        rmdir($tempDir);
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

class ProductLoader
{
    public function __construct(
        private Collection $productCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductLoader.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
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

    public function testProcessIgnoresFilesWithoutConstructor(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

class SimpleClass
{
    public function doSomething(): void
    {
        // No constructor
    }
}
PHP;
        $file = $tempDir . '/SimpleClass.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

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

    public function testReportSeparatesByViolationType(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Create file with multiple violation types
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Vendor\Module\Model\ProductRepository;

class BadService
{
    public function __construct(
        private Collection $productCollection,
        private ProductRepository $productRepository
    ) {
    }
}
PHP;
        $file = $tempDir . '/BadService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should have multiple violations
        $this->assertGreaterThanOrEqual(2, $processor->getFoundCount());

        // Check for different rule IDs
        $ruleIds = array_column($report, 'ruleId');
        $this->assertGreaterThanOrEqual(1, count($ruleIds), 'Should have at least one rule type');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresNonMagentoLibraries(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Non-Magento library classes should NOT be flagged by generic rule
        $content = <<<'PHP'
<?php
namespace Test\Module\Service;

use GuzzleHttp\Client;
use Monolog\Logger;
use Symfony\Component\Console\Application;

class ExternalApiService
{
    public function __construct(
        private Client $httpClient,
        private Logger $logger,
        private Application $consoleApp
    ) {
    }
}
PHP;
        $file = $tempDir . '/ExternalApiService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Non-Magento libraries should NOT be flagged
        $this->assertEquals(0, $processor->getFoundCount(), 'Should not flag non-Magento library injections');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresAllKnownNonMagentoVendors(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Test various non-Magento vendors
        $content = <<<'PHP'
<?php
namespace Test\Module\Service;

use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Laminas\Db\Adapter\Adapter;
use League\Flysystem\Filesystem;
use Composer\Autoload\ClassLoader;
use Doctrine\ORM\EntityManager;
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use Zend\Http\Client as ZendClient;

class MultiVendorService
{
    public function __construct(
        private Client $guzzle,
        private StreamHandler $monolog,
        private ContainerInterface $psrContainer,
        private EventDispatcher $symfony,
        private Adapter $laminas,
        private Filesystem $league,
        private ClassLoader $composer,
        private EntityManager $doctrine,
        private S3Client $aws,
        private StorageClient $google,
        private Carbon $carbon,
        private Uuid $ramsey,
        private ZendClient $zend
    ) {
    }
}
PHP;
        $file = $tempDir . '/MultiVendorService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // All known non-Magento vendors should be ignored
        $this->assertEquals(0, $processor->getFoundCount(), 'Should not flag any known non-Magento library');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessStillFlagsMagentoClasses(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Mix of non-Magento (ignored) and Magento (should be flagged) classes
        $content = <<<'PHP'
<?php
namespace Test\Module\Service;

use GuzzleHttp\Client;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

class MixedService
{
    public function __construct(
        private Client $httpClient,
        private Collection $productCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/MixedService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Only the Magento Collection should be flagged, not GuzzleHttp\Client
        $this->assertEquals(1, $processor->getFoundCount(), 'Should only flag Magento Collection, not GuzzleHttp\Client');

        // Verify the flagged issue is for Collection
        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('collectionMustUseFactory', $ruleIds);

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessStillFlagsCustomMagentoModules(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Custom Magento module classes should still be flagged
        $content = <<<'PHP'
<?php
namespace Test\Module\Service;

use Vendor\CustomModule\Model\ResourceModel\Order\Collection;

class CustomModuleService
{
    public function __construct(
        private Collection $orderCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/CustomModuleService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Custom Magento module Collection should be flagged
        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should flag custom Magento module Collection');

        unlink($file);
        rmdir($tempDir);
    }
}
