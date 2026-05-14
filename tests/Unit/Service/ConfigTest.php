<?php

namespace EasyAudit\Tests\Unit\Service;

use EasyAudit\Core\Report\HtmlReporter;
use EasyAudit\Core\Report\JsonReporter;
use EasyAudit\Core\Report\SarifReporter;
use EasyAudit\Service\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/easyaudit_config_test_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
    }

    public function testLoadDefaultConfigReturnsExpectedReporters(): void
    {
        $config = Config::load();

        $this->assertArrayHasKey('reporters', $config);
        $this->assertArrayHasKey('defaultFormat', $config);
        $this->assertSame(HtmlReporter::class, $config['reporters']['html']);
        $this->assertSame(SarifReporter::class, $config['reporters']['sarif']);
        $this->assertSame(JsonReporter::class, $config['reporters']['json']);
        $this->assertSame('html', $config['defaultFormat']);
    }

    public function testLoadIsCached(): void
    {
        $first = Config::load();
        $second = Config::load();
        $this->assertSame($first, $second);
    }

    public function testLoadFromCustomPath(): void
    {
        $path = $this->writeConfig([
            'reporters' => [
                'json' => JsonReporter::class,
            ],
            'defaultFormat' => 'json',
        ]);

        $config = Config::loadFrom($path);
        $this->assertSame(['json' => JsonReporter::class], $config['reporters']);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or unreadable/');
        Config::loadFrom($this->tmpDir . '/does-not-exist.json');
    }

    public function testInvalidJsonThrows(): void
    {
        $path = $this->tmpDir . '/bad.json';
        file_put_contents($path, '{ not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        Config::loadFrom($path);
    }

    public function testEmptyReportersThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => [],
            'defaultFormat' => 'json',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/non-empty 'reporters'/");
        Config::loadFrom($path);
    }

    public function testReporterClassMissingThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['html' => 'EasyAudit\\Does\\Not\\Exist'],
            'defaultFormat' => 'html',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reporter class not found/');
        Config::loadFrom($path);
    }

    public function testReporterClassNotImplementingInterfaceThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['html' => \stdClass::class],
            'defaultFormat' => 'html',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        Config::loadFrom($path);
    }

    public function testDefaultFormatNotInReportersThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'html',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not a registered reporter/');
        Config::loadFrom($path);
    }

    public function testMissingDefaultFormatThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/string 'defaultFormat'/");
        Config::loadFrom($path);
    }

    public function testSetPathOverrideAffectsLoad(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
        ]);

        Config::setPathOverride($path);
        $config = Config::load();

        $this->assertSame('json', $config['defaultFormat']);
        $this->assertCount(1, $config['reporters']);
    }

    public function testProcessorDirsAcceptedAndRoundTripped(): void
    {
        $absolute = $this->tmpDir;
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => [
                'Vendor\\Pack\\Processor' => $absolute,
            ],
        ]);

        $config = Config::loadFrom($path);

        $this->assertArrayHasKey('processorDirs', $config);
        $this->assertSame(['Vendor\\Pack\\Processor' => $absolute], $config['processorDirs']);
    }

    public function testProcessorDirsRelativePathsResolvedAgainstConfigDir(): void
    {
        mkdir($this->tmpDir . '/subdir', 0775);
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => [
                'Vendor\\Pack\\Processor' => 'subdir',
            ],
        ]);

        $config = Config::loadFrom($path);

        $expected = $this->tmpDir . DIRECTORY_SEPARATOR . 'subdir';
        $this->assertSame($expected, $config['processorDirs']['Vendor\\Pack\\Processor']);
    }

    public function testProcessorDirsAcceptsNonExistentDirectoryAtLoadTime(): void
    {
        // Sponsor overlays may list paths that only exist in some build flavors — load must not fail here;
        // Scanner skips missing directories at scan time instead.
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => [
                'Vendor\\Pack\\Processor' => '/path/that/does/not/exist/anywhere',
            ],
        ]);

        $config = Config::loadFrom($path);
        $this->assertSame('/path/that/does/not/exist/anywhere', $config['processorDirs']['Vendor\\Pack\\Processor']);
    }

    public function testProcessorDirsNonArrayThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => 'not-a-map',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/'processorDirs' must be a map/");
        Config::loadFrom($path);
    }

    public function testProcessorDirsEmptyNamespaceThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => ['' => '/tmp'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty namespace strings/');
        Config::loadFrom($path);
    }

    public function testProcessorDirsEmptyDirectoryThrows(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => ['Vendor\\Pack\\Processor' => ''],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a non-empty path/');
        Config::loadFrom($path);
    }

    public function testProcessorDirsEmptyMapIsAllowed(): void
    {
        $path = $this->writeConfig([
            'reporters' => ['json' => JsonReporter::class],
            'defaultFormat' => 'json',
            'processorDirs' => new \stdClass(), // json_encode → {}
        ]);

        $config = Config::loadFrom($path);
        $this->assertSame([], $config['processorDirs']);
    }

    private function writeConfig(array $data): string
    {
        $path = $this->tmpDir . '/config.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }
}
