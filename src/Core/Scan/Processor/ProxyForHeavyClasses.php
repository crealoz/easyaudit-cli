<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\ClassToProxy;

/**
 * Class ProxyForHeavyClasses
 *
 * This processor checks if heavy classes (like Session) are injected without proxies.
 * Heavy classes should use proxies to avoid instantiation overhead when they're not used.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class ProxyForHeavyClasses extends AbstractProcessor
{
    /**
     * Classes that are considered "heavy" and should use proxies (pattern matching)
     */
    private array $heavyClassPatterns = [
        'Session',
        'Collection',
    ];

    private ClassToProxy $classToProxy;

    public function __construct()
    {
        $this->classToProxy = new ClassToProxy();
    }

    public function getIdentifier(): string
    {
        return 'noProxyUsedForHeavyClasses';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->results)) {
            echo "  \033[33m!\033[0m Classes without proxy for heavy dependencies: \033[1;33m" . count($this->results) . "\033[0m\n";
            $report[] = [
                'ruleId' => 'noProxyUsedForHeavyClasses',
                'name' => 'No Proxy for Heavy Classes',
                'shortDescription' => 'Heavy classes injected without proxy configuration.',
                'longDescription' => 'Some classes such as Session, Collection, and ResourceModel are heavy and should be injected through a proxy. This avoids performance issues when the class is instantiated. Using proxies improves performance especially when the class is not necessarily used, as the proxy delays instantiation until the first method call.',
                'files' => $this->results,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Checks if heavy classes (Session, Collection, ResourceModel) are injected without proxies.';
    }

    public function process(array $files): void
    {
        if (empty($files['php']) || empty($files['di'])) {
            return;
        }

        // First, index all di.xml files for quick lookup
        $diXmlFiles = $files['di'];

        // Process each PHP file
        foreach ($files['php'] as $file) {
            $this->processPhpFile($file, $diXmlFiles);
        }
    }

    /**
     * Process a PHP file to check for heavy class injections
     */
    private function processPhpFile(string $file, array $diXmlFiles): void
    {
        // Skip test files
        if (str_contains($file, '/Test/') || str_contains($file, '/tests/')) {
            return;
        }

        $fileContent = file_get_contents($file);
        if ($fileContent === false) {
            return;
        }

        // Get class name from file
        $className = $this->extractClassName($fileContent);
        if ($className === null) {
            return;
        }

        // Parse constructor parameters
        $constructorParameters = Classes::parseConstructorParameters($fileContent);
        if (empty($constructorParameters)) {
            return;
        }

        // Parse imported classes
        $importedClasses = Classes::parseImportedClasses($fileContent);

        // Consolidate to get full class names
        $consolidatedParameters = Classes::consolidateParameters($constructorParameters, $importedClasses);

        // Check each parameter for heavy classes
        foreach ($consolidatedParameters as $paramName => $paramClassName) {
            if ($this->isHeavyClass($paramClassName)) {
                // Check if proxy is configured in di.xml
                if (!$this->hasProxyInDiXml($className, $paramName, $paramClassName, $diXmlFiles)) {
                    $this->foundCount++;
                    $lineNumber = Content::getLineNumber($fileContent, $paramName);
                    $diFile = $this->findDiXmlForFile($file);

                    $this->results[] = Formater::formatError(
                        $file,
                        $lineNumber,
                        "Class '$className' injects heavy class '$paramClassName' (parameter \$$paramName) without a proxy. Consider configuring a proxy in di.xml to improve performance.",
                        'error',
                        0,
                        [
                            'diFile' => $diFile,
                            'type' => $className,
                            'argument' => ltrim($paramName, '$'),
                            'proxy' => $paramClassName . '\Proxy',
                        ]
                    );
                }
            }
        }
    }

    /**
     * Extract class name from file content
     */
    private function extractClassName(string $content): ?string
    {
        // Match: namespace Vendor\Module\...; followed by class ClassName
        if (preg_match('/namespace\s+([^;]+);.*class\s+(\w+)/s', $content, $matches)) {
            $namespace = trim($matches[1]);
            $class = trim($matches[2]);
            return $namespace . '\\' . $class;
        }

        return null;
    }

    /**
     * Check if a class is considered "heavy"
     */
    private function isHeavyClass(string $className): bool
    {
        // Skip factories and interfaces
        if (str_ends_with($className, 'Factory') || str_ends_with($className, 'Interface')) {
            return false;
        }

        // Check against heavy class patterns
        foreach ($this->heavyClassPatterns as $pattern) {
            if (str_contains($className, $pattern)) {
                return true;
            }
        }

        // Check against specific heavy classes (exact match)
        if ($this->classToProxy::isRequired($className)) {
            return true;
        }

        return false;
    }

    /**
     * Find the di.xml file for a given PHP file path.
     * Looks for etc/di.xml in the module root directory.
     *
     * @param string $phpFile Path to PHP file
     * @return string|null Path to di.xml or null if not found
     */
    private function findDiXmlForFile(string $phpFile): ?string
    {
        // Extract module root from path like /path/to/app/code/Vendor/Module/Model/Class.php
        // Module root would be /path/to/app/code/Vendor/Module/

        // Look for app/code/Vendor/Module pattern
        if (preg_match('#(.*?/app/code/[^/]+/[^/]+)/#', $phpFile, $matches)) {
            $moduleRoot = $matches[1];
            $diFile = $moduleRoot . '/etc/di.xml';
            if (file_exists($diFile)) {
                return $diFile;
            }
        }

        // Fallback: walk up directories looking for etc/di.xml
        $dir = dirname($phpFile);
        while ($dir !== '/' && $dir !== '.') {
            $diFile = $dir . '/etc/di.xml';
            if (file_exists($diFile)) {
                return $diFile;
            }
            // Stop if we hit app/code level
            if (str_ends_with($dir, '/app/code')) {
                break;
            }
            $dir = dirname($dir);
        }

        return null;
    }

    /**
     * Check if proxy is configured in di.xml for this class
     */
    private function hasProxyInDiXml(string $className, string $paramName, string $paramClassName, array $diXmlFiles): bool
    {
        // Look for proxy configuration in di.xml files
        foreach ($diXmlFiles as $diXmlFile) {
            $xml = @simplexml_load_file($diXmlFile);
            if ($xml === false) {
                continue;
            }

            // Check for: <type name="ClassName"><arguments><argument name="paramName">ParamClassName\Proxy</argument>
            $xpath = "//type[@name='$className']//argument[@name='$paramName']";
            $arguments = $xml->xpath($xpath);

            if (!empty($arguments)) {
                foreach ($arguments as $argument) {
                    $argumentValue = (string)$argument;
                    // Check if the argument value ends with \Proxy
                    if (str_ends_with($argumentValue, '\Proxy')) {
                        return true;
                    }
                    // Also check if it's the same class with \Proxy appended
                    if ($argumentValue === $paramClassName . '\Proxy') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getName(): string
    {
        return 'Proxy for Heavy Classes';
    }

    public function getLongDescription(): string
    {
        return 'This processor checks if heavy classes are injected without proxy configuration in di.xml. ' .
               'Heavy classes include Session classes (customer sessions, backend sessions, etc.), Collection classes ' .
               '(database query collections), and ResourceModel classes (database access layers). These classes are ' .
               'expensive to instantiate because they may: (1) Initialize database connections, (2) Load configuration, ' .
               '(3) Start sessions, (4) Query the database. When a heavy class is injected directly, it is instantiated ' .
               'every time the parent class is created, even if the heavy class is never used. Proxies solve this by ' .
               'creating a lightweight wrapper that delays instantiation until the first method call. This can ' .
               'significantly improve performance, especially for classes that are created frequently but don\'t always ' .
               'use all their dependencies. To fix: add proxy configuration in di.xml like: ' .
               '<type name="Your\\Class"><arguments><argument name="paramName" xsi:type="object">Heavy\\Class\\Proxy</argument></arguments></type>';
    }
}
