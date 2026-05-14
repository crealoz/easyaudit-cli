<?php

namespace EasyAudit\Core\Scan\Util;

/**
 * Parses di.xml files to build a registry of plugin-to-target class mappings.
 * Used by AroundPlugins to determine plugin stack depth per method.
 */
class PluginRegistry
{
    /** @var array<string, array<array{class: string, name: string, disabled: bool, diFile: string, line: int}>> */
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
            $dom = self::loadDom($file);
            if ($dom === null) {
                continue;
            }
            self::parsePlugins($dom, $file);
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
     * @return array<array{class: string, name: string, disabled: bool, diFile: string, line: int}>
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

    /**
     * Load a di.xml file into a DOMDocument. Uses DOM rather than SimpleXMLElement
     * so each <plugin> node's source line can be recovered via getLineNo().
     */
    private static function loadDom(string $file): ?\DOMDocument
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->load($file, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return $loaded ? $dom : null;
    }

    private static function parsePlugins(\DOMDocument $dom, string $diFile): void
    {
        $xpath = new \DOMXPath($dom);
        $typeNodes = $xpath->query('//type[plugin]');
        if ($typeNodes === false) {
            return;
        }

        foreach ($typeNodes as $typeNode) {
            if (!$typeNode instanceof \DOMElement) {
                continue;
            }
            $targetClass = $typeNode->getAttribute('name');
            if ($targetClass === '') {
                continue;
            }

            foreach ($typeNode->getElementsByTagName('plugin') as $pluginNode) {
                // Only direct <plugin> children of this <type>.
                if ($pluginNode->parentNode !== $typeNode) {
                    continue;
                }

                $pluginClass = $pluginNode->getAttribute('type');
                $pluginName = $pluginNode->getAttribute('name');
                $disabledAttr = $pluginNode->hasAttribute('disabled')
                    ? $pluginNode->getAttribute('disabled')
                    : 'false';
                $disabled = $disabledAttr === 'true';

                if ($pluginClass === '') {
                    continue;
                }

                $entry = [
                    'class' => $pluginClass,
                    'name' => $pluginName,
                    'disabled' => $disabled,
                    'diFile' => $diFile,
                    'line' => $pluginNode->getLineNo(),
                ];

                self::$pluginsByTarget[$targetClass][] = $entry;

                if (!$disabled) {
                    self::$targetByPlugin[$pluginClass] = $targetClass;
                }
            }
        }
    }
}
