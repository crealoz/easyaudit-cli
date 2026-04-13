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
                'longDescription' => 'Detects helper classes that extend '
                    . '\Magento\Framework\App\Helper\AbstractHelper.' . "\n"
                    . 'Impact: Extending AbstractHelper ties the class to the Magento framework, '
                    . 'inheriting unnecessary constructor dependencies and making the class impossible '
                    . 'to mock in unit tests.' . "\n"
                    . 'Why change: This pattern is deprecated in Magento 2. AbstractHelper was a '
                    . 'Magento 1 convenience that has no place in modern modular code.' . "\n"
                    . 'How to fix: Remove the AbstractHelper extension. For presentation logic, '
                    . 'create a ViewModel. For business logic, create a plain service class with '
                    . 'constructor DI.',
                'files' => $this->extensionOfAbstractHelper,
            ];
        }

        if (!empty($this->helpersInsteadOfViewModels)) {
            $report[] = [
                'ruleId' => 'helpersInsteadOfViewModels',
                'name' => 'Helpers Instead of ViewModels',
                'shortDescription' => 'Template uses helper instead of ViewModel.',
                'longDescription' => 'Detects $this->helper() calls in phtml templates.' . "\n"
                    . 'Impact: Helpers are instantiated at layout load time regardless of whether the '
                    . 'template is rendered. This wastes resources and couples the template to the '
                    . 'Magento template engine.' . "\n"
                    . 'Why change: ViewModels are lighter, lazily resolved, and properly testable. '
                    . 'The helper() call in templates is a legacy pattern with no advantage over '
                    . 'ViewModels.' . "\n"
                    . 'How to fix: Replace with a ViewModel injected via layout XML. Access it with '
                    . '$block->getViewModel() in the template.',
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
                'high'
            );
        } else {
            // Helper extends AbstractHelper but not used in templates - still bad but less severe
            $msg = "Helper class '$className' extends deprecated AbstractHelper. Consider "
                . "refactoring to a simple utility class or service.";
            $this->extensionOfAbstractHelper[] = Formater::formatError(
                $phpFile,
                $lineNumber,
                $msg,
                'medium'
            );
        }
    }

    public function getName(): string
    {
        return 'Helpers';
    }

    public function getLongDescription(): string
    {
        return 'Identifies classes extending AbstractHelper and $this->helper() calls in phtml '
            . 'templates.' . "\n"
            . 'Impact: Helpers are instantiated at layout load time as part of the object graph, '
            . 'regardless of whether the template is rendered or the helper\'s methods are ever called. '
            . 'Their instantiation cost, and that of their own constructor dependencies, is paid on '
            . 'every request that triggers the layout.' . "\n"
            . 'Why change: Helpers extending AbstractHelper are nearly impossible to mock in unit tests, '
            . 'effectively excluding any consuming class from automated testing. The $this->helper() '
            . 'call in templates additionally couples templates to the Magento template engine.' . "\n"
            . 'How to fix: For presentation data in templates, create a ViewModel and inject it via '
            . 'layout XML. For shared business logic, create a service class with constructor DI. Some '
            . 'core Magento helpers (e.g., Data helpers) are exempted from this check.';
    }
}
