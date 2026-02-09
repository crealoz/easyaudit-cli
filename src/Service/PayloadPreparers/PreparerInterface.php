<?php

namespace EasyAudit\Service\PayloadPreparers;

interface PreparerInterface
{
    /**
     * Rules that require di.xml modification (proxy configuration)
     */
    public const SPECIFIC_RULES = [
        'noProxyUsedInCommands' => DiPreparer::class,
        'noProxyUsedForHeavyClasses' => DiPreparer::class,
    ];

    public const MAPPED_RULES = [
        'noProxyUsedInCommands' => 'proxyConfiguration',
        'noProxyUsedForHeavyClasses' => 'proxyConfiguration',
        'magento.performance.count-on-collection' => 'countOnCollection',
    ];

    /**
     * Prepare files and sends the prepared array
     *
     * @param array $findings
     * @param array $fixables
     * @param string|null $selectedRule Optional rule filter (only process this rule)
     * @return mixed
     */
    public function prepareFiles(array $findings, array $fixables, ?string $selectedRule = null): array;

    /**
     * Prepare payload that can be sent to easy audit fixer for a specific file.
     *
     * @param  string $filePath
     * @param  array  $data
     * @return array
     */
    public function preparePayload(string $filePath, array $data): array;
}
