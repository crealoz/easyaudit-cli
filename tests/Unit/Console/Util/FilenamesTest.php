<?php

namespace EasyAudit\Tests\Console\Util;

use EasyAudit\Console\Util\Filenames;
use PHPUnit\Framework\TestCase;

class FilenamesTest extends TestCase
{
    // --- sanitize() tests ---

    public function testSanitizeStripsLeadingSlashes(): void
    {
        $result = Filenames::sanitize('/var/www/magento/app/code/Module.php');
        $this->assertStringStartsWith('var', $result);
    }

    public function testSanitizeReplacesPathSeparators(): void
    {
        $result = Filenames::sanitize('/var/www/Module.php');
        $this->assertStringNotContainsString('/', $result);
        $this->assertStringContainsString('_', $result);
    }

    public function testSanitizeRemovesPhpExtension(): void
    {
        $result = Filenames::sanitize('/path/to/MyClass.php');
        $this->assertStringNotContainsString('.php', $result);
    }

    public function testSanitizeRemovesXmlExtension(): void
    {
        $result = Filenames::sanitize('/path/to/di.xml');
        $this->assertStringNotContainsString('.xml', $result);
    }

    public function testSanitizeKeepsOtherExtensions(): void
    {
        $result = Filenames::sanitize('/path/to/config.json');
        $this->assertStringContainsString('.json', $result);
    }

    // --- getRelativePath() tests ---

    public function testGetRelativePathUnderRoot(): void
    {
        $result = Filenames::getRelativePath(
            '/var/www/magento/app/code/Vendor/Module/Model/MyClass.php',
            '/var/www/magento'
        );
        $this->assertEquals('app/code/Vendor/Module/Model/MyClass', $result);
    }

    public function testGetRelativePathOutsideRoot(): void
    {
        $result = Filenames::getRelativePath(
            '/other/path/MyClass.php',
            '/var/www/magento'
        );
        $this->assertEquals('other/path/MyClass', $result);
    }

    public function testGetRelativePathRemovesPhpExtension(): void
    {
        $result = Filenames::getRelativePath('/root/File.php', '/root');
        $this->assertEquals('File', $result);
    }

    public function testGetRelativePathRemovesXmlExtension(): void
    {
        $result = Filenames::getRelativePath('/root/etc/di.xml', '/root');
        $this->assertEquals('etc/di', $result);
    }

    // --- getSequencedPath() tests ---

    public function testGetSequencedPathReturnsBaseWhenNoConflict(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_filenames_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $result = Filenames::getSequencedPath('MyClass', $tempDir);
        $this->assertEquals('MyClass.patch', $result);

        rmdir($tempDir);
    }

    public function testGetSequencedPathReturnsSequencedWhenConflict(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_filenames_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        file_put_contents($tempDir . '/MyClass.patch', 'existing');

        $result = Filenames::getSequencedPath('MyClass', $tempDir);
        $this->assertEquals('MyClass-2.patch', $result);

        unlink($tempDir . '/MyClass.patch');
        rmdir($tempDir);
    }

    public function testGetSequencedPathIncrementsSequence(): void
    {
        $tempDir = sys_get_temp_dir() . '/easyaudit_filenames_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        file_put_contents($tempDir . '/MyClass.patch', 'existing');
        file_put_contents($tempDir . '/MyClass-2.patch', 'existing');

        $result = Filenames::getSequencedPath('MyClass', $tempDir);
        $this->assertEquals('MyClass-3.patch', $result);

        unlink($tempDir . '/MyClass.patch');
        unlink($tempDir . '/MyClass-2.patch');
        rmdir($tempDir);
    }
}
