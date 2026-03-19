<?php

namespace EasyAudit\Core\Scan\Util;

/**
 * Parses di.xml files to build a registry of plugin-to-target class mappings.
 * Used by AroundPlugins to determine plugin stack depth per method.
 */
class PluginRegistry
{
    /** @var array<string, array<array{class: string, name: string, disabled: bool, diFile: string}>> */
    private static array $pluginsByTarget = [];

    /** @var array<string, string> pluginClass => targetClass */
    private static array $targetByPlugin = [];

    private static bool $built = false;

    /**
     * Parse all di.xml files and populate the plugin maps.
     *
     * @param array $diFiles List of di.xml file paths
     */
    public static function build(array $diFiles): void
    {
        if (self::$built) {
            return;
        }

        foreach ($diFiles as $file) {
            $xml = DiScope::loadXml($file);
            if ($xml === false) {
                continue;
            }
            self::parsePlugins($xml, $file);
        }

        self::$built = true;
    }

    /**
     * Reset the registry (for testing).
     */
    public static function reset(): void
    {
        self::$pluginsByTarget = [];
        self::$targetByPlugin = [];
        self::$built = false;
    }

    /**
     * Get the target class for a given plugin class.
     */
    public static function getTargetClass(string $pluginClass): ?string
    {
        return self::$targetByPlugin[$pluginClass] ?? null;
    }

    /**
     * Get all active (non-disabled) plugins for a given target class.
     *
     * @return array<array{class: string, name: string, disabled: bool, diFile: string}>
     */
    public static function getPluginsForTarget(string $targetClass): array
    {
        if (!isset(self::$pluginsByTarget[$targetClass])) {
            return [];
        }

        return array_filter(
            self::$pluginsByTarget[$targetClass],
            static fn(array $plugin): bool => !$plugin['disabled']
        );
    }

    /**
     * Check if the registry has been built.
     */
    public static function isBuilt(): bool
    {
        return self::$built;
    }

    private static function parsePlugins(\SimpleXMLElement $xml, string $diFile): void
    {
        $typeNodes = $xml->xpath('//type[plugin]');

        foreach ($typeNodes as $typeNode) {
            $targetClass = (string) $typeNode['name'];
            if (empty($targetClass)) {
                continue;
            }

            $pluginNodes = $typeNode->xpath('plugin');
            foreach ($pluginNodes as $pluginNode) {
                $pluginClass = (string) $pluginNode['type'];
                $pluginName = (string) $pluginNode['name'];
                $disabled = ((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                if (empty($pluginClass)) {
                    continue;
                }

                $entry = [
                    'class' => $pluginClass,
                    'name' => $pluginName,
                    'disabled' => $disabled,
                    'diFile' => $diFile,
                ];

                self::$pluginsByTarget[$targetClass][] = $entry;

                if (!$disabled) {
                    self::$targetByPlugin[$pluginClass] = $targetClass;
                }
            }
        }
    }
}
