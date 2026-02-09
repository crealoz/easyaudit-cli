<?php

namespace EasyAudit\Tests\Core\Scan\Processor;

use EasyAudit\Core\Scan\Processor\DiAreaScope;
use PHPUnit\Framework\TestCase;

class DiAreaScopeTest extends TestCase
{
    private DiAreaScope $processor;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->processor = new DiAreaScope();
        $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures/DiAreaScope';
    }

    public function testGetIdentifierReturnsCorrectId(): void
    {
        $this->assertEquals('magento.di.global-area-scope', $this->processor->getIdentifier());
    }

    public function testGetFileTypeReturnsDi(): void
    {
        $this->assertEquals('di', $this->processor->getFileType());
    }

    public function testGetNameReturnsDescription(): void
    {
        $this->assertNotEmpty($this->processor->getName());
    }

    public function testDetectsFrontendPluginInGlobalScope(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testDetectsAdminhtmlInGlobalScope(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/global_admin_di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertGreaterThan(0, $this->processor->getFoundCount());
    }

    public function testIgnoresCorrectlyScoped(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Good/etc/frontend/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testIgnoresGlobalServicePlugin(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Good/etc/di.xml']];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testProcessWithEmptyFiles(): void
    {
        $files = ['di' => []];

        ob_start();
        $this->processor->process($files);
        ob_end_clean();

        $this->assertEquals(0, $this->processor->getFoundCount());
    }

    public function testGetReportFormat(): void
    {
        $files = ['di' => [$this->fixturesPath . '/Bad/etc/di.xml']];

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
}
