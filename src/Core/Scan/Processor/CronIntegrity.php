<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class CronIntegrity extends AbstractProcessor
{
    /** @var array<string, string> FQCN => file path */
    private array $classIndex = [];

    public function getIdentifier(): string
    {
        return 'cronIntegrity';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'Cron Handler Integrity';
    }

    public function getMessage(): string
    {
        return 'Detects crontab.xml jobs whose instance class or method does not exist.';
    }

    public function getLongDescription(): string
    {
        return 'Flags <job> entries in crontab.xml where the instance class cannot be resolved to a '
            . 'file in the scan, or where the target method is not defined on that class.' . "\n"
            . 'Impact: A cron job with a broken reference fails silently at dispatch time. Magento '
            . 'logs the error, marks the run as failed, and moves on — there is no startup validation. '
            . 'Routine maintenance like inventory reindexing, cart cleanup, or newsletter dispatch can '
            . 'stop working for weeks before anyone notices.' . "\n"
            . 'Why change: Broken cron is invisible in development and rarely caught in staging. The '
            . 'first signal is usually a business-process failure: customers not receiving order '
            . 'confirmations, stock counts drifting, backups missing.' . "\n"
            . 'How to fix: Either correct the instance/method to point at an existing handler, or '
            . 'remove the job from crontab.xml. If the handler was recently renamed or moved, update '
            . 'every crontab.xml that referenced it.';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        $this->classIndex = $this->buildClassIndex($files['php'] ?? []);

        foreach ($files['xml'] as $file) {
            if (basename($file) !== 'crontab.xml') {
                continue;
            }
            $this->processCrontab($file);
        }

        if (!empty($this->results)) {
            echo "  \033[31m✗\033[0m Broken cron handlers: \033[1;31m"
                . count($this->results) . "\033[0m\n";
        }
    }

    /**
     * @param  array<int, string> $phpFiles
     * @return array<string, string>
     */
    private function buildClassIndex(array $phpFiles): array
    {
        $index = [];
        foreach ($phpFiles as $file) {
            $content = @file_get_contents($file, false, null, 0, 4096);
            if ($content === false) {
                continue;
            }
            if (!preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
                continue;
            }
            if (!preg_match('/\b(?:class|interface|trait|enum)\s+(\w+)/', $content, $classMatch)) {
                continue;
            }
            $fqcn = trim($nsMatch[1]) . '\\' . $classMatch[1];
            $index[$fqcn] = $file;
        }
        return $index;
    }

    private function processCrontab(string $file): void
    {
        $xml = Xml::loadFile($file);
        if ($xml === false) {
            return;
        }

        $fileContent = '';
        foreach ($xml->xpath('//job') as $job) {
            $instance = ltrim((string)$job['instance'], '\\');
            $method = (string)$job['method'];
            $name = (string)$job['name'];

            if ($instance === '' || $method === '') {
                continue;
            }

            $handlerFile = $this->classIndex[$instance] ?? null;
            if ($handlerFile === null) {
                // Can't verify — class is outside the scan or is a virtual type.
                continue;
            }

            $handlerContent = @file_get_contents($handlerFile);
            if ($handlerContent === false) {
                continue;
            }

            if ($this->methodExists($handlerContent, $method)) {
                continue;
            }

            if ($fileContent === '') {
                $fileContent = file_get_contents($file);
            }

            $line = Content::getLineNumber($fileContent, 'name="' . $name . '"');
            if ($line === 0) {
                $line = Content::getLineNumber($fileContent, 'method="' . $method . '"');
            }
            if ($line === 0) {
                $line = 1;
            }

            $this->foundCount++;
            $this->results[] = Formater::formatError(
                $file,
                $line,
                sprintf(
                    'Cron job "%s" references %s::%s(), but that method is not defined on the class. '
                    . 'The job will fail at dispatch time.',
                    $name !== '' ? $name : '(unnamed)',
                    $instance,
                    $method
                ),
                'error',
                0,
                [
                    'jobName' => $name,
                    'instance' => $instance,
                    'method' => $method,
                ]
            );
        }
    }

    private function methodExists(string $classContent, string $method): bool
    {
        $pattern = '/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/';
        return preg_match($pattern, $classContent) === 1;
    }
}
