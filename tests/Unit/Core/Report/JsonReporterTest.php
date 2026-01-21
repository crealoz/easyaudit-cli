<?php

namespace EasyAudit\Tests\Core\Report;

use EasyAudit\Core\Report\JsonReporter;
use EasyAudit\Core\Report\ReporterInterface;
use PHPUnit\Framework\TestCase;

class JsonReporterTest extends TestCase
{
    private JsonReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new JsonReporter();
    }

    public function testImplementsReporterInterface(): void
    {
        $this->assertInstanceOf(ReporterInterface::class, $this->reporter);
    }

    public function testGenerateReturnsValidJson(): void
    {
        $findings = [
            [
                'ruleId' => 'useOfObjectManager',
                'name' => 'Object Manager Usage',
                'files' => [
                    ['file' => '/path/to/file.php', 'line' => 10],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
    }

    public function testGeneratePreservesAllData(): void
    {
        $findings = [
            [
                'ruleId' => 'testRule',
                'name' => 'Test Rule',
                'shortDescription' => 'Short desc',
                'longDescription' => 'Long desc',
                'files' => [
                    [
                        'file' => '/path/to/file.php',
                        'line' => 10,
                        'message' => 'Issue found',
                    ],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertEquals('testRule', $decoded[0]['ruleId']);
        $this->assertEquals('Test Rule', $decoded[0]['name']);
        $this->assertEquals('Short desc', $decoded[0]['shortDescription']);
        $this->assertEquals('Long desc', $decoded[0]['longDescription']);
        $this->assertEquals('/path/to/file.php', $decoded[0]['files'][0]['file']);
    }

    public function testGenerateHandlesEmptyFindings(): void
    {
        $result = $this->reporter->generate([]);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    public function testGenerateHandlesMultipleFindings(): void
    {
        $findings = [
            ['ruleId' => 'rule1', 'files' => []],
            ['ruleId' => 'rule2', 'files' => []],
            ['ruleId' => 'rule3', 'files' => []],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertCount(3, $decoded);
    }

    public function testGenerateOutputsFormattedJson(): void
    {
        $findings = [
            ['ruleId' => 'test', 'files' => []],
        ];

        $result = $this->reporter->generate($findings);

        // JSON_PRETTY_PRINT adds newlines and indentation
        $this->assertStringContainsString("\n", $result);
    }

    public function testGenerateHandlesNestedArrays(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    [
                        'file' => '/path/file.php',
                        'metadata' => [
                            'line' => 10,
                            'column' => 5,
                            'nested' => ['deep' => 'value'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertEquals('value', $decoded[0]['files'][0]['metadata']['nested']['deep']);
    }

    public function testGenerateHandlesSpecialCharacters(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'message' => 'Message with "quotes" and <tags>',
                'files' => [],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertEquals('Message with "quotes" and <tags>', $decoded[0]['message']);
    }

    public function testGenerateHandlesUnicodeCharacters(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'message' => 'Message with unicode: \u00e9\u00e8\u00ea',
                'files' => [],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
    }
}
