<?php

namespace EasyAudit\Service;

/**
 * Contract for a fixer backend. Implementations may call a remote service (Api) or run transformations locally.
 *
 * Returned by `getRemainingCredits()`:
 * - `null` means this backend does not track credits (offline / local fixers); FixApply will skip credit UI.
 * - an array means credits are tracked: `{credits: int, credit_expiration_date?: string,
 *   licence_expiration_date?: string, project_id?: string}`.
 */
interface FixerInterface
{
    /**
     * Request a fix for a single file.
     *
     * @param string $filePath  Path to the file being fixed
     * @param string $content   File content
     * @param array  $rules     Map of {ruleId: metadata}
     * @param string $projectId Project identifier for grouping requests
     * @param string $format    Output format ('git', 'patch', 'diffonly')
     * @return array{diff: string, status?: string, credits_remaining?: int|null}
     */
    public function requestFilefix(
        string $filePath,
        string $content,
        array $rules,
        string $projectId,
        string $format = 'git'
    ): array;

    /**
     * Return the rule types this fixer can handle, keyed by rule name.
     * Values are credit costs for credit-aware fixers, or `true` for local fixers.
     *
     * @return array<string, int|true>
     */
    public function getAllowedType(): array;

    /**
     * Return remaining credits info or `null` if this fixer doesn't track credits.
     *
     * @param string $projectId Project identifier for validation
     * @return array{credits: int, credit_expiration_date?: ?string, licence_expiration_date?: ?string, project_id?: string}|null
     */
    public function getRemainingCredits(string $projectId): ?array;
}
