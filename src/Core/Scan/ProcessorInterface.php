<?php

namespace EasyAudit\Core\Scan;

interface ProcessorInterface
{
    /**
     * Get a unique identifier for the processor. It should be a lowercase string with words separated by hyphens.
     *
     * @return string
     */
    public function getIdentifier(): string;

    /**
     * Process the given files and populate internal state with findings.
     *
     * @param array $files List of file paths to process.
     */
    public function process(array $files): void;

    /**
     * Get the count of findings detected by the processor.
     */
    public function getFoundCount(): int;

    public function getName(): string;

    public function getLongDescription(): string;

    public function getFileType(): string;

    /**
     * Get a descriptive message about the processor's purpose.
     */
    public function getMessage(): string;

    /**
     * Return report data formatted for SARIF.
     * Array must contain:
     * - ruleId: string identifier of the rule
     * - message: description of the finding
     * - locations: list of location entries, each with:
     *   * physicalLocation => [
     *       artifactLocation => [uri => string],
     *       region => [startLine => int]
     *     ]
     *
     * @return array
     */
    public function getReport(): array;
}
