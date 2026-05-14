<?php

namespace EasyAudit\Tests\Unit\Core\Glossary;

use EasyAudit\Core\Glossary\GlossaryLoader;
use EasyAudit\Exception\Glossary\LanguageNotAvailableException;
use PHPUnit\Framework\TestCase;

class GlossaryLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/easyaudit_glossary_test_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testLoadShippedEnglishGlossary(): void
    {
        $loader = new GlossaryLoader();
        $concepts = $loader->load('en');

        $this->assertNotEmpty($concepts);
        $this->assertArrayHasKey('stateful', $concepts);
        $this->assertSame('Stateful', $concepts['stateful']['term']);
        $this->assertNotEmpty($concepts['stateful']['shortDefinition']);
        $this->assertIsArray($concepts['stateful']['links']);
    }

    public function testShippedGlossarySchemaSanity(): void
    {
        $loader = new GlossaryLoader();
        $concepts = $loader->load('en');

        foreach ($concepts as $slug => $entry) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $slug, "Slug {$slug} must be lowercase snake_case");
            $this->assertArrayHasKey('term', $entry);
            $this->assertArrayHasKey('shortDefinition', $entry);
            $this->assertArrayHasKey('links', $entry);
            $this->assertIsString($entry['term']);
            $this->assertIsString($entry['shortDefinition']);
            $this->assertIsArray($entry['links']);
            foreach ($entry['links'] as $link) {
                $this->assertArrayHasKey('url', $link);
                $this->assertStringStartsWith('https://', $link['url'], "Link URL for {$slug} must be HTTPS");
            }
        }
    }

    public function testAvailableLanguagesIncludesEn(): void
    {
        $loader = new GlossaryLoader();
        $this->assertContains('en', $loader->availableLanguages());
    }

    public function testUnavailableLanguageFallsBackToEnglish(): void
    {
        $loader = new GlossaryLoader();
        $fallback = $loader->load('zz');
        $english = $loader->load('en');

        $this->assertSame($english, $fallback);
        $this->assertArrayHasKey('stateful', $fallback);
    }

    public function testFallbackThrowsWhenEnglishIsAlsoMissing(): void
    {
        // Only 'fr' available — fallback 'en' is missing, so loading 'zz' must throw.
        mkdir($this->tmpDir . '/fr', 0775, true);
        file_put_contents(
            $this->tmpDir . '/fr/glossary.json',
            json_encode([
                'version' => '1.0',
                'concepts' => [
                    'foo' => ['term' => 'Foo', 'shortDefinition' => 'fr def', 'links' => []],
                ],
            ])
        );

        $loader = new GlossaryLoader($this->tmpDir);
        $this->expectException(LanguageNotAvailableException::class);
        $this->expectExceptionMessageMatches('/fallback.+en.+missing/');
        $loader->load('zz');
    }

    public function testCustomDataDirIsolation(): void
    {
        // Create a fake data dir with only 'fr'
        mkdir($this->tmpDir . '/fr', 0775, true);
        file_put_contents(
            $this->tmpDir . '/fr/glossary.json',
            json_encode([
                'version' => '1.0',
                'concepts' => [
                    'foo' => ['term' => 'Foo', 'shortDefinition' => 'fr def', 'links' => []],
                ],
            ])
        );

        $loader = new GlossaryLoader($this->tmpDir);
        $this->assertSame(['fr'], $loader->availableLanguages());

        $concepts = $loader->load('fr');
        $this->assertSame('Foo', $concepts['foo']['term']);

        // 'en' is requested as the fallback target itself, and it is missing — must throw.
        $this->expectException(LanguageNotAvailableException::class);
        $loader->load('en');
    }

    public function testLoadIsCachedPerLanguage(): void
    {
        $loader = new GlossaryLoader();
        $first = $loader->load('en');
        $second = $loader->load('en');
        $this->assertSame($first, $second);
    }

    public function testCorruptJsonThrows(): void
    {
        mkdir($this->tmpDir . '/en', 0775, true);
        file_put_contents($this->tmpDir . '/en/glossary.json', '{ broken');

        $loader = new GlossaryLoader($this->tmpDir);
        $this->expectException(\RuntimeException::class);
        $loader->load('en');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
