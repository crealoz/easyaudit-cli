<?php

namespace EasyAudit\Service;

use EasyAudit\Console\CommandInterface;
use EasyAudit\Core\Report\ReporterInterface;

final class Config
{
    /**
     * @var array{
     *     reporters: array<string, class-string<ReporterInterface>>,
     *     defaultFormat: string,
     *     commands?: array<string, class-string<CommandInterface>>,
     *     fixer?: class-string<FixerInterface>
     * }|null
     */
    private static ?array $cache = null;
    private static ?string $pathOverride = null;

    /**
     * @return array{
     *     reporters: array<string, class-string<ReporterInterface>>,
     *     defaultFormat: string,
     *     commands?: array<string, class-string<CommandInterface>>,
     *     fixer?: class-string<FixerInterface>
     * }
     */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = self::loadFrom(self::path());
        return self::$cache;
    }

    /**
     * @return array{
     *     reporters: array<string, class-string<ReporterInterface>>,
     *     defaultFormat: string,
     *     commands?: array<string, class-string<CommandInterface>>,
     *     fixer?: class-string<FixerInterface>
     * }
     */
    public static function loadFrom(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("EasyAudit config file not found or unreadable: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read EasyAudit config file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("EasyAudit config file is not valid JSON: {$path}");
        }
        self::validate($data, $path);
        /**
         * @var array{
         *     reporters: array<string, class-string<ReporterInterface>>,
         *     defaultFormat: string,
         *     commands?: array<string, class-string<CommandInterface>>,
         *     fixer?: class-string<FixerInterface>
         * } $data
         */
        return $data;
    }

    public static function path(): string
    {
        if (self::$pathOverride !== null) {
            return self::$pathOverride;
        }
        $envPath = getenv('EA_CONFIG');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }
        return __DIR__ . '/../../config/easyaudit.json';
    }

    public static function setPathOverride(?string $path): void
    {
        self::$pathOverride = $path;
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
        self::$pathOverride = null;
    }

    private static function validate(array $data, string $path): void
    {
        if (!isset($data['reporters']) || !is_array($data['reporters']) || $data['reporters'] === []) {
            throw new \RuntimeException("EasyAudit config must define a non-empty 'reporters' map: {$path}");
        }
        foreach ($data['reporters'] as $format => $fqcn) {
            if (!is_string($format) || $format === '') {
                throw new \RuntimeException("EasyAudit config 'reporters' keys must be non-empty strings: {$path}");
            }
            if (!is_string($fqcn) || !class_exists($fqcn)) {
                throw new \RuntimeException("EasyAudit config reporter class not found for format '{$format}': {$fqcn}");
            }
            if (!is_subclass_of($fqcn, ReporterInterface::class)) {
                throw new \RuntimeException(
                    "EasyAudit config reporter '{$fqcn}' must implement " . ReporterInterface::class
                );
            }
        }
        if (!isset($data['defaultFormat']) || !is_string($data['defaultFormat'])) {
            throw new \RuntimeException("EasyAudit config must define a string 'defaultFormat': {$path}");
        }
        if (!array_key_exists($data['defaultFormat'], $data['reporters'])) {
            throw new \RuntimeException(
                "EasyAudit config 'defaultFormat' '{$data['defaultFormat']}' is not a registered reporter: {$path}"
            );
        }
        if (isset($data['commands'])) {
            if (!is_array($data['commands']) || $data['commands'] === []) {
                throw new \RuntimeException("EasyAudit config 'commands' must be a non-empty map: {$path}");
            }
            foreach ($data['commands'] as $name => $fqcn) {
                if (!is_string($name) || $name === '') {
                    throw new \RuntimeException("EasyAudit config 'commands' keys must be non-empty strings: {$path}");
                }
                if (!is_string($fqcn) || !class_exists($fqcn)) {
                    throw new \RuntimeException("EasyAudit config command class not found for '{$name}': {$fqcn}");
                }
                if (!is_subclass_of($fqcn, CommandInterface::class)) {
                    throw new \RuntimeException(
                        "EasyAudit config command '{$fqcn}' must implement " . CommandInterface::class
                    );
                }
            }
        }
        if (isset($data['fixer'])) {
            if (!is_string($data['fixer']) || !class_exists($data['fixer'])) {
                $shown = is_string($data['fixer']) ? $data['fixer'] : '<non-string>';
                throw new \RuntimeException("EasyAudit config 'fixer' class not found: {$shown}");
            }
            if (!is_subclass_of($data['fixer'], FixerInterface::class)) {
                throw new \RuntimeException(
                    "EasyAudit config 'fixer' '{$data['fixer']}' must implement " . FixerInterface::class
                );
            }
        }
    }
}
