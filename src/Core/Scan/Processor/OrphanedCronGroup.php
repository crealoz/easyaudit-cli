<?php

namespace EasyAudit\Core\Scan\Processor;

use EasyAudit\Core\Scan\Util\Content;
use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Core\Scan\Util\Xml;

class OrphanedCronGroup extends AbstractProcessor
{
    /** Magento ships these groups out of the box. */
    private const DEFAULT_GROUPS = ['default', 'index'];

    public function getIdentifier(): string
    {
        return 'orphanedCronGroup';
    }

    public function getFileType(): string
    {
        return 'xml';
    }

    public function getName(): string
    {
        return 'Orphaned Cron Group';
    }

    public function getMessage(): string
    {
        return 'Detects crontab.xml jobs placed in a group that no cron_groups.xml defines.';
    }

    public function getLongDescription(): string
    {
        return 'Flags <group id="..."> entries in crontab.xml whose id is not declared in any '
            . 'cron_groups.xml file across the scan.' . "\n"
            . 'Impact: Jobs in an undeclared group inherit defaults for schedule generation, history '
            . 'cleanup, and concurrency — but these defaults are usually not what the developer '
            . 'intended. Schedules may not be generated at all, jobs may pile up, or custom tuning '
            . '(e.g. longer history window, separate PID) silently does not apply.' . "\n"
            . 'Why change: cron_groups.xml is the only way to tune Magento cron behavior per group. '
            . 'Forgetting it means every operational knob you thought you set on this group is '
            . 'actually off.' . "\n"
            . 'How to fix: Add an etc/cron_groups.xml declaring the group, or move the jobs to an '
            . 'existing group (default, index, or a custom one that is already declared).';
    }

    public function process(array $files): void
    {
        if (empty($files['xml'])) {
            return;
        }

        $definedGroups = $this->collectDefinedGroups($files['xml']);
        $this->checkCrontabs($files['xml'], $definedGroups);

        if (!empty($this->results)) {
            echo "  \033[33m!\033[0m Orphaned cron groups: \033[1;33m"
                . count($this->results) . "\033[0m\n";
        }
    }

    /**
     * @param  array<int, string> $xmlFiles
     * @return array<string, bool>
     */
    private function collectDefinedGroups(array $xmlFiles): array
    {
        $groups = array_fill_keys(self::DEFAULT_GROUPS, true);
        foreach ($xmlFiles as $file) {
            if (basename($file) !== 'cron_groups.xml') {
                continue;
            }
            $xml = Xml::loadFile($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->xpath('//group[@id]') as $group) {
                $id = (string)$group['id'];
                if ($id !== '') {
                    $groups[$id] = true;
                }
            }
        }
        return $groups;
    }

    /**
     * @param array<int, string>  $xmlFiles
     * @param array<string, bool> $definedGroups
     */
    private function checkCrontabs(array $xmlFiles, array $definedGroups): void
    {
        foreach ($xmlFiles as $file) {
            if (basename($file) !== 'crontab.xml') {
                continue;
            }
            $xml = Xml::loadFile($file);
            if ($xml === false) {
                continue;
            }

            $fileContent = '';
            foreach ($xml->xpath('//group[@id]') as $group) {
                $id = (string)$group['id'];
                if ($id === '' || isset($definedGroups[$id])) {
                    continue;
                }

                if ($fileContent === '') {
                    $fileContent = file_get_contents($file);
                }
                $line = Content::getLineNumber($fileContent, 'id="' . $id . '"');
                if ($line === 0) {
                    $line = 1;
                }

                $this->foundCount++;
                $this->results[] = Formater::formatError(
                    $file,
                    $line,
                    sprintf(
                        'Cron group "%s" is not declared in any cron_groups.xml. Either declare it '
                        . 'or move its jobs to an existing group.',
                        $id
                    ),
                    'warning',
                    0,
                    ['groupId' => $id]
                );
            }
        }
    }
}
