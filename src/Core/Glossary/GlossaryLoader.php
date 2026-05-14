<?php

namespace EasyAudit\Core\Glossary;

use EasyAudit\Exception\Glossary\LanguageNotAvailableException;

class GlossaryLoader
{
    public const FALLBACK_LANGUAGE = 'en';

    private string $dataDir;

    /** @var array<string, array<string, array{term: string, shortDefinition: string, links: array<int, array{type: string, label: string, url: string}>, excludeFromAutoLink?: bool}>> */
    private array $cache = [];

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? __DIR__ . '/../../../data';
    }

    /**
     * @return array<string, array{term: string, shortDefinition: string, links: array<int, array{type: string, label: string, url: string}>, excludeFromAutoLink?: bool}>
     */
    public function load(string $language): array
    {
        if (isset($this->cache[$language])) {
            return $this->cache[$language];
        }

        $available = $this->availableLanguages();
        $resolved = $language;
        if (!in_array($resolved, $available, true)) {
            if (!in_array(self::FALLBACK_LANGUAGE, $available, true)) {
                throw new LanguageNotAvailableException(
                    "Glossary language '{$language}' not available and fallback '"
                    . self::FALLBACK_LANGUAGE . "' is missing. Available languages: "
                    . implode(', ', $available)
                );
            }
            $resolved = self::FALLBACK_LANGUAGE;
        }

        $path = $this->dataDir . '/' . $resolved . '/glossary.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new LanguageNotAvailableException("Failed to read glossary file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['concepts']) || !is_array($data['concepts'])) {
            throw new \RuntimeException("Glossary file is invalid: {$path}");
        }

        $this->cache[$language] = $data['concepts'];
        return $this->cache[$language];
    }

    /**
     * @return array<int, string>
     */
    public function availableLanguages(): array
    {
        $languages = [];
        if (!is_dir($this->dataDir)) {
            return $languages;
        }
        foreach (glob($this->dataDir . '/*/glossary.json') ?: [] as $file) {
            $languages[] = basename(dirname($file));
        }
        sort($languages);
        return $languages;
    }
}
