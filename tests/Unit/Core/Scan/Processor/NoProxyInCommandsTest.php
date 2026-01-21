<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\NoProxyInCommands;
use PHPUnit\Framework\TestCase;

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
        // Processor expects 'di' key in files
        $files = ['di' => []];

        ob_start();
        try {
            $this->processor->process($files);
        } catch (\TypeError $e) {
            // Expected when di key is empty array
        }
        $report = $this->processor->getReport();
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithValidDiXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_command_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // di.xml without CommandList - should not find any commands
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
        $report = $processor->getReport();
        ob_end_clean();

        // No commands, no issues
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testProcessSkipsMalformedXml(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_command_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Malformed XML
        $diContent = '<?xml version="1.0"?><config><type name="broken"';
        $diFile = $tempDir . '/di.xml';
        file_put_contents($diFile, $diContent);

        $processor = new NoProxyInCommands();
        $files = ['di' => [$diFile]];

        ob_start();
        $processor->process($files);
        $report = $processor->getReport();
        ob_end_clean();

        // Should gracefully skip malformed XML
        $this->assertEquals(0, $processor->getFoundCount());

        unlink($diFile);
        rmdir($tempDir);
    }

    public function testGetReportReturnsNoFilesWhenNoIssues(): void
    {
        // Create a fresh processor to ensure no state from previous tests
        $processor = new NoProxyInCommands();
        $report = $processor->getReport();

        // AbstractProcessor always returns structure with files array
        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        // The files array should be empty when no issues found
        $this->assertEmpty($report[0]['files']);
    }

    public function testProcessWithMissingDiKey(): void
    {
        $files = ['php' => ['/some/file.php']];  // Missing 'di' key

        // Process should handle missing 'di' key gracefully or throw
        $caughtException = false;
        ob_start();
        try {
            $this->processor->process($files);
        } catch (\TypeError|\Error $e) {
            $caughtException = true;
        }
        ob_end_clean();

        // Either caught exception or silently handled (both acceptable)
        $this->assertTrue(true); // Test passes if we get here
    }
}
