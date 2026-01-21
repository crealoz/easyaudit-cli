<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\UseOfObjectManager;
use PHPUnit\Framework\TestCase;

class UseOfObjectManagerTest extends TestCase
{
    private UseOfObjectManager $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new UseOfObjectManager();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/UseOfObjectManager';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('replaceObjectManager', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsPhp(): void
    {
        $this->assertEquals('php', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertEquals('Use of ObjectManager', $this->processor->getName());
    }

    public function testGetMessageReturnsDescription(): void
    {
        $this->assertStringContainsString('ObjectManager', $this->processor->getMessage());
    }

    public function testGetLongDescriptionExplainsProblem(): void
    {
        $description = $this->processor->getLongDescription();
        $this->assertStringContainsString('dependency injection', $description);
        $this->assertStringContainsString('anti-pattern', $description);
    }

    public function testProcessDetectsViolation(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $this->assertFileExists($badFile, 'Bad fixture file should exist');

        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresCleanCode(): void
    {
        $goodFile = $this->fixturesPath . '/GoodDependencyInjection.php';
        $this->assertFileExists($goodFile, 'Good fixture file should exist');

        $files = ['php' => [$goodFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessIgnoresFactoryClasses(): void
    {
        $factoryFile = $this->fixturesPath . '/ProductFactory.php';
        $this->assertFileExists($factoryFile, 'Factory fixture file should exist');

        $files = ['php' => [$factoryFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        // Factories are allowed to use ObjectManager
        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessDetectsUselessImport(): void
    {
        $uselessImportFile = $this->fixturesPath . '/UselessImport.php';
        $this->assertFileExists($uselessImportFile, 'Useless import fixture file should exist');

        $files = ['php' => [$uselessImportFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testGetReportReturnsCorrectFormat(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        $this->assertIsArray($report);
        if (!empty($report)) {
            $firstRule = $report[0];
            $this->assertArrayHasKey('ruleId', $firstRule);
            $this->assertArrayHasKey('name', $firstRule);
            $this->assertArrayHasKey('shortDescription', $firstRule);
            $this->assertArrayHasKey('files', $firstRule);
        }
    }

    public function testProcessWithEmptyFilesArray(): void
    {
        $files = ['php' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithNoPhpFiles(): void
    {
        $files = [];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testReportContainsFileInformation(): void
    {
        $badFile = $this->fixturesPath . '/BadObjectManagerUsage.php';
        $files = ['php' => [$badFile]];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $report = $this->processor->getReport();

        // Should have report entries
        $this->assertNotEmpty($report);

        // Each rule should have file information
        foreach ($report as $rule) {
            $this->assertArrayHasKey('files', $rule);
            $this->assertNotEmpty($rule['files']);
        }
    }
}
