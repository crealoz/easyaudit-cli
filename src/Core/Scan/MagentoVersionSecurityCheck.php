<?php

namespace EasyAudit\Core\Scan;

use EasyAudit\Core\Scan\Util\Formater;
use EasyAudit\Service\CliWriter;

class MagentoVersionSecurityCheck
{
    private const ADOBE_SECURITY_URL = 'https://helpx.adobe.com/security/products/magento.html';

    private const MAGENTO_PACKAGES = [
        'magento/product-enterprise-edition',
        'magento/product-community-edition',
        'magento/magento2-base',
    ];

    /**
     * Check the scanned Magento project for known security vulnerabilities.
     *
     * @return array List of findings (0 or 1 entries)
     */
    public function check(string $scanPath): array
    {
        $composerLock = rtrim($scanPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!file_exists($composerLock)) {
            CliWriter::skipped('No composer.lock found, skipping version security check');
            return [];
        }

        $version = $this->detectMagentoVersion($composerLock);
        if ($version === null) {
            CliWriter::skipped('No Magento package found in composer.lock');
            return [];
        }

        // Skip beta versions
        if (str_contains($version, 'beta')) {
            CliWriter::skipped("Beta version $version detected, skipping security check");
            return [];
        }

        $releaseLine = $this->getReleaseLine($version);
        $data = $this->loadReleaseLineData($releaseLine);

        if ($data === null) {
            CliWriter::resultLine("Magento $version: no security data available for $releaseLine", 1, 'medium');
            return [$this->buildUnknownVersionFinding($version, $composerLock)];
        }

        $latestPatch = $data['latest_patch'] ?? '';
        $patches = $data['patches'] ?? [];

        // Already on latest patch for this release line
        if ($latestPatch !== '' && version_compare($version, $latestPatch, '>=')) {
            $support = $data['support'] ?? null;
            $supportStatus = $this->getSupportStatus($support);
            $newestRelease = $this->getNewestReleaseLine();

            // Truly up to date: latest patch on newest release line
            if ($newestRelease === null || version_compare($releaseLine, $newestRelease['release_line'], '>=')) {
                CliWriter::success("  Magento $version is up to date (latest: $latestPatch)");
                return [];
            }

            $newestVersion = $newestRelease['latest_patch'];
            $newestLine = $newestRelease['release_line'];

            // Determine severity and message based on support status
            if ($supportStatus === 'eol') {
                $severity = 'high';
                $shortDesc = "Magento $releaseLine is end-of-life and no longer receives security patches";
                $longDesc = "Magento $version has all available patches for the $releaseLine release line, "
                    . "but this release line has reached end-of-life and will no longer receive security updates. "
                    . "Upgrade to $newestLine (latest: $newestVersion) to continue receiving security patches.";
                $message = "Magento $releaseLine is end-of-life. Upgrade to $newestLine (latest: $newestVersion).";
                CliWriter::resultLine("Magento $releaseLine is end-of-life!", 1, 'high');
            } elseif ($supportStatus === 'extended') {
                $extendedEnd = $support['extended_end'] ?? '';
                $severity = 'high';
                $shortDesc = "Magento $releaseLine is in extended support only (ends $extendedEnd)";
                $longDesc = "Magento $version has all available patches for the $releaseLine release line, "
                    . "but this release line is in extended support only (ends $extendedEnd). "
                    . "Extended support provides limited security fixes. "
                    . "Upgrade to $newestLine (latest: $newestVersion) for full security support.";
                $message = "Magento $releaseLine is in extended support only (ends $extendedEnd). "
                    . "Upgrade to $newestLine (latest: $newestVersion).";
                CliWriter::resultLine("Magento $releaseLine is in extended support (ends $extendedEnd)", 1, 'high');
            } else {
                $severity = 'medium';
                $shortDesc = "Magento $version is on the latest patch for $releaseLine, "
                    . "but newer release line $newestLine is available";
                $longDesc = "Magento $version has all security patches for the $releaseLine release line. "
                    . "However, a newer release line $newestLine is available (latest: $newestVersion). "
                    . "Consider upgrading to $newestLine to benefit from the latest security fixes and features.";
                $message = "Magento $releaseLine is fully patched but $newestLine is available "
                    . "(latest: $newestVersion). Consider upgrading.";
                CliWriter::resultLine(
                    "Magento $version is patched, but $newestLine is available (latest: $newestVersion)",
                    1,
                    'medium'
                );
            }

            return [[
                'ruleId' => 'magento-version-security',
                'name' => 'Magento Version Upgrade Available',
                'shortDescription' => $shortDesc,
                'longDescription' => $longDesc,
                'files' => [
                    Formater::formatError($composerLock, 1, $message, $severity),
                ],
            ]];
        }

        // Collect missing patches
        $missingPatches = $this->collectMissingPatches($version, $releaseLine, $patches);

        if (empty($missingPatches)) {
            CliWriter::resultLine("Magento $version: may be outdated (latest: $latestPatch)", 1, 'medium');
            return [$this->buildUnknownVersionFinding($version, $composerLock)];
        }

        // Build finding with one error entry per missing patch
        $results = [];
        foreach ($missingPatches as $patchVersion => $patchData) {
            $message = $this->buildPatchMessage($patchVersion, $patchData);
            $results[] = Formater::formatError($composerLock, 1, $message, 'high');
        }

        $count = count($missingPatches);
        CliWriter::resultLine("Magento $version is missing $count security patch(es)", $count, 'high');

        $longDescription = "Magento $version is missing $count security patch(es). "
            . "The latest available patch for the $releaseLine line is $latestPatch. "
            . "Each missing patch addresses security vulnerabilities documented in Adobe Security Bulletins (APSB). "
            . "Update to $latestPatch or later to resolve these issues.";

        // Suggest upgrading to a newer release line if available
        $newestRelease = $this->getNewestReleaseLine();
        if ($newestRelease !== null && version_compare($releaseLine, $newestRelease['release_line'], '<')) {
            $longDescription .= " Additionally, consider upgrading to the {$newestRelease['release_line']} release line "
                . "(latest: {$newestRelease['latest_patch']}) for long-term security support.";
        }

        return [[
            'ruleId' => 'magento-version-security',
            'name' => 'Magento Security Vulnerabilities',
            'shortDescription' => "Known security vulnerabilities for Magento $version",
            'longDescription' => $longDescription,
            'files' => $results,
        ]];
    }

