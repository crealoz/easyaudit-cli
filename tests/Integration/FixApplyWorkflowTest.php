<?php

namespace EasyAudit\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full Scan → Fix-Apply workflow.
 *
 * These tests verify the end-to-end workflow by:
 * 1. Running the scan command on test fixtures
 * 2. Verifying the report output
 * 3. Testing the fix-apply preparation logic (without actual API calls)
 *
 * For live API tests, use: ./tests/Integration/FixApplyWorkflowTest.sh
 */
class FixApplyWorkflowTest extends TestCase
{
    private string $projectRoot;
    private string $fixturesPath;
    private string $tempDir;
    private string $cliPath;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->fixturesPath = $this->projectRoot . '/tests/fixtures';
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_integration_' . uniqid();
        $this->cliPath = $this->projectRoot . '/bin/easyaudit';

        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    /**
     * Test 1: Scan command generates valid JSON report.
     */
    public function testScanGeneratesValidJsonReport(): void
    {
        $reportPath = $this->tempDir . '/report.json';

        // Run scan on UseOfObjectManager fixtures (known to have issues)
        $output = $this->runCli("scan {$this->fixturesPath}/UseOfObjectManager --format=json --output={$reportPath}");

        $this->assertFileExists($reportPath, 'Report file should be created');

        $json = file_get_contents($reportPath);
        $report = json_decode($json, true);

        $this->assertNotNull($report, 'Report should be valid JSON');
        $this->assertArrayHasKey('metadata', $report, 'Report should have metadata');

        // Should find replaceObjectManager issues
        $foundObjectManager = false;
        foreach ($report as $key => $finding) {
            if ($key === 'metadata') {
                continue;
            }
            if (($finding['ruleId'] ?? '') === 'replaceObjectManager') {
                $foundObjectManager = true;
                break;
            }
        }

        $this->assertTrue($foundObjectManager, 'Should detect replaceObjectManager issues');
    }

    /**
     * Test 2: Scan finds multiple rule types in combined fixtures.
     */
    public function testScanFindsMultipleRuleTypes(): void
    {
        $reportPath = $this->tempDir . '/multi-report.json';

        // Run scan on multiple fixture directories
        $output = $this->runCli("scan {$this->fixturesPath} --format=json --output={$reportPath}");

        $this->assertFileExists($reportPath);

        $report = json_decode(file_get_contents($reportPath), true);
        $this->assertNotNull($report);

        // Collect all rule IDs found
        $ruleIds = [];
        foreach ($report as $key => $finding) {
            if ($key !== 'metadata' && isset($finding['ruleId'])) {
                $ruleIds[] = $finding['ruleId'];
            }
        }

        // Should find at least 3 different rule types
        $uniqueRules = array_unique($ruleIds);
        $this->assertGreaterThanOrEqual(3, count($uniqueRules),
            'Should find at least 3 rule types. Found: ' . implode(', ', $uniqueRules));
    }

    /**
     * Test 3: Report format matches expected structure.
     */
    public function testReportStructureMatchesExpectedFormat(): void
    {
        $reportPath = $this->tempDir . '/structure-report.json';

        $this->runCli("scan {$this->fixturesPath}/HardWrittenSQL --format=json --output={$reportPath}");

        $report = json_decode(file_get_contents($reportPath), true);

        // Check metadata structure
        $this->assertArrayHasKey('metadata', $report);
        $this->assertArrayHasKey('scan_path', $report['metadata']);

        // Check finding structure
        foreach ($report as $key => $finding) {
            if ($key === 'metadata') {
                continue;
            }

            $this->assertArrayHasKey('ruleId', $finding, "Finding should have ruleId");
            $this->assertArrayHasKey('name', $finding, "Finding should have name");
            $this->assertArrayHasKey('files', $finding, "Finding should have files");
            $this->assertIsArray($finding['files'], "Files should be an array");

            // Check file structure
            foreach ($finding['files'] as $file) {
                $this->assertArrayHasKey('file', $file, "File entry should have 'file' key");
                $this->assertArrayHasKey('severity', $file, "File entry should have 'severity' key");
            }
        }
    }

    /**
     * Test 4: GeneralPreparer correctly prepares files from report.
     */
    public function testGeneralPreparerPreparesFilesCorrectly(): void
    {
        $preparer = new \EasyAudit\Service\PayloadPreparers\GeneralPreparer();

        // Simulate findings like those from a scan
        $findings = [
            [
                'ruleId' => 'replaceObjectManager',
                'files' => [
                    ['file' => '/tmp/test/Model/Product.php', 'metadata' => ['line' => 10]],
                    ['file' => '/tmp/test/Model/Category.php', 'metadata' => ['line' => 20]],
                ]
            ],
            [
                'ruleId' => 'aroundToBeforePlugin',
                'files' => [
                    ['file' => '/tmp/test/Plugin/ProductPlugin.php', 'metadata' => ['function' => 'beforeSave']],
                ]
            ],
        ];

        $fixables = [
            'replaceObjectManager' => 1,
            'aroundToBeforePlugin' => 2,
        ];

        $result = $preparer->prepareFiles($findings, $fixables);

        $this->assertCount(3, $result, 'Should prepare 3 files');
        $this->assertArrayHasKey('/tmp/test/Model/Product.php', $result);
        $this->assertArrayHasKey('/tmp/test/Model/Category.php', $result);
        $this->assertArrayHasKey('/tmp/test/Plugin/ProductPlugin.php', $result);
    }

