<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Functions;
use EasyAudit\Core\Scan\Util\Types;
use EasyAudit\Exception\Scanner\InstantiationNotFoundException;
use EasyAudit\Service\CliWriter;

/**
 * Class CountOnCollection
 *
 * Detects count() usage on Magento collections instead of getSize().
 * count() loads all items into memory to count them, while getSize()
 * uses a COUNT SQL query which is much more efficient.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class CountOnCollection extends AbstractProcessor
{
    private array $collectionReturningMethods = [];

    public function getIdentifier(): string
    {
        return 'magento.performance.count-on-collection';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getName(): string
    {
        return 'count() on Collection';
    }

    public function getMessage(): string
    {
        return 'Detects count() usage on collections instead of getSize().';
    }

    public function getLongDescription(): string
    {
        return 'Using count() on a Magento collection forces it to load all items from the '
            . 'database into memory just to count them. This is extremely inefficient for large '
            . 'collections. Use getSize() instead, which executes a COUNT(*) SQL query and '
            . 'returns the result without loading any items. This applies to both PHP\'s '
            . 'count($collection) function and the collection\'s own ->count() method.';
    }

    public function process(array $files): void
    {
        // Phase 1: Analyze PHP files for count() usage and build collection-returning method map
        if (!empty($files['php'])) {
            foreach ($files['php'] as $file) {
                $fileContent = file_get_contents($file);
                if ($fileContent === false) {
                    continue;
                }

                $this->analyzeFile($file, $fileContent);
                $this->mapCollectionReturningMethods($file, $fileContent);
            }
        }

        // Phase 2: Analyze phtml templates for count() on collection variables
        if (!empty($files['phtml'])) {
            foreach ($files['phtml'] as $phtmlFile) {
                $this->analyzePhtmlFile($phtmlFile);
            }
        }

        if (!empty($this->results)) {
            CliWriter::resultLine('count() on collection (use getSize())', count($this->results), 'warning');
        }
    }

    private function analyzeFile(string $file, string $fileContent): void
    {
        $constructorParams = Classes::parseConstructorParameters($fileContent);
        if (empty($constructorParams)) {
            return;
        }

        $importedClasses = Classes::parseImportedClasses($fileContent);
        $consolidated = Classes::consolidateParameters($constructorParams, $importedClasses);

        foreach ($consolidated as $paramName => $paramClass) {
            $isDirect = Types::isCollectionType($paramClass);
            $isFactory = Types::isCollectionFactoryType($paramClass);

            if (!$isDirect && !$isFactory) {
                continue;
            }

            try {
                $property = Classes::getInstantiation($constructorParams, $paramName, $fileContent);
            } catch (InstantiationNotFoundException) {
                continue;
            }

            if ($property === null) {
                continue;
            }

            if ($isDirect) {
                $this->detectCountUsage($file, $fileContent, $property);
            } else {
                $this->detectFactoryCountUsage($file, $fileContent, $property);
            }
        }
    }

    private function detectFactoryCountUsage(string $file, string $fileContent, string $property): void
    {
        $cleanProperty = preg_quote($property, '/');

        // Find: $var = $this->property->create(...)
        $createPattern = '/(\$\w+)\s*=\s*' . $cleanProperty . '->create\s*\(/';
        if (!preg_match_all($createPattern, $fileContent, $createMatches)) {
            return;
        }

        $localVars = array_unique($createMatches[1]);
        foreach ($localVars as $localVar) {
            $this->detectCountUsage($file, $fileContent, $localVar);
        }
    }

    private function detectCountUsage(string $file, string $fileContent, string $property): void
    {
        $cleanProperty = preg_quote($property, '/');

        // Include variable reference in metadata for the fixer
        $metadata = [$property];

        // Detect: count($this->property)
        $phpCountPattern = '/\bcount\s*\(\s*' . $cleanProperty . '\s*\)/';
        if (preg_match_all($phpCountPattern, $fileContent, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($fileContent, 0, $match[1]), "\n") + 1;
                $msg = "count({$property}) loads all collection items into memory. "
                    . "Use {$property}->getSize() instead for a COUNT(*) SQL query.";
                $this->results[] = Formater::formatError($file, $line, $msg, 'warning', 0, $metadata);
                $this->foundCount++;
            }
        }

        // Detect: $this->property->count()
        $methodCountPattern = '/' . $cleanProperty . '->count\s*\(/';
        if (preg_match_all($methodCountPattern, $fileContent, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($fileContent, 0, $match[1]), "\n") + 1;
                $msg = "{$property}->count() loads all collection items into memory. "
                    . "Use {$property}->getSize() instead for a COUNT(*) SQL query.";
                $this->results[] = Formater::formatError($file, $line, $msg, 'warning', 0, $metadata);
                $this->foundCount++;
            }
        }
    }

    /**
     * Build a map of methods that return collections created from CollectionFactory.
     */
    private function mapCollectionReturningMethods(string $file, string $fileContent): void
    {
        $constructorParams = Classes::parseConstructorParameters($fileContent);
        if (empty($constructorParams)) {
            return;
        }

        $importedClasses = Classes::parseImportedClasses($fileContent);
        $consolidated = Classes::consolidateParameters($constructorParams, $importedClasses);

        // Find factory properties
        $factoryProperties = [];
        foreach ($consolidated as $paramName => $paramClass) {
            if (!Types::isCollectionFactoryType($paramClass)) {
                continue;
            }
            try {
                $property = Classes::getInstantiation($constructorParams, $paramName, $fileContent);
            } catch (InstantiationNotFoundException) {
                continue;
            }
            if ($property !== null) {
                $factoryProperties[] = $property;
            }
        }

        if (empty($factoryProperties)) {
            return;
        }

        $fqcn = Classes::extractClassName($fileContent);

        // Find methods that return a variable created from factory->create()
        foreach ($factoryProperties as $factoryProp) {
            $cleanProp = preg_quote($factoryProp, '/');
            // Match: $var = $this->prop->create(...)
            $createPattern = '/(\$\w+)\s*=\s*' . $cleanProp . '->create\s*\(/';
            if (!preg_match_all($createPattern, $fileContent, $createMatches)) {
                continue;
            }

            $localVars = array_unique($createMatches[1]);
            foreach ($localVars as $localVar) {
                $cleanVar = preg_quote($localVar, '/');
                // Find methods that contain this assignment and return the variable
                $this->findReturningMethods($fileContent, $fqcn, $cleanVar);
            }
        }
    }

    /**
     * Find methods that return a given local variable and register them.
     */
    private function findReturningMethods(string $fileContent, string $fqcn, string $cleanVar): void
    {
        // Match: function methodName(...)  {
        $methodPattern = '/function\s+(\w+)\s*\([^)]*\)[^{]*\{/';
        if (!preg_match_all($methodPattern, $fileContent, $methodMatches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($methodMatches[0] as $index => $match) {
            $methodName = $methodMatches[1][$index][0];
            if ($methodName === '__construct') {
                continue;
            }

            $methodBody = Functions::extractBraceBlock($fileContent, $match[1]);
            if ($methodBody === null) {
                continue;
            }

            // Check: contains $var = ... ->create() AND return $var;
            if (
                preg_match('/' . $cleanVar . '\s*=\s*\$this->\w+->create\s*\(/', $methodBody)
                && preg_match('/return\s+' . $cleanVar . '\s*;/', $methodBody)
            ) {
                $this->collectionReturningMethods[$fqcn . '::' . $methodName] = true;
            }
        }
    }

    /**
     * Analyze a phtml template for count() usage on collection variables.
     */
    private function analyzePhtmlFile(string $phtmlFile): void
    {
        $content = @file_get_contents($phtmlFile);
        if ($content === false) {
            return;
        }

        // Extract @var annotation for $block
        if (!preg_match('/@var\s+\\\\?([\w\\\\]+)\s+\$block\b/', $content, $varMatch)) {
            return;
        }

        $blockClass = str_replace('\\\\', '\\', $varMatch[1]);
        $methods = $this->getCollectionMethodsForClass($blockClass);
        if (empty($methods)) {
            return;
        }

        foreach ($methods as $methodName) {
            $cleanMethod = preg_quote($methodName, '/');

            // Find: $var = $block->methodName(...)
            $assignPattern = '/(\$\w+)\s*=\s*\$block->' . $cleanMethod . '\s*\(/';
            if (preg_match_all($assignPattern, $content, $assignMatches)) {
                $localVars = array_unique($assignMatches[1]);
                foreach ($localVars as $localVar) {
                    $this->detectCountUsage($phtmlFile, $content, $localVar);
                }
            }

            // Detect chained: $block->methodName()->count()
            $chainedPattern = '/\$block->' . $cleanMethod . '\s*\([^)]*\)->count\s*\(/';
            if (preg_match_all($chainedPattern, $content, $chainMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($chainMatches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $msg = "\$block->{$methodName}()->count() loads all collection items into memory. "
                        . "Use \$block->{$methodName}()->getSize() instead for a COUNT(*) SQL query.";
                    $this->results[] = Formater::formatError($phtmlFile, $line, $msg, 'warning');
                    $this->foundCount++;
                }
            }

            // Detect chained: count($block->methodName())
            $countFuncPattern = '/\bcount\s*\(\s*\$block->' . $cleanMethod . '\s*\([^)]*\)\s*\)/';
            if (preg_match_all($countFuncPattern, $content, $countMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($countMatches[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $msg = "count(\$block->{$methodName}()) loads all collection items into memory. "
                        . "Use \$block->{$methodName}()->getSize() instead for a COUNT(*) SQL query.";
                    $this->results[] = Formater::formatError($phtmlFile, $line, $msg, 'warning');
                    $this->foundCount++;
                }
            }
        }
    }

    /**
     * Get collection-returning method names for a given block FQCN.
     */
    private function getCollectionMethodsForClass(string $blockClass): array
    {
        $methods = [];
        $prefix = $blockClass . '::';
        foreach (array_keys($this->collectionReturningMethods) as $key) {
            if (str_starts_with($key, $prefix)) {
                $methods[] = substr($key, strlen($prefix));
            }
        }
        return $methods;
    }
}
