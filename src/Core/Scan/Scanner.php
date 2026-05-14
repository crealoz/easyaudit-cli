<?php

namespace EasyAudit\Core\Scan;

use EasyAudit\Service\Api;
use EasyAudit\Service\CliWriter;
use EasyAudit\Service\Config;
use EasyAudit\Service\PayloadPreparers\PreparerInterface;
use EasyAudit\Service\Paths;

class Scanner
{
    /** Built-in processor namespace, used when `processorDirs` is absent or empty in config. */
    private const DEFAULT_PROCESSOR_NAMESPACE = 'EasyAudit\\Core\\Scan\\Processor';

    private static ?string $generatedPath = null;

    /**
     * Scan mode signal. Commands set this before calling Scanner::run() so processors can tune behavior for the
     * active feature (e.g. a `checkout-audit` mode triggers severity amplification on checkout-critical files).
     * Null means the default, general-purpose scan.
     */
    private static ?string $mode = null;

    private string $scanRoot = '';

    private array $excludePatterns = [];

    private array $excludedDirs = [
        '.',
        '..',
        '.git',
        '.svn',
        '.idea',
        'node_modules',
        'Test',
        'Tests',
        'vendor',
        'generated',
        'var',
        'pub',
        'setup',
        'lib',
        'dev',
        'phpserver',
        'update',
    ];

    private array $excludedFiles = [
        'LICENSE',
        'README.md',
        'CHANGELOG.md',
        'composer.json',
        'composer.lock',
        'package.json',
        'yarn.lock',
        'webpack.config.js',
        'gulpfile.js',
        'Gruntfile.js',
    ];

    private array $allowedExtensions = [
        'php',
        'phtml',
        'xml',
        'js',
        'di',
        'html',
    ];

    public function run(
        string $exclude = '',
        array $excludedExtensions = [],
        string $format = 'html'
    ): array {
        if (empty(EA_SCAN_PATH)) {
            $path = getcwd();
        } else {
            $path = Paths::getAbsolutePath(EA_SCAN_PATH);
        }

        $fixerReady = false;
        if ($format === 'json') {
            $fixerReady = true;
        }

        if (!empty($excludedExtensions)) {
            $this->allowedExtensions = array_diff($this->allowedExtensions, array_map('strtolower', $excludedExtensions));
        }

        if ($exclude != '') {
            $this->excludePatterns = array_map('trim', explode(',', $exclude));
        }

        if (self::isMagentoRoot($path)) {
            CliWriter::line("  Magento installation detected.");
            CliWriter::line("  Auto-excluded directories: " . implode(', ', $this->excludedDirs));
            $generatedDir = $path . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'code';
            if (is_dir($generatedDir) && count(scandir($generatedDir)) > 2) {
                self::$generatedPath = $generatedDir;
            } else {
                self::$generatedPath = null;
            }
        } else {
            self::$generatedPath = null;
        }

        $findings = [];
        $toolSuggestions = [];
        $files = [];
        foreach ($this->allowedExtensions as $ext) {
            $files[$ext] = [];
        }
        CliWriter::header("EasyAudit Scan");
        CliWriter::line("  Path: " . CliWriter::bold($path));
        if (!is_dir($path) && !is_file($path)) {
            $findings[] = "Path '$path' is not a valid directory or file.";
        }
        $this->scanRoot = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = $this->scanPaths($path, $files);

        $fixableTypes = [];
        if ($fixerReady) {
            $api = new Api();
            $fixableTypes = $api->getAllowedType();
        }

        if (empty($files)) {
            $findings[] = "No files found to scan.";
        } else {
            $processors = $this->getProcessors();
            foreach ($processors as $processor) {

                if (!isset($files[$processor->getFileType()])) {
                    $fileType = $processor->getFileType();
                    CliWriter::skipped("Skipping " . $processor->getName() . " (file type $fileType excluded)");
                    continue;
                }
                if (empty($files[$processor->getFileType()])) {
                    CliWriter::skipped("Skipping " . $processor->getName() . " (no " . $processor->getFileType() . " files)");
                    continue;
                }
                CliWriter::processorHeader($processor->getName());
                $processor->process($files);
                if ($processor->getFoundCount() > 0) {
                    foreach ($processor->getReport() as $report) {
                        $ruleId = $report['ruleId'] ?? '';
                        /** if the file needs to be ready for fixer, we need to remove useless information to improve
                         * file process performances
                         */
                        if ($fixerReady) {
                            unset($report['name']);
                            unset($report['shortDescription']);
                            unset($report['longDescription']);
                            // let's remove "message" entry from file as well
                            foreach ($report['files'] as $key => $_) {
                                unset($report['files'][$key]['message']);
                            }
                        }

                        // Check if this issue can be fixed by an external tool (phpcs or something like that)
                        if (ExternalToolMapping::isExternallyFixable($ruleId)) {
                            $fileCount = count($report['files'] ?? []);
                            $toolSuggestions[$ruleId] = ($toolSuggestions[$ruleId] ?? 0) + $fileCount;
                        } else {
                            $fixableKey = PreparerInterface::MAPPED_RULES[$ruleId] ?? $ruleId;
                        if (!$fixerReady || isset($fixableTypes[$fixableKey])) {
                                $findings[] = $report;
                            }
                        }
                    }
                }
            }
        }

        // Security vulnerability check (version-based, not file-based)
        CliWriter::processorHeader('Magento Version Security');
        $securityCheck = new MagentoVersionSecurityCheck();
        $securityFindings = $securityCheck->check($path);
        foreach ($securityFindings as $finding) {
            $findings[] = $finding;
        }

        return [
            'findings' => $findings,
            'toolSuggestions' => $toolSuggestions,
        ];
    }

