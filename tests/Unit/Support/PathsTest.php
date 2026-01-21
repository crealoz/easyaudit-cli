<?php

namespace EasyAudit\Tests\Support;

use EasyAudit\Support\Paths;
use PHPUnit\Framework\TestCase;

class PathsTest extends TestCase
{
    private string $tempConfigDir;
    private ?string $originalXdgHome = null;
    private ?string $originalHome = null;

    protected function setUp(): void
    {
        $this->tempConfigDir = sys_get_temp_dir() . '/easyaudit_paths_test_' . uniqid();
        mkdir($this->tempConfigDir, 0777, true);

        // Save original env values
        $this->originalXdgHome = getenv('XDG_CONFIG_HOME') ?: null;
        $this->originalHome = getenv('HOME') ?: null;
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $easyauditDir = $this->tempConfigDir . '/easyaudit';
        if (is_dir($easyauditDir)) {
            $files = glob($easyauditDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($easyauditDir);
        }
        @rmdir($this->tempConfigDir);

        // Restore original env values
        if ($this->originalXdgHome !== null) {
            putenv('XDG_CONFIG_HOME=' . $this->originalXdgHome);
        } else {
            putenv('XDG_CONFIG_HOME');
        }
        if ($this->originalHome !== null) {
            putenv('HOME=' . $this->originalHome);
        }
    }

    public function testConfigDirUsesXdgConfigHome(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);

        $configDir = Paths::configDir();

        $this->assertEquals($this->tempConfigDir . '/easyaudit', $configDir);
    }

    public function testConfigDirCreatesDirectoryIfNotExists(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);

        $configDir = Paths::configDir();

        $this->assertDirectoryExists($configDir);
    }

    public function testConfigFileReturnsJsonPath(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);

        $configFile = Paths::configFile();

        $this->assertStringEndsWith('/config.json', $configFile);
    }

    public function testConfigFileReturnsLocalConfigIfExists(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);

        $easyauditDir = $this->tempConfigDir . '/easyaudit';
        @mkdir($easyauditDir, 0777, true);
        file_put_contents($easyauditDir . '/config.json.local', '{}');

        $configFile = Paths::configFile();

        $this->assertStringEndsWith('/config.json.local', $configFile);
    }

    public function testUpdateConfigFileCreatesFile(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir(); // Ensure directory exists

        Paths::updateConfigFile(['key' => 'value']);

        $configFile = Paths::configFile();
        $this->assertFileExists($configFile);

        $content = json_decode(file_get_contents($configFile), true);
        $this->assertEquals('value', $content['key']);
    }

    public function testUpdateConfigFileMergesWithExisting(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir(); // Ensure directory exists

        Paths::updateConfigFile(['key1' => 'value1']);
        Paths::updateConfigFile(['key2' => 'value2']);

        $configFile = Paths::configFile();
        $content = json_decode(file_get_contents($configFile), true);

        $this->assertEquals('value1', $content['key1']);
        $this->assertEquals('value2', $content['key2']);
    }

    public function testUpdateConfigFileOverwritesExistingKeys(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir(); // Ensure directory exists

        Paths::updateConfigFile(['key' => 'old']);
        Paths::updateConfigFile(['key' => 'new']);

        $configFile = Paths::configFile();
        $content = json_decode(file_get_contents($configFile), true);

        $this->assertEquals('new', $content['key']);
    }

    public function testGetConfigReturnsSingleEntry(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir();
        Paths::updateConfigFile(['api-url' => 'https://test.example.com']);

        $result = Paths::getConfig('api-url');

        $this->assertEquals('https://test.example.com', $result);
    }

    public function testGetConfigReturnsEmptyForMissingEntry(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir();
        Paths::updateConfigFile(['key' => 'value']);

        $result = Paths::getConfig('nonexistent');

        $this->assertEquals('', $result);
    }

    public function testGetConfigReturnsEmptyForMissingFile(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir();
        // Don't create config file

        $result = Paths::getConfig('any-key');

        $this->assertEquals('', $result);
    }

    public function testGetConfigReturnsArrayForMultipleEntries(): void
    {
        putenv('XDG_CONFIG_HOME=' . $this->tempConfigDir);
        Paths::configDir();
        Paths::updateConfigFile([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        $result = Paths::getConfig(['key1', 'key2', 'key3']);

        $this->assertIsArray($result);
        $this->assertEquals('value1', $result['key1']);
        $this->assertEquals('value2', $result['key2']);
        $this->assertEquals('', $result['key3']);  // Missing key
    }

    public function testGetAbsolutePathReturnsAbsolutePathUnchanged(): void
    {
        $path = '/absolute/path/to/file.php';

        $result = Paths::getAbsolutePath($path);

        $this->assertEquals($path, $result);
    }

    public function testGetAbsolutePathResolvesRelativePath(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_file_' . uniqid() . '.php';
        file_put_contents($tempFile, '<?php');

        $cwd = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $result = Paths::getAbsolutePath(basename($tempFile));
            $this->assertEquals($tempFile, $result);
        } finally {
            chdir($cwd);
            @unlink($tempFile);
        }
    }

    public function testGetAbsolutePathHandlesEmptyPath(): void
    {
        $result = Paths::getAbsolutePath('');

        $this->assertEquals(getcwd() ?: '/', $result);
    }

    public function testGetAbsolutePathHandlesDotPath(): void
    {
        $result = Paths::getAbsolutePath('.');

        $this->assertEquals(getcwd() ?: '/', $result);
    }

    public function testGetAbsolutePathHandlesDotSlashPath(): void
    {
        $result = Paths::getAbsolutePath('./');

        $this->assertEquals(getcwd() ?: '/', $result);
    }

    public function testGetAbsolutePathHandlesNonExistentFile(): void
    {
        $cwd = getcwd() ?: '/';
        $result = Paths::getAbsolutePath('nonexistent/path/file.php');

        // Should compose with CWD when realpath fails
        $this->assertEquals($cwd . '/nonexistent/path/file.php', $result);
    }

    public function testGetAbsolutePathTrimsLeadingDotSlash(): void
    {
        $cwd = getcwd() ?: '/';
        $result = Paths::getAbsolutePath('./somefile.php');

        // realpath will fail for nonexistent file, so it composes with CWD
        $this->assertStringNotContainsString('./', $result);
    }
}
