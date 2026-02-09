<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class Helpers
 *
 * This processor detects:
 * 1. Helper classes extending deprecated AbstractHelper
 * 2. Helpers used in phtml templates (should use ViewModels instead)
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class Helpers extends AbstractProcessor
{
    private const ABSTRACT_HELPER_FQCN = 'Magento\\Framework\\App\\Helper\\AbstractHelper';

    /**
     * Magento core helpers that are allowed (legacy exceptions)
     */
    private array $ignoredHelpers = [
        'Magento\\Customer\\Helper\\Address',
        'Magento\\Tax\\Helper\\Data',
        'Magento\\Msrp\\Helper\\Data',
        'Magento\\Catalog\\Helper\\Output',
        'Magento\\Directory\\Helper\\Data',
    ];

    private array $helpersInPhtml = [];
    private array $extensionOfAbstractHelper = [];
    private array $helpersInsteadOfViewModels = [];

    public function getIdentifier(): string
    {
        return 'helpers';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->extensionOfAbstractHelper)) {
            $report[] = [
                'ruleId' => 'extensionOfAbstractHelper',
                'name' => 'Extension of AbstractHelper',
                'shortDescription' => 'Helper class extends deprecated AbstractHelper.',
                'longDescription' => 'Helper classes should not extend Magento\\Framework\\App\\'
                    . 'Helper\\AbstractHelper. This pattern is deprecated in Magento 2. Helpers '
                    . 'should be simple utility classes without framework dependencies, or better '
                    . 'yet, logic should be moved to ViewModels for presentation or to Service '
                    . 'classes for business logic.',
                'files' => $this->extensionOfAbstractHelper,
            ];
        }

        if (!empty($this->helpersInsteadOfViewModels)) {
            $report[] = [
                'ruleId' => 'helpersInsteadOfViewModels',
                'name' => 'Helpers Instead of ViewModels',
                'shortDescription' => 'Template uses helper instead of ViewModel.',
                'longDescription' => 'Templates should not use helpers for presentation logic. '
                    . 'ViewModels provide a clearer separation of concerns, are more testable, '
                    . 'and follow modern Magento 2 best practices. Move presentation logic from '
                    . 'helpers to ViewModels.',
                'files' => $this->helpersInsteadOfViewModels,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects deprecated Helper usage: classes extending AbstractHelper and helpers used in phtml templates.';
    }

    public function process(array $files): void
    {
        // First pass: scan phtml files for helper usage
        if (!empty($files['phtml'])) {
            foreach ($files['phtml'] as $phtmlFile) {
                $this->scanPhtmlForHelpers($phtmlFile);
            }
        }

        // Second pass: check PHP files for AbstractHelper extension
        if (!empty($files['php'])) {
            foreach ($files['php'] as $phpFile) {
                // Skip test files
                if (str_contains($phpFile, '/Test/') || str_contains($phpFile, '/tests/')) {
                    continue;
                }

                $this->checkPhpFileForHelper($phpFile);
            }
        }

        // Output counts for each rule type
        if (!empty($this->extensionOfAbstractHelper)) {
            $cnt = count($this->extensionOfAbstractHelper);
            echo "  \033[33m!\033[0m Helper classes extending AbstractHelper: ";
            echo "\033[1;33m" . $cnt . "\033[0m\n";
        }
        if (!empty($this->helpersInsteadOfViewModels)) {
            $cnt = count($this->helpersInsteadOfViewModels);
            echo "  \033[31m✗\033[0m Helpers used in templates (use ViewModels): ";
            echo "\033[1;31m" . $cnt . "\033[0m\n";
        }
    }

    /**
     * Scan phtml file for $this->helper() usage
     */
    private function scanPhtmlForHelpers(string $phtmlFile): void
    {
        $content = @file_get_contents($phtmlFile);
        if ($content === false) {
            return;
        }

        // Match: $this->helper('ClassName' or ClassName::class)
        preg_match_all('/\$this->helper\((.*?)\)/s', $content, $matches);

        if (empty($matches[1])) {
            return;
        }

        foreach ($matches[1] as $match) {
            $className = trim($match, '\'" ');
            $className = str_replace('::class', '', $className);

            // Skip ignored helpers
            if (in_array($className, $this->ignoredHelpers)) {
                continue;
            }

            // If short name (no backslash), try to resolve from use statements
            if (!str_contains($className, '\\')) {
                preg_match("/use\s+(.*\\\\$className);/", $content, $useMatches);
                if (!empty($useMatches[1])) {
                    $className = $useMatches[1];
                }
            }

            if (!isset($this->helpersInPhtml[$className])) {
                $this->helpersInPhtml[$className] = [];
            }

            $this->helpersInPhtml[$className][] = $phtmlFile;
        }
    }

    /**
     * Check PHP file for AbstractHelper extension
     */
    private function checkPhpFileForHelper(string $phpFile): void
    {
        $content = file_get_contents($phpFile);
        if ($content === false) {
            return;
        }

        // Check if it extends AbstractHelper
        if (!Classes::extendsClass($content, self::ABSTRACT_HELPER_FQCN)) {
            return;
        }

        $this->foundCount++;

        // Get the class name
        $className = Classes::extractClassName($content);
        if ($className === 'UnknownClass') {
            return;
        }

        $lineNumber = Classes::findClassDeclarationLine($content, 'AbstractHelper');

        // Check if this helper is used in phtml files
        $usedInPhtml = false;
        $phtmlFiles = [];

        // Check both with and without leading backslash
        $classNameVariants = [$className, ltrim($className, '\\')];

        foreach ($classNameVariants as $variant) {
            if (isset($this->helpersInPhtml[$variant])) {
                $usedInPhtml = true;
                $phtmlFiles = array_merge($phtmlFiles, $this->helpersInPhtml[$variant]);
            }
        }

        if ($usedInPhtml) {
            // Helper extends AbstractHelper AND is used in phtml - worse violation
            $cnt = count($phtmlFiles);
            $msg = "Helper class '$className' extends AbstractHelper and is used in $cnt "
                . "template(s). Move presentation logic to ViewModel instead.";
            $this->helpersInsteadOfViewModels[] = Formater::formatError(
                $phpFile,
                $lineNumber,
                $msg,
                'error'
            );
        } else {
            // Helper extends AbstractHelper but not used in templates - still bad but less severe
            $msg = "Helper class '$className' extends deprecated AbstractHelper. Consider "
                . "refactoring to a simple utility class or service.";
            $this->extensionOfAbstractHelper[] = Formater::formatError(
                $phpFile,
                $lineNumber,
                $msg,
                'warning'
            );
        }
    }

    public function getName(): string
    {
        return 'Helpers';
    }

    public function getLongDescription(): string
    {
        return 'This processor identifies deprecated Helper patterns in Magento 2: (1) Helper '
            . 'classes extending AbstractHelper - In Magento 1, helpers extended AbstractHelper '
            . 'to access framework functionality. In Magento 2, this pattern is deprecated. '
            . 'Helpers should be lightweight utility classes with no framework dependencies, '
            . 'or logic should be moved to appropriate layers (ViewModels for presentation, '
            . 'Services for business logic). (2) Helpers used in phtml templates - Templates '
            . 'using $this->helper() should migrate to ViewModels. ViewModels provide: better '
            . 'testability, clearer separation of concerns, no template engine coupling, '
            . 'reusability across multiple templates, type safety. To modernize: For '
            . 'presentation logic in templates: create ViewModels and inject them via layout '
            . 'XML. For business logic: create Service classes in Model/Service directory. '
            . 'For simple utilities: create standalone utility classes with static methods or '
            . 'injected dependencies. Some core Magento helpers are exempted as they are still '
            . 'part of the public API.';
    }
}