    /**
     * Returns the path to generated/code/ if available and non-empty, null otherwise.
     */
    public static function getGeneratedPath(): ?string
    {
        return self::$generatedPath;
    }

    /**
     * Override the generated path (for testing).
     */
    public static function setGeneratedPath(?string $path): void
    {
        self::$generatedPath = $path;
    }

    /**
     * Return the active scan mode (e.g. 'checkout-audit') or null for a default scan.
     */
    public static function getMode(): ?string
    {
        return self::$mode;
    }

    /**
     * Set the active scan mode. Commands should call this before invoking run() and reset it to null in tests.
     */
    public static function setMode(?string $mode): void
    {
        self::$mode = $mode;
    }

    /**
     * Detect if the given path is the root of a Magento 2 installation.
     * Returns true if 2 or more indicators are found.
     */
    public static function isMagentoRoot(string $path): bool
    {
        $indicators = 0;
        if (file_exists($path . '/nginx.conf.sample')) {
            $indicators++;
        }
        if (file_exists($path . '/bin/magento')) {
            $indicators++;
        }
        if (file_exists($path . '/app/etc/env.php') || file_exists($path . '/app/etc/config.php')) {
            $indicators++;
        }
        if (is_dir($path . '/generated')) {
            $indicators++;
        }
        if (is_dir($path . '/pub')) {
            $indicators++;
        }
        return $indicators >= 2;
    }

    /**
     * Recursively scan paths and return list of files to scan. Exclude dirs, files and extensions as configured.
     *
     * @param  string $path
     * @param  array  $files
     * @return array
     */
    private function scanPaths(string $path, array $files): array
    {
        $dirContent = scandir($path);
        foreach ($dirContent as $entry) {
            if (in_array($entry, $this->excludedDirs)) {
                continue;
            }
            $fullPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullPath)) {
                if ($this->isExcluded($fullPath)) {
                    continue;
                }
                $files = $this->scanPaths($fullPath, $files);
                continue;
            }
            $entry = $fullPath;
            // Skip excluded extensions
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), $this->allowedExtensions)) {
                continue;
            }
            // Skip excluded files
            if (in_array(basename($entry), $this->excludedFiles)) {
                continue;
            }
            if ($this->isExcluded($entry)) {
                continue;
            }
            $ext = strtolower($ext);
            // If file is a di.xml, treat it as di
            if ($ext === 'xml' && str_ends_with(basename($entry), 'di.xml')) {
                $ext = 'di';
            }
            $files[$ext][] = $entry;
        }
        return $files;
    }

    /**
     * Check if a full path should be excluded based on exclude patterns.
     * Patterns without '/' match the basename (directory or file name).
     * Patterns with '/' match as a prefix of the relative path from the scan root.
     */
    private function isExcluded(string $fullPath): bool
    {
        $basename = basename($fullPath);
        $relativePath = str_replace($this->scanRoot, '', $fullPath);

        foreach ($this->excludePatterns as $pattern) {
            if (str_contains($pattern, '/')) {
                // Path pattern: match relative path prefix
                if (str_starts_with($relativePath, $pattern)) {
                    return true;
                }
            } else {
                // Simple name pattern: match basename
                if ($basename === $pattern) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get the list of processors to run on the files. Processors implement ProcessorInterface.
     *
     * The set of directories scanned is driven by `processorDirs` in `config/easyaudit.json`:
     *   - present and non-empty: every `namespace => directory` entry contributes processors.
     *   - absent or `{}`: falls back to the built-in `EasyAudit\Core\Scan\Processor` directory.
     *
     * Non-existent directories are skipped silently so sponsor overlays can list paths that only exist in some
     * build flavors.
     *
     * @return ProcessorInterface[]
     */
    protected function getProcessors(): array
    {
        $configured = Config::load()['processorDirs'] ?? [];
        $dirs = $configured !== []
            ? $configured
            : [self::DEFAULT_PROCESSOR_NAMESPACE => __DIR__ . '/Processor'];

        $processors = [];
        foreach ($dirs as $namespace => $directory) {
            foreach ($this->discoverProcessorsIn($namespace, $directory) as $processor) {
                $processors[] = $processor;
            }
        }
        return $processors;
    }

    /**
     * Scan a single directory for processor classes under the given namespace prefix and instantiate them.
     * Returns an empty array if the directory does not exist or contains no instantiable processors.
     *
     * @return ProcessorInterface[]
     */
    private function discoverProcessorsIn(string $namespace, string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = scandir($directory);
        if ($files === false) {
            return [];
        }
        $prefix = rtrim($namespace, '\\') . '\\';
        $processors = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $className = $prefix . pathinfo($file, PATHINFO_FILENAME);
            if (!class_exists($className)) {
                continue;
            }
            try {
                $processor = new $className();
            } catch (\Error | \Exception) {
                continue;
            }
            if ($processor instanceof ProcessorInterface) {
                $processors[] = $processor;
            }
        }
        return $processors;
    }
}