    /**
     * Load the JSON data file for a specific release line.
     */
    private function loadReleaseLineData(string $releaseLine): ?array
    {
        // Works both in development (src/Core/Scan/) and inside PHAR
        $jsonPath = __DIR__ . '/../../../data/security/' . $releaseLine . '.json';
        if (!file_exists($jsonPath)) {
            return null;
        }

        $content = file_get_contents($jsonPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function detectMagentoVersion(string $composerLock): ?string
    {
        $content = file_get_contents($composerLock);
        if ($content === false) {
            return null;
        }

        $lock = json_decode($content, true);
        if (!is_array($lock) || !isset($lock['packages'])) {
            return null;
        }

        foreach (self::MAGENTO_PACKAGES as $packageName) {
            foreach ($lock['packages'] as $package) {
                if (($package['name'] ?? '') === $packageName && isset($package['version'])) {
                    return ltrim($package['version'], 'v');
                }
            }
        }

        return null;
    }

    /**
     * Extract the release line from a version string.
     * e.g., "2.4.7-p3" -> "2.4.7", "2.4.7" -> "2.4.7"
     */
    private function getReleaseLine(string $version): string
    {
        $pos = strpos($version, '-');
        return $pos !== false ? substr($version, 0, $pos) : $version;
    }

    /**
     * Get the patch number from a version string.
     * e.g., "2.4.7-p3" -> 3, "2.4.7" -> 0
     */
    private function getPatchNumber(string $version): int
    {
        if (preg_match('/-p(\d+)$/', $version, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Collect patches newer than the installed version for the same release line.
     */
    private function collectMissingPatches(string $installedVersion, string $releaseLine, array $patches): array
    {
        $installedPatch = $this->getPatchNumber($installedVersion);
        $missing = [];

        foreach ($patches as $patchVersion => $patchData) {
            if ($this->getReleaseLine($patchVersion) !== $releaseLine) {
                continue;
            }
            if ($this->getPatchNumber($patchVersion) > $installedPatch) {
                $missing[$patchVersion] = $patchData;
            }
        }

        // Sort by patch number ascending
        uksort($missing, fn($a, $b) => $this->getPatchNumber($a) - $this->getPatchNumber($b));

        return $missing;
    }

    /**
     * Build a human-readable message for a missing patch, including vulnerability details.
     */
    private function buildPatchMessage(string $patchVersion, array $patchData): string
    {
        $apsb = $patchData['apsb'] ?? 'unknown';
        $date = $patchData['date'] ?? '';
        $url = $patchData['url'] ?? '';
        $vulnerabilities = $patchData['vulnerabilities'] ?? [];

        $parts = ["Missing patch $patchVersion ($apsb"];
        if ($date !== '') {
            $parts[0] .= ", $date";
        }
        $parts[0] .= ')';

        if (!empty($vulnerabilities)) {
            $parts[0] .= ': ' . $this->summarizeVulnerabilities($vulnerabilities);
        }

        if ($url !== '') {
            $parts[] = $url;
        }

        return implode(' - ', $parts);
    }

    /**
     * Summarize vulnerabilities into a compact string.
     * e.g., "Cross-site Scripting (Stored XSS): Privilege escalation, Security feature bypass (Critical, Important)"
     */
    private function summarizeVulnerabilities(array $vulnerabilities): string
    {
        $summaries = [];
        foreach ($vulnerabilities as $vuln) {
            $category = $vuln['category'] ?? '';
            $impacts = $vuln['impacts'] ?? [];
            $severities = $vuln['severities'] ?? [];

            if ($category === '') {
                continue;
            }

            $summary = $category;
            if (!empty($impacts)) {
                $summary .= ': ' . implode(', ', $impacts);
            }
            if (!empty($severities)) {
                $summary .= ' (' . implode(', ', $severities) . ')';
            }
            $summaries[] = $summary;
        }

        return implode('. ', $summaries);
    }

    /**
     * Determine the support status of a release line based on its support dates.
     * Returns 'eol', 'extended', or 'active'.
     */
    private function getSupportStatus(?array $support): string
    {
        if ($support === null) {
            return 'active'; // No support data available, assume active
        }

        $today = date('Y-m-d');
        $regularEnd = $support['regular_end'] ?? '';
        $extendedEnd = $support['extended_end'] ?? null;

        // Regular support still active
        if ($regularEnd === '' || $today <= $regularEnd) {
            return 'active';
        }

        // Regular support ended, check extended
        if ($extendedEnd !== null && $extendedEnd !== '' && $today <= $extendedEnd) {
            return 'extended';
        }

        // All support ended
        return 'eol';
    }

    /**
     * Scan all data/security/*.json files and return the newest release line info.
     * Returns ['release_line' => '2.4.8', 'latest_patch' => '2.4.8-p4'] or null.
     */
    private function getNewestReleaseLine(): ?array
    {
        $dataDir = __DIR__ . '/../../../data/security';
        if (!is_dir($dataDir)) {
            return null;
        }

        $newest = null;
        foreach (scandir($dataDir) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }

            $content = file_get_contents($dataDir . '/' . $file);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (!is_array($data) || !isset($data['release_line'], $data['latest_patch'])) {
                continue;
            }

            if ($newest === null || version_compare($data['release_line'], $newest['release_line'], '>')) {
                $newest = [
                    'release_line' => $data['release_line'],
                    'latest_patch' => $data['latest_patch'],
                ];
            }
        }

        return $newest;
    }

    private function buildUnknownVersionFinding(string $version, string $composerLock): array
    {
        $url = self::ADOBE_SECURITY_URL;
        return [
            'ruleId' => 'magento-version-security',
            'name' => 'Magento Security Vulnerabilities',
            'shortDescription' => "Magento $version could not be matched against the known security database",
            'longDescription' => "Magento version $version could not be matched against the known security bulletin database. "
                . "Your installation may be outdated and exposed to known vulnerabilities. "
                . "Check $url for the latest security information.",
            'files' => [
                Formater::formatError(
                    $composerLock,
                    1,
                    "Magento $version: could not verify security status. See $url",
                    'medium'
                ),
            ],
        ];
    }
}