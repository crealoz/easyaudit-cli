<?php
namespace EasyAudit\Core\Scan;

use EasyAudit\Service\Api;
use EasyAudit\Support\Env;
use EasyAudit\Support\Paths;

class Scanner
{
    private array $excludePatterns = [];

    private array $excludedDirs = [
        '.',
        '..',
        '.git',
        '.svn',
        '.idea',
        'node_modules',
        'Tests',
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
    ];

    public function run(string $exclude = '', array $excludedExtensions = [], $onlyFixable = false): array
    {
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

        $errors = [];
        $files = [];
        foreach ($this->allowedExtensions as $ext) {
            $files[$ext] = [];
        }
        echo "Scanning path: $path\n";
        if (!is_dir($path) && !is_file($path)) {
            $errors[] = "Path '$path' is not a valid directory or file.";
        }
        $files = $this->scanPaths($path, $files);

        $fixableTypes = [];
        if ($onlyFixable) {
            $api = new Api(Env::isSelfSigned());
            $fixableTypes = $api->getAllowedType();
        }

        if (empty($files)) {
            $errors[] = "No files found to scan.";
        } else {
            $processors = $this->getProcessors();
            /** @var ProcessorInterface $processor */
            foreach ($processors as $processor) {
                if ($onlyFixable && !in_array($processor, $fixableTypes)) {
                    echo "Skipping processor: " . $processor->getIdentifier() . " (not fixable)\n";
                    continue;
                }

                if (!isset($files[$processor->getFileType()])) {
                    echo "Skipping processor: " . $processor->getIdentifier() . " (file type " . $processor->getFileType() . " is excluded)\n";
                    continue;
                }
                if (empty($files[$processor->getFileType()])) {
                    echo "Skipping processor: " . $processor->getIdentifier() . " (no files of type " . $processor->getFileType() . " found)\n";
                    continue;
                }
                echo "Running processor: " . $processor->getIdentifier() . "\n";
                $processor->process($files);
                if ($processor->getFoundCount() > 0) {
                    foreach ($processor->getReport() as $report) {
                        $errors[] = $report;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Recursively scan paths and return list of files to scan. Exclude dirs, files and extensions as configured.
     * @param string $path
     * @param array $files
     * @return array
     */
    private function scanPaths(string $path, array $files): array
    {
        $dirContent = scandir($path);
        foreach ($dirContent as $entry) {
            if (in_array($entry, $this->excludedDirs)) {
                continue;
            }
            $entry = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entry)) {
                $files = $this->scanPaths($entry, $files);
                continue;
            }
            // Skip excluded extensions
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), $this->allowedExtensions)) {
                continue;
            }
            // Skip excluded files
            if (in_array(basename($entry), $this->excludedFiles)) {
                continue;
            }
            if (in_array($entry, $this->excludePatterns)) {
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
     * Get the list of processors to run on the files. Processors implement ProcessorInterface and are located in the
     * EasyAudit\Core\Scan\Processors namespace.
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