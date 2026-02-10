<?php

namespace EasyAudit\Tests\Unit\Service;

use EasyAudit\Service\ProjectIdentifier;
use PHPUnit\Framework\TestCase;

class ProjectIdentifierTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/easyaudit_projid_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testResolveUsesCliArgWhenProvided(): void
    {
        $result = ProjectIdentifier::resolve('my-custom-project', $this->tempDir);

        // Should start with the slugified CLI arg
        $this->assertStringStartsWith('my-custom-project-', $result);
    }

    public function testResolveAppendDatetimeSuffix(): void
    {
        $result = ProjectIdentifier::resolve('test', $this->tempDir);

        // Should have datetime format YYYYMMDD-HHMMSS at the end
        $this->assertMatchesRegularExpression('/test-\d{8}-\d{6}$/', $result);
    }

    public function testResolveFallsBackToComposerJson(): void
    {
        $composerContent = json_encode(['name' => 'vendor/my-package']);
        file_put_contents($this->tempDir . '/composer.json', $composerContent);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should start with slugified composer name
        $this->assertStringStartsWith('vendor-my-package-', $result);
    }

    public function testResolveFallsBackToModuleXml(): void
    {
        mkdir($this->tempDir . '/etc', 0777, true);
        $moduleXml = <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <module name="Vendor_ModuleName" setup_version="1.0.0"/>
</config>
XML;
        file_put_contents($this->tempDir . '/etc/module.xml', $moduleXml);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should start with slugified module name
        $this->assertStringStartsWith('vendor-modulename-', $result);
    }

    public function testResolveFallsBackToUniqid(): void
    {
        // No composer.json, no module.xml, no CLI arg
        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should start with 'project-' followed by 8 characters
        $this->assertMatchesRegularExpression('/^project-[a-f0-9]{8}-\d{8}-\d{6}$/', $result);
    }

    public function testResolveSlugifyRemovesSpecialCharacters(): void
    {
        $result = ProjectIdentifier::resolve('My Project! @#$% Name', $this->tempDir);

        // Should only contain alphanumeric and dashes
        $baseName = explode('-', $result);
        // Extract the project name part (everything before the date)
        $projectParts = array_slice($baseName, 0, -2);
        $projectName = implode('-', $projectParts);

        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $projectName);
    }

    public function testResolveSlugifyConvertsToLowercase(): void
    {
        $result = ProjectIdentifier::resolve('MyProject', $this->tempDir);

        $this->assertStringStartsWith('myproject-', $result);
    }

    public function testResolveSlugifyReplacesSlashesWithDashes(): void
    {
        $composerContent = json_encode(['name' => 'vendor/package-name']);
        file_put_contents($this->tempDir . '/composer.json', $composerContent);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        $this->assertStringStartsWith('vendor-package-name-', $result);
        $this->assertStringNotContainsString('/', $result);
    }

    public function testResolveSlugifyReplacesUnderscoresWithDashes(): void
    {
        $result = ProjectIdentifier::resolve('my_project_name', $this->tempDir);

        $this->assertStringStartsWith('my-project-name-', $result);
        $this->assertStringNotContainsString('_', $result);
    }

    public function testResolveSlugifyCollapsesDashes(): void
    {
        $result = ProjectIdentifier::resolve('my---project', $this->tempDir);

        // Should collapse multiple dashes
        $this->assertStringNotContainsString('---', $result);
    }

    public function testResolveSlugifyTruncatesTo32Characters(): void
    {
        $longName = str_repeat('a', 50);
        $result = ProjectIdentifier::resolve($longName, $this->tempDir);

        // Base name should be max 32 chars, plus datetime suffix
        $parts = explode('-', $result);
        $baseParts = array_slice($parts, 0, -2);
        $baseName = implode('-', $baseParts);

        $this->assertLessThanOrEqual(32, strlen($baseName));
    }

    public function testResolveChecksAppCodeComposer(): void
    {
        // Create Magento app/code structure
        mkdir($this->tempDir . '/app/code/CustomVendor/CustomModule', 0777, true);
        $composerContent = json_encode(['name' => 'custom-vendor/custom-module']);
        file_put_contents($this->tempDir . '/app/code/CustomVendor/CustomModule/composer.json', $composerContent);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        $this->assertStringStartsWith('custom-vendor-custom-module-', $result);
    }

    public function testResolveChecksAppCodeModuleXml(): void
    {
        // Create Magento app/code structure with module.xml
        mkdir($this->tempDir . '/app/code/TestVendor/TestModule/etc', 0777, true);
        $moduleXml = <<<'XML'
<?xml version="1.0"?>
<config><module name="TestVendor_TestModule"/></config>
XML;
        file_put_contents($this->tempDir . '/app/code/TestVendor/TestModule/etc/module.xml', $moduleXml);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        $this->assertStringStartsWith('testvendor-testmodule-', $result);
    }

    public function testResolveHandlesEmptyComposerName(): void
    {
        $composerContent = json_encode(['description' => 'No name field']);
        file_put_contents($this->tempDir . '/composer.json', $composerContent);

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should fall back to uniqid since no name field
        $this->assertStringStartsWith('project-', $result);
    }

    public function testResolveHandlesMalformedComposerJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', 'not valid json');

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should fall back to uniqid
        $this->assertStringStartsWith('project-', $result);
    }

    public function testResolveHandlesMalformedModuleXml(): void
    {
        mkdir($this->tempDir . '/etc', 0777, true);
        file_put_contents($this->tempDir . '/etc/module.xml', 'not valid xml');

        $result = ProjectIdentifier::resolve(null, $this->tempDir);

        // Should fall back to uniqid
        $this->assertStringStartsWith('project-', $result);
    }

    public function testResolvePreferenceOrder(): void
    {
        // Create both composer.json and module.xml
        mkdir($this->tempDir . '/etc', 0777, true);

        $composerContent = json_encode(['name' => 'composer/name']);
        file_put_contents($this->tempDir . '/composer.json', $composerContent);

        $moduleXml = '<config><module name="Module_Name"/></config>';
        file_put_contents($this->tempDir . '/etc/module.xml', $moduleXml);

        // CLI arg should take precedence
        $result = ProjectIdentifier::resolve('cli-arg', $this->tempDir);
        $this->assertStringStartsWith('cli-arg-', $result);

        // Without CLI arg, composer.json should take precedence over module.xml
        $result = ProjectIdentifier::resolve(null, $this->tempDir);
        $this->assertStringStartsWith('composer-name-', $result);
    }

    public function testResolveTrimsTrailingDashesFromTruncation(): void
    {
        // Name that when truncated to 32 chars would end with a dash
        $name = str_repeat('ab-', 15); // 45 chars, truncated might end with dash
        $result = ProjectIdentifier::resolve($name, $this->tempDir);

        // Extract base name (before datetime)
        $parts = explode('-', $result);
        $baseParts = array_slice($parts, 0, -2);
        $baseName = implode('-', $baseParts);

        $this->assertStringNotMatchesFormat('%-', $baseName);
    }

    public function testResolveHandlesEmptyCliArg(): void
    {
        $composerContent = json_encode(['name' => 'fallback/name']);
        file_put_contents($this->tempDir . '/composer.json', $composerContent);

        // Empty string should fall through to composer.json
        $result = ProjectIdentifier::resolve('', $this->tempDir);

        $this->assertStringStartsWith('fallback-name-', $result);
    }
}