    /**
     * Test 5: DiPreparer correctly identifies proxy rule files.
     */
    public function testDiPreparerIdentifiesProxyRules(): void
    {
        $preparer = new \EasyAudit\Service\PayloadPreparers\DiPreparer();

        // DiPreparer expects metadata with: diFile, type, argument, proxy
        $findings = [
            [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'files' => [
                    [
                        'file' => '/tmp/test/Model/HeavyService.php',
                        'metadata' => [
                            'diFile' => '/tmp/test/etc/di.xml',
                            'type' => 'Vendor\Module\Model\HeavyService',
                            'argument' => 'catalogSession',
                            'proxy' => 'Magento\Catalog\Model\Session\Proxy',
                        ]
                    ],
                ]
            ],
            [
                'ruleId' => 'replaceObjectManager',  // Not a proxy rule
                'files' => [
                    ['file' => '/tmp/test/Model/Other.php', 'metadata' => ['line' => 5]],
                ]
            ],
        ];

        // Rules are mapped: noProxyUsedForHeavyClasses -> proxyConfiguration
        $fixables = [
            'proxyConfiguration' => 1,
            'replaceObjectManager' => 1,
        ];

        $result = $preparer->prepareFiles($findings, $fixables);

        // DiPreparer should only handle proxy rules and group by di.xml file
        $this->assertCount(1, $result, 'DiPreparer should only process proxy rules');
        $this->assertArrayHasKey('/tmp/test/etc/di.xml', $result, 'Should group by di.xml file path');
    }

    /**
     * Test 6: Fix-apply command shows help when --help is passed.
     */
    public function testFixApplyShowsHelp(): void
    {
        $output = $this->runCli('fix-apply --help');

        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--confirm', $output);
        $this->assertStringContainsString('--patch-out', $output);
        $this->assertStringContainsString('--fix-by-rule', $output);
    }

    /**
     * Test 7: Fix-apply fails gracefully when report file doesn't exist.
     */
    public function testFixApplyFailsGracefullyWithMissingFile(): void
    {
        $output = $this->runCli('fix-apply /nonexistent/report.json', $exitCode);

        $this->assertStringContainsString('Invalid', $output);
        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * Test 8: Fix-apply fails gracefully with invalid JSON.
     */
    public function testFixApplyFailsGracefullyWithInvalidJson(): void
    {
        $invalidJsonPath = $this->tempDir . '/invalid.json';
        file_put_contents($invalidJsonPath, 'not valid json {');

        $output = $this->runCli("fix-apply {$invalidJsonPath} --confirm", $exitCode);

        $this->assertStringContainsString('Failed to parse JSON', $output);
        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * Test 9: SARIF output format is valid.
     */
    public function testSarifOutputIsValid(): void
    {
        $reportPath = $this->tempDir . '/sarif-report.sarif';

        $this->runCli("scan {$this->fixturesPath}/UseOfObjectManager --format=sarif --output={$reportPath}");

        $this->assertFileExists($reportPath);

        $sarif = json_decode(file_get_contents($reportPath), true);
        $this->assertNotNull($sarif, 'SARIF should be valid JSON');

        // Verify SARIF structure
        $this->assertArrayHasKey('$schema', $sarif);
        $this->assertArrayHasKey('version', $sarif);
        $this->assertEquals('2.1.0', $sarif['version']);
        $this->assertArrayHasKey('runs', $sarif);
        $this->assertIsArray($sarif['runs']);
        $this->assertNotEmpty($sarif['runs']);

        // Check run structure
        $run = $sarif['runs'][0];
        $this->assertArrayHasKey('tool', $run);
        $this->assertArrayHasKey('results', $run);
    }

    /**
     * Test 10: Verify specific processor detection accuracy.
     */
    public function testProcessorDetectionAccuracy(): void
    {
        // Test HardWrittenSQL processor
        $reportPath = $this->tempDir . '/sql-report.json';
        $this->runCli("scan {$this->fixturesPath}/HardWrittenSQL --format=json --output={$reportPath}");

        $report = json_decode(file_get_contents($reportPath), true);

        $sqlRuleFound = false;
        foreach ($report as $key => $finding) {
            if ($key === 'metadata') {
                continue;
            }
            if (strpos($finding['ruleId'] ?? '', 'sql') !== false) {
                $sqlRuleFound = true;
                // Verify it found issues in the bad file
                $files = array_column($finding['files'] ?? [], 'file');
                $foundBadFile = false;
                foreach ($files as $file) {
                    if (strpos($file, 'ValidSQL.php') !== false) {
                        $foundBadFile = true;
                        break;
                    }
                }
                $this->assertTrue($foundBadFile, 'Should detect issues in ValidSQL.php fixture');
            }
        }

        $this->assertTrue($sqlRuleFound, 'Should find SQL-related rule');
    }

    /**
     * Run the CLI and capture output.
     *
     * @param string $command CLI arguments
     * @param int|null $exitCode Reference to capture exit code
     * @return string Command output
     */
    private function runCli(string $command, ?int &$exitCode = null): string
    {
        $fullCommand = "php {$this->cliPath} {$command} 2>&1";
        $output = [];
        exec($fullCommand, $output, $exitCode);
        return implode("\n", $output);
    }

    /**
     * Recursively delete a directory.
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
