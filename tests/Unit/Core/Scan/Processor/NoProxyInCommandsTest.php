<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\NoProxyInCommands;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NoProxyInCommandsTest extends TestCase
{
    private NoProxyInCommands $processor;

    protected function setUp(): void
    {
        $this->processor = new NoProxyInCommands();
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('noProxyUsedInCommands', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('No Proxy in Commands', $this->processor->getName());
    }

    public function testGetMessageContainsProxy(): void
    {
        $this->assertStringContainsString('prox', strtolower($this->processor->getMessage()));
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('prox', strtolower($description));
        $this->assertStringContainsString('command', strtolower($description));
    }

    public function testProcessWithEmptyDiFiles(): void
    {
        $files = ['di' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithValidDiXmlWithoutCommands(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_command_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="SomeClass">
        <arguments>
            <argument name="param" xsi:type="string">value</argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_command_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $diContent = '<?xml version="1.0"?><config><type name="broken"';
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testGetReportReturnsNoFilesWhenNoIssues(): void
    {
        $processor = new NoProxyInCommands();
        $report = $processor->getReport();

        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        $this->assertEmpty($report[0]['files']);
    }

    public function testProcessWithMissingDiKey(): void
    {
        $files = ['php' => ['/some/file.php']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessDetectsCommandWithoutProxy(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cmd_' . uniqid();
        mkdir($tempDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        // Create a command PHP file with constructor dependencies but no proxy
        $commandContent = <<<'PHP'
<?php
namespace Vendor\Module\Console\Command;

use Vendor\Module\Model\SomeService;
use Vendor\Module\Api\SomeRepositoryInterface;

class MyCommand extends \Symfony\Component\Console\Command\Command
{
    public function __construct(
        private SomeService $someService,
        private SomeRepositoryInterface $someRepository
    ) {
        parent::__construct();
    }
}
PHP;
        // Store at the path the resolver expects (Strategy 2: strip Vendor\Module prefix)
        mkdir($tempDir . '/Console/Command', 0777, true);
        file_put_contents($tempDir . '/Console/Command/MyCommand.php', $commandContent);

        // Create di.xml that references this command in CommandList
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\Console\CommandList">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="myCommand" xsi:type="object">Vendor\Module\Console\Command\MyCommand</item>
            </argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $output = ob_get_clean();

        // Should detect parameters without proxy
        $this->assertGreaterThan(0, $processor->getFoundCount());

        // Should output summary message
        $this->assertStringContainsString('Commands without proxy', $output);

        // Check report format
        $report = $processor->getReport();
        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        $this->assertNotEmpty($report[0]['files']);

        // Verify file entries contain expected structure
        $fileEntry = $report[0]['files'][0];
        $this->assertArrayHasKey('file', $fileEntry);
        $this->assertArrayHasKey('message', $fileEntry);
        $this->assertArrayHasKey('severity', $fileEntry);
        $this->assertEquals('warning', $fileEntry['severity']);

        // Cleanup
        @unlink($tempDir . '/Console/Command/MyCommand.php');
        @unlink($diFile);
        @rmdir($tempDir . '/Console/Command');
        @rmdir($tempDir . '/Console');
        @rmdir($tempDir);
    }

    public function testProcessAllowsCommandsWithProxy(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cmd_' . uniqid();
        mkdir($tempDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        // Command with a single dependency
        $commandContent = <<<'PHP'
<?php
namespace Vendor\Module\Console\Command;

use Vendor\Module\Model\SomeService;

class ProxyCommand extends \Symfony\Component\Console\Command\Command
{
    public function __construct(
        private SomeService $someService
    ) {
        parent::__construct();
    }
}
PHP;
        mkdir($tempDir . '/Console/Command', 0777, true);
        file_put_contents($tempDir . '/Console/Command/ProxyCommand.php', $commandContent);

        // di.xml with both CommandList and proxy configuration
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\Console\CommandList">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="proxyCommand" xsi:type="object">Vendor\Module\Console\Command\ProxyCommand</item>
            </argument>
        </arguments>
    </type>
    <type name="Vendor\Module\Console\Command\ProxyCommand">
        <arguments>
            <argument name="someService" xsi:type="object">Vendor\Module\Model\SomeService\Proxy</argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // All proxies are configured, should find 0 issues
        $this->assertEquals(0, $processor->getFoundCount());

        @unlink($tempDir . '/Console/Command/ProxyCommand.php');
        @unlink($diFile);
        @rmdir($tempDir . '/Console/Command');
        @rmdir($tempDir . '/Console');
        @rmdir($tempDir);
    }

    public function testProcessHandlesUnresolvableClassGracefully(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cmd_' . uniqid();
        mkdir($tempDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        // di.xml references a class that doesn't exist as a file
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\Console\CommandList">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="ghostCommand" xsi:type="object">Vendor\Module\Console\Command\NonexistentCommand</item>
            </argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Should skip gracefully when class file not found
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessSkipsFactoryDependencies(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cmd_' . uniqid();
        mkdir($tempDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        // Command with only Factory dependencies (should be skipped)
        $commandContent = <<<'PHP'
<?php
namespace Vendor\Module\Console\Command;

use Vendor\Module\Model\ProductFactory;

class FactoryCommand extends \Symfony\Component\Console\Command\Command
{
    public function __construct(
        private ProductFactory $productFactory
    ) {
        parent::__construct();
    }
}
PHP;
        mkdir($tempDir . '/Console/Command', 0777, true);
        file_put_contents($tempDir . '/Console/Command/FactoryCommand.php', $commandContent);

        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\Console\CommandList">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="factoryCommand" xsi:type="object">Vendor\Module\Console\Command\FactoryCommand</item>
            </argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Factory dependencies are lazy-loaded by design, should not be flagged
        $this->assertEquals(0, $processor->getFoundCount());

        @unlink($tempDir . '/Console/Command/FactoryCommand.php');
        @unlink($diFile);
        @rmdir($tempDir . '/Console/Command');
        @rmdir($tempDir . '/Console');
        @rmdir($tempDir);
    }

    public function testProcessWithCommandListInterface(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_cmd_' . uniqid();
        mkdir($tempDir, 0777, true);

        if (!defined('EA_SCAN_PATH')) {
            define('EA_SCAN_PATH', $tempDir);
        }

        $commandContent = <<<'PHP'
<?php
namespace Vendor\Module\Console\Command;

use Vendor\Module\Model\SomeService;

class InterfaceCommand extends \Symfony\Component\Console\Command\Command
{
    public function __construct(
        private SomeService $someService
    ) {
        parent::__construct();
    }
}
PHP;
        mkdir($tempDir . '/Console/Command', 0777, true);
        file_put_contents($tempDir . '/Console/Command/InterfaceCommand.php', $commandContent);

        // Use CommandListInterface instead of CommandList
        $diContent = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <type name="Magento\Framework\Console\CommandListInterface">
        <arguments>
            <argument name="commands" xsi:type="array">
                <item name="interfaceCommand" xsi:type="object">Vendor\Module\Console\Command\InterfaceCommand</item>
            </argument>
        </arguments>
    </type>
</config>
XML;
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        ob_end_clean();

        // Should also detect via CommandListInterface
        $this->assertGreaterThan(0, $processor->getFoundCount());

        @unlink($tempDir . '/Console/Command/InterfaceCommand.php');
        @unlink($diFile);
        @rmdir($tempDir . '/Console/Command');
        @rmdir($tempDir . '/Console');
        @rmdir($tempDir);
    }
}
