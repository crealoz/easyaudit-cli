<?php

namespace EasyAudit\Core\Scan;

use EasyAudit\Service\Api;
use EasyAudit\Service\CliWriter;
use EasyAudit\Service\Paths;

class Scanner
{
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
        $onlyFixable = false,
    ): array {
        if (empty(EA_SCAN_PATH)) {
            $path = getcwd();
        } else {
            $path = Paths::getAbsolutePath(EA_SCAN_PATH);
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
        if ($onlyFixable) {
            $api = new Api();
            $fixableTypes = $api->getAllowedType();
        }

        if (empty($files)) {
            $findings[] = "No files found to scan.";
        } else {
            $processors = $this->getProcessors();
            /** @var ProcessorInterface $processor */
            foreach ($processors as $processor) {
                if ($onlyFixable && !in_array($processor, $fixableTypes)) {
                    CliWriter::skipped("Skipping " . $processor->getName() . " (not fixable)");
                    continue;
                }

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

                        // Check if this issue can be fixed by an external tool
                        if (ExternalToolMapping::isExternallyFixable($ruleId)) {
                            $fileCount = count($report['files'] ?? []);
                            $toolSuggestions[$ruleId] = ($toolSuggestions[$ruleId] ?? 0) + $fileCount;
                        } else {
                            $findings[] = $report;
                        }
                    }
                }
            }
        }

        return [
            'findings' => $findings,
            'toolSuggestions' => $toolSuggestions,
        ];
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
     * Get the list of processors to run on the files. Processors implement ProcessorInterface and are located in the
     * EasyAudit\Core\Scan\Processors namespace.
     *
     * @return array
     */
    private function getProcessors(): array
    {
        $processors = [];
        $processorDir = __DIR__ . '/Processor';
        $files = scandir($processorDir);
        foreach ($files as $file) {
            if (str_ends_with($file, '.php')) {
                $className = 'EasyAudit\\Core\\Scan\\Processor\\' . pathinfo($file, PATHINFO_FILENAME);
                if (class_exists($className)) {
                    try {
                        $processor = new $className();
                        if ($processor instanceof ProcessorInterface) {
                            $processors[] = $processor;
                        }
                    } catch (\Error | \Exception $e) {
                        // Skip classes that cannot be instantiated
                        continue;
                    }
                }
            }
        }
        return $processors;
    }
}
