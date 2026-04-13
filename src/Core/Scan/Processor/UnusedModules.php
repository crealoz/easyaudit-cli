<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Xml;

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
                'longDescription' => 'Detects modules present in the codebase but disabled in '
                    . 'app/etc/config.php.' . "\n"
                    . 'Impact: Disabled modules still consume disk space, are indexed by the '
                    . 'autoloader, and can be re-enabled accidentally through setup:upgrade.' . "\n"
                    . 'Why change: Their presence creates confusion about what code is actually '
                    . 'active and they tend to fall out of sync with the rest of the codebase over '
                    . 'time.' . "\n"
                    . 'How to fix: Remove disabled modules entirely. Re-install via Composer if '
                    . 'needed later.',
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
                    'level' => 'low'
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
     * Traverses up from scan path until config.php is found or root is reached
     *
     * @return string|null
     */
    private function findConfigPath(): ?string
    {
        $scanPath = defined('EA_SCAN_PATH') ? EA_SCAN_PATH : getcwd();
        $currentPath = realpath($scanPath);

        while ($currentPath && $currentPath !== dirname($currentPath)) {
            $configPath = $currentPath . '/app/etc/config.php';
            if (file_exists($configPath)) {
                return $configPath;
            }
            $currentPath = dirname($currentPath);
        }

        return null;
    }

    /**
     * Extract module name from module.xml file
     *
     * @param  string $filePath
     * @return string|null
     */
    private function extractModuleName(string $filePath): ?string
    {
        $xml = Xml::loadFile($filePath);

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
        return 'Identifies modules present in the codebase but disabled in app/etc/config.php.' . "\n"
            . 'Impact: Disabled modules remain on disk, are indexed by the autoloader, and can be '
            . 're-enabled accidentally through setup:upgrade or configuration reset. Their schemas may '
            . 'still exist in the database.' . "\n"
            . 'Why change: For developers unfamiliar with the project, their presence creates confusion '
            . 'about what is actually active. Over time they fall out of sync with the rest of the '
            . 'codebase, making re-enabling them risky.' . "\n"
            . 'How to fix: Remove disabled modules from the codebase entirely. If needed later, '
            . 're-install them via Composer. This reduces repository size and eliminates ambiguity about '
            . 'active code.';
    }
}
