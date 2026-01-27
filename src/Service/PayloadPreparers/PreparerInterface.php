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

    /**
     * Prepare files and sends the prepared array
     *
     * @param array $errors
     * @param array $fixables
     * @param string|null $selectedRule Optional rule filter (only process this rule)
     * @return mixed
     */
    public function prepareFiles(array $errors, array $fixables, ?string $selectedRule = null): array;

    /**
     * Prepare payload that can be sent to easy audit fixer for a specific file.
     *
     * @param string $filePath
     * @param array $data
     * @return array
     */
    public function preparePayload(string $filePath, array $data): array;
}