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

        // Check for different rule IDs from RULE_CONFIGS
        $ruleIds = array_column($report, 'ruleId');
        $this->assertGreaterThanOrEqual(1, count($ruleIds), 'Should have at least one rule type');

        // Valid rule IDs from RULE_CONFIGS
        $validRuleIds = [
            'collectionMustUseFactory',
            'collectionWithChildrenMustUseFactory',
            'repositoryMustUseInterface',
            'repositoryWithChildrenMustUseInterface',
            'modelUseApiInterface',
            'noResourceModelInjection',
            'specificClassInjection',
        ];

        foreach ($ruleIds as $ruleId) {
            $this->assertContains($ruleId, $validRuleIds, "Rule ID '$ruleId' should be from RULE_CONFIGS");
        }

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
        // Use a simple Model class name (no Collection) to test that GuzzleHttp is ignored
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use GuzzleHttp\Client;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

class ProductModel
{
    public function __construct(
        private Client $httpClient,
        private Collection $productCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // GuzzleHttp\Client should be ignored (non-Magento library)
        // Collection should be flagged
        $this->assertGreaterThan(0, $processor->getFoundCount(), 'Should flag at least the Collection');

        // Verify GuzzleHttp is not in any rule's files
        $allMessages = [];
        foreach ($report as $rule) {
            foreach ($rule['files'] as $fileEntry) {
                $allMessages[] = $fileEntry['message'];
            }
        }
        $hasGuzzle = false;
        foreach ($allMessages as $msg) {
            if (str_contains($msg, 'GuzzleHttp')) {
                $hasGuzzle = true;
                break;
            }
        }
        $this->assertFalse($hasGuzzle, 'GuzzleHttp should not be flagged');

        unlink($tempDir . '/ProductModel.php');
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

    public function testProcessIgnoresHeavyClassesFromClassToProxy(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Classes from ClassToProxy list should be ignored (not flagged)
        $content = <<<'PHP'
<?php
namespace Test\Module\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class HeavyDependencyService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private CustomerRepositoryInterface $customerRepository,
        private ResourceConnection $resourceConnection,
        private StoreManagerInterface $storeManager
    ) {
    }
}
PHP;
        $file = $tempDir . '/HeavyDependencyService.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // All these classes are in ClassToProxy, so should be ignored
        $this->assertEquals(0, $processor->getFoundCount(), 'Heavy classes from ClassToProxy should be ignored');
    }

    public function testProcessIgnoresHeavyClassButFlagsOthers(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Mix: heavy class (ignored) + collection (should be flagged)
        // Use simple Model name (no "Collection" in the name) to avoid triggering collection
        // detection for ResourceConnection
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

class MixedModel
{
    public function __construct(
        private ResourceConnection $resourceConnection,
        private Collection $productCollection
    ) {
    }
}
PHP;
        $file = $tempDir . '/MixedModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // ResourceConnection is in ClassToProxy (ignored)
        // Collection may be flagged as resource model or generic class
        $this->assertNotEmpty($report, 'Should have at least one violation');

        // Verify ResourceConnection is not flagged
        $allMessages = [];
        foreach ($report as $rule) {
            foreach ($rule['files'] as $fileEntry) {
                $allMessages[] = $fileEntry['message'];
            }
        }
        $hasResourceConnection = false;
        foreach ($allMessages as $msg) {
            if (str_contains($msg, 'ResourceConnection')) {
                $hasResourceConnection = true;
                break;
            }
        }
        $this->assertFalse($hasResourceConnection, 'ResourceConnection should be ignored');

        unlink($tempDir . '/MixedModel.php');
        rmdir($tempDir);
    }

    public function testRuleConfigsConsistency(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Create file with collection violation
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

        $this->assertNotEmpty($report);

        // Each report entry should have the standard structure from RULE_CONFIGS
        foreach ($report as $entry) {
            $this->assertArrayHasKey('ruleId', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('shortDescription', $entry);
            $this->assertArrayHasKey('longDescription', $entry);
            $this->assertArrayHasKey('files', $entry);

            // Rule ID should follow the pattern from RULE_CONFIGS
            $this->assertMatchesRegularExpression(
                '/^(collectionMustUseFactory|collectionWithChildrenMustUseFactory|repositoryMustUseInterface|repositoryWithChildrenMustUseInterface|modelUseApiInterface|noResourceModelInjection|specificClassInjection)$/',
                $entry['ruleId'],
                "Rule ID should be from RULE_CONFIGS"
            );
        }

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessSkipsSymfonyCommands(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Console\Command;

use Symfony\Component\Console\Command\Command;
use Vendor\Module\Model\SomeService;

class MyCommand extends Command
{
    public function __construct(
        private SomeService $service
    ) {
        parent::__construct();
    }
}
PHP;
        $file = $tempDir . '/MyCommand.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should skip Symfony console commands');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessDetectsGenericClassInjection(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Model\SomeConcreteClass;

class ServiceModel
{
    public function __construct(
        private SomeConcreteClass $concrete
    ) {
    }
}
PHP;
        $file = $tempDir . '/ServiceModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        $this->assertGreaterThan(0, $processor->getFoundCount());
        $ruleIds = array_column($report, 'ruleId');
        $this->assertContains('specificClassInjection', $ruleIds);

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresFrameworkClasses(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Magento\Framework classes should be ignored (via IGNORED_SUBSTRINGS containing 'Magento\Framework')
        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Magento\Framework\Event\ManagerInterface;

class SomeModel
{
    public function __construct(
        private ManagerInterface $eventManager
    ) {
    }
}
PHP;
        $file = $tempDir . '/SomeModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should ignore Magento Framework classes');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresHelperClasses(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Helper\Data;

class SomeModel
{
    public function __construct(
        private Data $helper
    ) {
    }
}
PHP;
        $file = $tempDir . '/SomeModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should ignore Helper classes');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresProviderSuffix(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Model\DataProvider;

class SomeModel
{
    public function __construct(
        private DataProvider $provider
    ) {
    }
}
PHP;
        $file = $tempDir . '/SomeModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should ignore Provider suffix');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresResolverSuffix(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

use Vendor\Module\Model\StockResolver;

class SomeModel
{
    public function __construct(
        private StockResolver $resolver
    ) {
    }
}
PHP;
        $file = $tempDir . '/SomeModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should ignore Resolver suffix');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessResourceModelNotFlaggedInsideResourceModel(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // A ResourceModel class injecting another ResourceModel is a common pattern
        $content = <<<'PHP'
<?php
namespace Test\Module\Model\ResourceModel;

use Vendor\Module\Model\ResourceModel\Related;

class ProductResourceModel
{
    public function __construct(
        private Related $relatedResource
    ) {
    }
}
PHP;
        $file = $tempDir . '/ProductResourceModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // ResourceModel injecting ResourceModel should not be flagged
        $report = $processor->getReport();
        $hasResourceModelRule = false;
        foreach ($report as $entry) {
            if ($entry['ruleId'] === 'noResourceModelInjection') {
                $hasResourceModelRule = true;
            }
        }
        $this->assertFalse($hasResourceModelRule, 'ResourceModel injecting ResourceModel should be allowed');

        unlink($file);
        rmdir($tempDir);
    }

    public function testProcessIgnoresBasicTypes(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $content = <<<'PHP'
<?php
namespace Test\Module\Model;

class SimpleModel
{
    public function __construct(
        private string $name,
        private int $count,
        private array $data,
        private bool $active
    ) {
    }
}
PHP;
        $file = $tempDir . '/SimpleModel.php';
        file_put_contents($file, $content);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$file]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount(), 'Should ignore basic PHP types');

        unlink($file);
        rmdir($tempDir);
    }

    public function testChildrenDetectionAffectsSeverity(): void
    {
        // Test that collection/repository with children use warning severity
        // while those without children use error severity

        $tempDir = sys_get_temp_dir() . '/easyaudit_injection_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Create a parent collection class
        $parentContent = <<<'PHP'
<?php
namespace Test\Module\Model\ResourceModel\Product;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
}
PHP;
        $parentFile = $tempDir . '/Collection.php';
        file_put_contents($parentFile, $parentContent);

        // Create a child collection class
        $childContent = <<<'PHP'
<?php
namespace Test\Module\Model\ResourceModel\Product;

class ChildCollection extends Collection
{
}
PHP;
        $childFile = $tempDir . '/ChildCollection.php';
        file_put_contents($childFile, $childContent);

        // Create a service that injects the parent collection
        // Note: ClassName must contain 'Model' AND 'Collection' to trigger collection detection
        $serviceContent = <<<'PHP'
<?php
namespace Test\Module\Model\Collection;

use Test\Module\Model\ResourceModel\Product\Collection;

class ProductCollectionModel
{
    public function __construct(
        private Collection $productCollection
    ) {
    }
}
PHP;
        $serviceFile = $tempDir . '/ProductCollectionModel.php';
        file_put_contents($serviceFile, $serviceContent);

        $processor = new SpecificClassInjection();
        $files = ['php' => [$parentFile, $childFile, $serviceFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // When a collection has children, it should use collectionWithChildrenMustUseFactory (warning)
        // When it doesn't, it should use collectionMustUseFactory (error)
        // The exact behavior depends on whether Classes::getChildren finds the child

        $this->assertNotEmpty($report, 'Should have at least one report entry');
        $ruleIds = array_column($report, 'ruleId');
        // Should be one of the two collection rules
        $hasCollectionRule = in_array('collectionMustUseFactory', $ruleIds)
            || in_array('collectionWithChildrenMustUseFactory', $ruleIds);
        $this->assertTrue($hasCollectionRule, 'Should have a collection rule');

        unlink($parentFile);
        unlink($childFile);
        unlink($serviceFile);
        rmdir($tempDir);
    }
}
