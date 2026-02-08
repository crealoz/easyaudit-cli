<?php

namespace EasyAudit\Tests\Core\Report;

use EasyAudit\Core\Report\HtmlReporter;
use EasyAudit\Core\Report\ReporterInterface;
use PHPUnit\Framework\TestCase;

class HtmlReporterTest extends TestCase
{
    private HtmlReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new HtmlReporter();
    }

    public function testImplementsReporterInterface(): void
    {
        $this->assertInstanceOf(ReporterInterface::class, $this->reporter);
    }

    public function testGenerateReturnsHtmlWithDoctype(): void
    {
        $findings = $this->createFindings();
        $result = $this->reporter->generate($findings);

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('<html', $result);
        $this->assertStringContainsString('</html>', $result);
    }

    public function testGenerateContainsRuleName(): void
    {
        $findings = $this->createFindings();
        $result = $this->reporter->generate($findings);

        $this->assertStringContainsString('Object Manager Usage', $result);
    }

    public function testGenerateContainsSeverityBadges(): void
    {
        $findings = $this->createFindings();
        $result = $this->reporter->generate($findings);

        $this->assertStringContainsString('badge-error', $result);
    }

    public function testGenerateWithEmptyFindings(): void
    {
        $result = $this->reporter->generate([]);

        $this->assertStringContainsString('<!DOCTYPE html>', $result);
        $this->assertStringContainsString('No issues found', $result);
    }

    public function testGenerateCountsSeveritiesCorrectly(): void
    {
        $findings = [
            [
                'ruleId' => 'rule1',
                'name' => 'Rule One',
                'shortDescription' => 'First rule',
                'files' => [
                    ['file' => '/path/a.php', 'startLine' => 1, 'message' => 'error1', 'severity' => 'error'],
                    ['file' => '/path/b.php', 'startLine' => 2, 'message' => 'warn1', 'severity' => 'warning'],
                ],
            ],
            [
                'ruleId' => 'rule2',
                'name' => 'Rule Two',
                'shortDescription' => 'Second rule',
                'files' => [
                    ['file' => '/path/c.php', 'startLine' => 3, 'message' => 'note1', 'severity' => 'note'],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);

        // Should contain summary cards with totals
        $this->assertStringContainsString('Errors', $result);
        $this->assertStringContainsString('Warnings', $result);
        $this->assertStringContainsString('Notes', $result);
    }

    public function testGenerateEscapesSpecialCharacters(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'name' => 'Test <Script> Rule',
                'shortDescription' => 'Description with "quotes" & ampersands',
                'files' => [
                    [
                        'file' => '/path/to/file.php',
                        'startLine' => 1,
                        'message' => 'Message with <tags> & "quotes"',
                        'severity' => 'warning',
                    ],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);

        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringNotContainsString('<Script>', $result);
    }

    public function testGenerateContainsScanPath(): void
    {
        $findings = [
            'metadata' => ['scan_path' => '/var/www/magento'],
        ];

        $result = $this->reporter->generate($findings);
        $this->assertStringContainsString('/var/www/magento', $result);
    }

    public function testGenerateContainsTitle(): void
    {
        $result = $this->reporter->generate([]);
        $this->assertStringContainsString('<title>EasyAudit Report</title>', $result);
    }

    private function createFindings(): array
    {
        return [
            [
                'ruleId' => 'useOfObjectManager',
                'name' => 'Object Manager Usage',
                'shortDescription' => 'Direct use of ObjectManager detected.',
                'files' => [
                    [
                        'file' => '/path/to/file.php',
                        'startLine' => 10,
                        'message' => 'Found ObjectManager usage',
                        'severity' => 'error',
                    ],
                ],
            ],
        ];
    }
}
