<?php

namespace EasyAudit\Tests\Fixtures\Scanner\ExtraProcessors;

use EasyAudit\Core\Scan\Processor\AbstractProcessor;

/**
 * Sponsor-style processor used by ScannerTest to verify that `processorDirs` config entries
 * contribute processors at scan time. Not auto-loaded by Composer — `class_exists()` triggers
 * a manual `require_once` in the test setUp.
 */
class StubProcessor extends AbstractProcessor
{
    public function getIdentifier(): string
    {
        return 'stubProcessor';
    }

    public function getName(): string
    {
        return 'Stub Processor';
    }

    public function getMessage(): string
    {
        return 'Test-only processor for sponsor processor-directory wiring.';
    }

    public function getLongDescription(): string
    {
        return 'No-op processor used in ScannerTest to verify config-driven processor directory discovery.';
    }

    public function getFileType(): string
    {
        return 'php';
    }

    public function process(array $files): void
    {
        // Intentionally no-op.
    }
}
