<?php

namespace EasyAudit\Core\Scan\Processor;

/**
 * Class UnusedModules
 *
 * This processor identifies modules that are present in the codebase but disabled
 * in app/etc/config.php. These modules consume disk space and may cause confusion.
 *
 * Note: This processor requires access to app/etc/config.php in the Magento root.
 * If the file is not found, the processor will skip the check.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class UnusedModules extends AbstractProcessor
{
    private array $disabledModules = [];
    private ?array $configModules = null;

    public function getIdentifier(): string
    {
        return 'unusedModules';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->disabledModules)) {
            echo "  \033[34mi\033[0m Disabled modules: \033[1;34m" . count($this->disabledModules) . "\033[0m\n";
            $report[] = [
                'ruleId' => 'unusedModules',
                'name' => 'Unused Modules',
                'shortDescription' => 'Modules present in codebase but disabled in configuration.',
                'longDescription' => 'The following modules are present in the codebase but are disabled in app/etc/config.php. Consider removing them to reduce disk space usage and avoid confusion. Disabled modules do not load but still consume storage and may contain security vulnerabilities.',
                'files' => $this->disabledModules,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Identifies modules that are present in the codebase but disabled in app/etc/config.php.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        // Try to load config.php
        $this->loadMagentoConfig();

        if ($this->configModules === null) {
            echo "Warning: Could not find or read app/etc/config.php. Skipping unused modules check.\n";
            return;
        }

        // Process each module.xml file
        foreach ($files['xml'] as $file) {
            // Only process module.xml files
            if (!str_ends_with(basename($file), 'module.xml')) {
                continue;
            }

            $moduleName = $this->extractModuleName($file);

            if ($moduleName === null) {
                continue;
            }

            // Check if module is disabled in config.php
            if (isset($this->configModules[$moduleName]) && $this->configModules[$moduleName] === 0) {
                $this->foundCount++;
                $this->disabledModules[] = [
                    'module' => $moduleName,
                    'path' => $file,
                    'message' => "Module '$moduleName' is disabled in app/etc/config.php but still present in codebase.",
                    'level' => 'note'
                ];
            }
        }
    }

    /**
     * Try to load Magento's app/etc/config.php file
     */
    private function loadMagentoConfig(): void
    {
        $configPath = $this->findConfigPath();

        if ($configPath === null || !file_exists($configPath)) {
            $this->configModules = null;
            return;
        }

        try {
            $config = include $configPath;

            if (isset($config['modules']) && is_array($config['modules'])) {
                $this->configModules = $config['modules'];
            } else {
                $this->configModules = null;
            }
        } catch (\Exception $e) {
            echo "Error reading config.php: " . $e->getMessage() . "\n";
            $this->configModules = null;
        }
    }

    /**
     * Find the config.php file path
     * Tries multiple common locations relative to scan path
     *
     * @return string|null
     */
    private function findConfigPath(): ?string
    {
        // Get scan path from constant or current directory
        $scanPath = defined('EA_SCAN_PATH') ? EA_SCAN_PATH : getcwd();

        // Possible locations for config.php
        $possiblePaths = [
            $scanPath . '/app/etc/config.php',                    // Root of Magento
            $scanPath . '/../app/etc/config.php',                 // Scanning vendor or app/code
            $scanPath . '/../../app/etc/config.php',              // Deeper in directory
            $scanPath . '/../../../app/etc/config.php',           // Even deeper
            dirname($scanPath) . '/app/etc/config.php',           // Parent directory
            dirname(dirname($scanPath)) . '/app/etc/config.php',  // Two levels up
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Extract module name from module.xml file
     *
     * @param string $filePath
     * @return string|null
     */
    private function extractModuleName(string $filePath): ?string
    {
        $xml = @simplexml_load_file($filePath);

        if ($xml === false) {
            return null;
        }

        // module.xml format: <config><module name="Vendor_Module" .../>
        if (isset($xml->module['name'])) {
            return (string)$xml->module['name'];
        }

        return null;
    }

    public function getName(): string
    {
        return 'Unused Modules';
    }

    public function getLongDescription(): string
    {
        return 'This processor identifies modules that are present in the codebase but have been disabled in ' .
               'app/etc/config.php (with status 0). Disabled modules do not load when Magento runs, but they still: ' .
               '(1) Consume disk space, (2) May contain outdated or vulnerable code, (3) Can cause confusion for ' .
               'developers wondering why certain code is not executing, (4) Increase maintenance overhead. If a module ' .
               'is intentionally disabled, consider removing it entirely from the codebase. If it may be needed later, ' .
               'remove it and add it back via Composer when required. This keeps the codebase clean and reduces the ' .
               'attack surface. Note: This check requires access to app/etc/config.php and will be skipped if the ' .
               'file cannot be found or read.';
    }
}
