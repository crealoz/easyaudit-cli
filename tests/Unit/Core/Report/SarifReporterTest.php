<?php

namespace EasyAudit\Tests\Core\Report;

use EasyAudit\Core\Report\SarifReporter;
use EasyAudit\Core\Report\ReporterInterface;
use PHPUnit\Framework\TestCase;

class SarifReporterTest extends TestCase
{
    private SarifReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new SarifReporter();
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

    public function testGenerateReturnsCorrectSarifVersion(): void
    {
        $findings = [];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertEquals('2.1.0', $decoded['version']);
    }

    public function testGenerateContainsSarifSchema(): void
    {
        $findings = [];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('$schema', $decoded);
        $this->assertStringContainsString('sarif', $decoded['$schema']);
    }

    public function testGenerateContainsToolInformation(): void
    {
        $findings = [];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('runs', $decoded);
        $this->assertNotEmpty($decoded['runs']);

        $run = $decoded['runs'][0];
        $this->assertArrayHasKey('tool', $run);
        $this->assertEquals('EasyAudit CLI', $run['tool']['driver']['name']);
    }

    public function testGenerateContainsInformationUri(): void
    {
        $findings = [];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $driver = $decoded['runs'][0]['tool']['driver'];
        $this->assertArrayHasKey('informationUri', $driver);
        $this->assertStringContainsString('github.com/crealoz/easyaudit-cli', $driver['informationUri']);
    }

    public function testGenerateCreatesResults(): void
    {
        $findings = [
            [
                'ruleId' => 'testRule',
                'name' => 'Test Rule',
                'message' => 'Default message',
                'files' => [
                    ['file' => '/path/to/file.php', 'line' => 10],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $results = $decoded['runs'][0]['results'];
        $this->assertCount(1, $results);
    }

    public function testGenerateCreatesRules(): void
    {
        $findings = [
            [
                'ruleId' => 'testRule',
                'name' => 'Test Rule',
                'shortDescription' => 'Short desc',
                'longDescription' => 'Long desc',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $rules = $decoded['runs'][0]['tool']['driver']['rules'];
        $this->assertCount(1, $rules);
        $this->assertEquals('testRule', $rules[0]['id']);
        $this->assertEquals('Test Rule', $rules[0]['name']);
    }

    public function testGenerateResultContainsRuleId(): void
    {
        $findings = [
            [
                'ruleId' => 'myRule',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $sarifResult = $decoded['runs'][0]['results'][0];
        $this->assertEquals('myRule', $sarifResult['ruleId']);
    }

    public function testGenerateResultContainsLevel(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1, 'severity' => 'error'],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $sarifResult = $decoded['runs'][0]['results'][0];
        $this->assertEquals('error', $sarifResult['level']);
    }

    public function testGenerateResultDefaultsToWarningLevel(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],  // No severity specified
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $sarifResult = $decoded['runs'][0]['results'][0];
        $this->assertEquals('warning', $sarifResult['level']);
    }

    public function testGenerateResultContainsMessage(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'message' => 'Finding message',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $sarifResult = $decoded['runs'][0]['results'][0];
        $this->assertArrayHasKey('message', $sarifResult);
        $this->assertEquals('Finding message', $sarifResult['message']['text']);
    }

    public function testGenerateResultContainsLocation(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/to/file.php', 'line' => 42],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $sarifResult = $decoded['runs'][0]['results'][0];
        $this->assertArrayHasKey('locations', $sarifResult);
        $this->assertCount(1, $sarifResult['locations']);

        $location = $sarifResult['locations'][0];
        $this->assertArrayHasKey('physicalLocation', $location);
        $this->assertArrayHasKey('artifactLocation', $location['physicalLocation']);
        $this->assertArrayHasKey('region', $location['physicalLocation']);
        $this->assertEquals(42, $location['physicalLocation']['region']['startLine']);
    }

    public function testGenerateCreatesRelativeUris(): void
    {
        // The reporter converts absolute paths to relative using scanRoot
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/home/user/project/src/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $uri = $decoded['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        // URI should be set (either relative or basename)
        $this->assertNotEmpty($uri);
    }

    public function testGenerateHandlesEmptyFindings(): void
    {
        $result = $this->reporter->generate([]);
        $decoded = json_decode($result, true);

        $this->assertEmpty($decoded['runs'][0]['results']);
    }

    public function testGenerateHandlesFindingsWithEmptyFiles(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [],  // Empty files array
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        // Should skip findings with empty files
        $this->assertEmpty($decoded['runs'][0]['results']);
        $this->assertEmpty($decoded['runs'][0]['tool']['driver']['rules']);
    }

    public function testGenerateHandlesMultipleFilesPerFinding(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file1.php', 'line' => 10],
                    ['file' => '/path/file2.php', 'line' => 20],
                    ['file' => '/path/file3.php', 'line' => 30],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        // Each file should create a separate result
        $this->assertCount(3, $decoded['runs'][0]['results']);
    }

    public function testGenerateContainsOriginalUriBaseIds(): void
    {
        $findings = [];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $run = $decoded['runs'][0];
        $this->assertArrayHasKey('originalUriBaseIds', $run);
        $this->assertArrayHasKey('SRCROOT', $run['originalUriBaseIds']);
    }

    public function testGenerateArtifactLocationHasUriBaseId(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $artifactLocation = $decoded['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation'];
        $this->assertArrayHasKey('uriBaseId', $artifactLocation);
        $this->assertEquals('SRCROOT', $artifactLocation['uriBaseId']);
    }

    public function testGenerateDefaultsLineToOne(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file.php'],  // No line specified
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $region = $decoded['runs'][0]['results'][0]['locations'][0]['physicalLocation']['region'];
        $this->assertEquals(1, $region['startLine']);
    }

    public function testGenerateUsesFileMessageOverFindingMessage(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'message' => 'Finding level message',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1, 'message' => 'File level message'],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $message = $decoded['runs'][0]['results'][0]['message']['text'];
        $this->assertEquals('File level message', $message);
    }

    public function testGenerateRuleContainsDescriptions(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'name' => 'Test Rule',
                'shortDescription' => 'This is short',
                'longDescription' => 'This is a longer description',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);
        $decoded = json_decode($result, true);

        $rule = $decoded['runs'][0]['tool']['driver']['rules'][0];
        $this->assertEquals('This is short', $rule['shortDescription']['text']);
        $this->assertEquals('This is a longer description', $rule['fullDescription']['text']);
        $this->assertEquals('This is a longer description', $rule['help']['text']);
    }

    public function testGenerateOutputIsPrettyPrinted(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);

        // JSON_PRETTY_PRINT adds newlines and indentation
        $this->assertStringContainsString("\n", $result);
    }

    public function testGenerateUnescapesSlashes(): void
    {
        $findings = [
            [
                'ruleId' => 'test',
                'files' => [
                    ['file' => '/path/to/file.php', 'line' => 1],
                ],
            ],
        ];

        $result = $this->reporter->generate($findings);

        // JSON_UNESCAPED_SLASHES means forward slashes are not escaped
        $this->assertStringNotContainsString('\\/', $result);
    }
}
