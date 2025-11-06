<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;

/**
 * Class PaymentInterfaceUseAudit
 *
 * This processor detects payment methods that extend the deprecated AbstractMethod class.
 * Modern Magento payment methods should implement PaymentMethodInterface instead.
 *
 * @package EasyAudit\Core\Scan\Processor
 */
class PaymentInterfaceUseAudit extends AbstractProcessor
{
    private const DEPRECATED_CLASS = '\\Magento\\Payment\\Model\\Method\\AbstractMethod';
    private const DEPRECATED_CLASS_WITHOUT_SLASH = 'Magento\\Payment\\Model\\Method\\AbstractMethod';

    public function getIdentifier(): string
    {
        return 'extensionOfAbstractMethod';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function getReport(): array
    {
        $report = [];

        if (!empty($this->results)) {
            echo 'Deprecated payment method implementations found: ' . count($this->results) . PHP_EOL;
            $report[] = [
                'ruleId' => 'extensionOfAbstractMethod',
                'name' => 'Extension of Deprecated Payment AbstractMethod',
                'shortDescription' => 'Payment method extends deprecated AbstractMethod class.',
                'longDescription' => 'The class extends \\Magento\\Payment\\Model\\Method\\AbstractMethod which is deprecated in Magento 2. This approach to creating payment methods is no longer recommended. Modern payment methods should implement Magento\\Payment\\Api\\Data\\PaymentMethodInterface or extend one of the newer payment method base classes. Using the deprecated AbstractMethod can lead to compatibility issues with newer Magento versions and may not support newer payment features.',
                'files' => $this->results,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects payment methods that extend the deprecated AbstractMethod class instead of using PaymentMethodInterface.';
    }

    public function process(array $files): void
    {
        if (empty($files['php'])) {
            return;
        }

        foreach ($files['php'] as $file) {
            // Skip test files
            if (str_contains($file, '/Test/') || str_contains($file, '/tests/')) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Check for extension of AbstractMethod
            if ($this->extendsAbstractMethod($content)) {
                $this->foundCount++;

                // Find the line number of the class declaration
                $lineNumber = $this->findClassDeclarationLine($content);

                $this->results[] = Formater::formatError(
                    $file,
                    $lineNumber,
                    'This payment method extends the deprecated \\Magento\\Payment\\Model\\Method\\AbstractMethod. Consider implementing PaymentMethodInterface or using a modern payment base class instead.',
                    'error'
                );
            }
        }
    }

    /**
     * Check if the file extends AbstractMethod
     */
    private function extendsAbstractMethod(string $content): bool
    {
        // Check for both with and without leading backslash
        return str_contains($content, 'extends ' . self::DEPRECATED_CLASS) ||
               str_contains($content, 'extends ' . self::DEPRECATED_CLASS_WITHOUT_SLASH);
    }

    /**
     * Find the line number where the class is declared
     */
    private function findClassDeclarationLine(string $content): int
    {
        $lines = explode("\n", $content);

        foreach ($lines as $index => $line) {
            if (preg_match('/class\s+\w+\s+extends\s+.*AbstractMethod/', $line)) {
                return $index + 1; // Line numbers are 1-indexed
            }
        }

        // Fallback: find first occurrence of "extends"
        return Content::getLineNumber($content, 'extends \\Magento\\Payment\\Model\\Method\\AbstractMethod')
            ?: Content::getLineNumber($content, 'extends Magento\\Payment\\Model\\Method\\AbstractMethod')
            ?: 1;
    }

    public function getName(): string
    {
        return 'Payment Interface Use Audit';
    }

    public function getLongDescription(): string
    {
        return 'This processor identifies payment methods that extend the deprecated AbstractMethod class. ' .
               'In early versions of Magento 2, payment methods were created by extending ' .
               '\\Magento\\Payment\\Model\\Method\\AbstractMethod. This approach is now deprecated and not recommended. ' .
               'Modern Magento 2 payment methods should: (1) Implement Magento\\Payment\\Api\\Data\\PaymentMethodInterface ' .
               'for better API compatibility, (2) Use adapter patterns for third-party payment gateways, (3) Leverage ' .
               'newer payment method base classes that provide better separation of concerns. The deprecated AbstractMethod ' .
               'class may not support newer payment features such as: GraphQL support, modern payment flows, improved ' .
               'authorization and capture patterns, better vault (saved payment) support. Continuing to use AbstractMethod ' .
               'can lead to: compatibility issues with future Magento versions, inability to use newer payment features, ' .
               'difficulty integrating with modern payment service providers, maintenance challenges as the ecosystem moves ' .
               'away from this pattern. To modernize: review the Magento DevDocs for current payment method implementation ' .
               'patterns, consider using payment gateway adapters, implement PaymentMethodInterface, or extend from newer ' .
               'base classes provided by Magento or reputable third-party libraries.';
    }
}
