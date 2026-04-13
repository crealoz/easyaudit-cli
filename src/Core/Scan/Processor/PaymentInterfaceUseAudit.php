<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Classes;
use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\CliWriter;

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
    private const DEPRECATED_FQCN = 'Magento\\Payment\\Model\\Method\\AbstractMethod';

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
            $cnt = count($this->results);
            CliWriter::resultLine('Deprecated payment method implementations', $cnt, 'medium');
            $report[] = [
                'ruleId' => 'extensionOfAbstractMethod',
                'name' => 'Extension of Deprecated Payment AbstractMethod',
                'shortDescription' => 'Payment method extends deprecated AbstractMethod class.',
                'longDescription' => 'Detects payment methods extending the deprecated '
                    . '\Magento\Payment\Model\Method\AbstractMethod.' . "\n"
                    . 'Impact: AbstractMethod is officially deprecated by Adobe and scheduled for '
                    . 'removal. A broken payment flow at upgrade time has immediate and severe '
                    . 'business impact.' . "\n"
                    . 'Why change: This class cannot leverage modern payment features (GraphQL, '
                    . 'vault, command pool) and may break silently on future Magento versions.' . "\n"
                    . 'How to fix: Implement \Magento\Payment\Model\MethodInterface and use the '
                    . 'command pool / gateway adapter pattern.',
                'files' => $this->results,
            ];
        }

        return $report;
    }

    public function getMessage(): string
    {
        return 'Detects payment methods that extend the deprecated AbstractMethod class '
            . 'instead of using PaymentMethodInterface.';
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
            if (Classes::extendsClass($content, self::DEPRECATED_FQCN)) {
                $this->foundCount++;

                // Find the line number of the class declaration
                $lineNumber = Classes::findClassDeclarationLine($content, 'AbstractMethod');

                $msg = 'This payment method extends the deprecated '
                    . '\\Magento\\Payment\\Model\\Method\\AbstractMethod. Consider implementing '
                    . 'PaymentMethodInterface or using a modern payment base class instead.';
                $this->results[] = Formater::formatError($file, $lineNumber, $msg, 'high');
            }
        }
    }

    public function getName(): string
    {
        return 'Payment Interface Use Audit';
    }

    public function getLongDescription(): string
    {
        return 'Identifies payment method classes extending the deprecated '
            . '\Magento\Payment\Model\Method\AbstractMethod.' . "\n"
            . 'Impact: AbstractMethod is officially deprecated by Adobe and scheduled for removal. '
            . 'Payment integrations are among the most sensitive parts of a store; a broken payment '
            . 'flow at upgrade time has immediate and severe business impact.' . "\n"
            . 'Why change: Any payment module extending AbstractMethod carries a direct upgrade '
            . 'compatibility risk. It may break silently on a future Magento version without runtime '
            . 'deprecation notice, and it cannot leverage modern payment features (GraphQL, vault, '
            . 'command pool).' . "\n"
            . 'How to fix: Implement \Magento\Payment\Model\MethodInterface and use the command pool / '
            . 'gateway adapter pattern introduced in Magento 2.1. Refer to the Magento DevDocs payment '
            . 'gateway integration guide for the current recommended architecture.';
    }
}
