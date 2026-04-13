#!/usr/bin/env php
<?php
/**
 * Build-time script to scrape Adobe Experience League and helpx.adobe.com
 * for Magento security patch data and vulnerability details.
 *
 * Generates one JSON file per release line in data/security/:
 *   data/security/2.4.8.json
 *   data/security/2.4.7.json
 *   ...
 *
 * This script is NOT included in the PHAR distribution.
 *
 * Usage:
 *   php tools/update-security-bulletins.php
 *   php tools/update-security-bulletins.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv, true);

$versionsUrl = 'https://experienceleague.adobe.com/en/docs/commerce-operations/release/versions';
$patchNotesBaseUrl = 'https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes/security-patches';

// Step 1: Fetch the versions overview page to discover release lines
fwrite(STDERR, "Fetching versions overview...\n");
$versionsHtml = fetchUrl($versionsUrl);
if ($versionsHtml === false) {
    fwrite(STDERR, "ERROR: Failed to fetch versions page\n");
    exit(1);
}

$releaseLines = extractReleaseLines($versionsHtml);

if (empty($releaseLines)) {
    fwrite(STDERR, "ERROR: No release lines found on versions page\n");
    exit(1);
}

fwrite(STDERR, "Found release lines: " . implode(', ', array_keys($releaseLines)) . "\n");

// Step 1b: Fetch lifecycle policy page for support end dates
$lifecycleUrl = 'https://experienceleague.adobe.com/en/docs/commerce-operations/release/planning/lifecycle-policy';
fwrite(STDERR, "Fetching lifecycle policy...\n");
$lifecycleHtml = fetchUrl($lifecycleUrl);
$supportDates = [];
if ($lifecycleHtml !== false) {
    $supportDates = extractSupportDates($lifecycleHtml);
    fwrite(STDERR, "Found support dates for " . count($supportDates) . " release lines\n");
} else {
    fwrite(STDERR, "WARNING: Failed to fetch lifecycle policy, support dates will be missing\n");
}

// Cache of already-fetched APSB bulletins (same APSB applies across release lines)
$apsbCache = [];

// Step 2: For each release line, fetch patch notes, then APSB vulnerability details
$outputDir = __DIR__ . '/../data/security';

foreach ($releaseLines as $releaseLine => $patchVersions) {
    $urlSlug = str_replace('.', '-', $releaseLine);
    $patchNotesUrl = "$patchNotesBaseUrl/$urlSlug-patches";

    fwrite(STDERR, "\nFetching patch notes for $releaseLine...\n");
    $patchHtml = fetchUrl($patchNotesUrl);

    if ($patchHtml === false) {
        fwrite(STDERR, "  WARNING: Failed to fetch patch notes for $releaseLine, skipping\n");
        continue;
    }

    $patchData = extractPatchData($patchHtml, $releaseLine);

    if (empty($patchData)) {
        fwrite(STDERR, "  WARNING: No patch data found for $releaseLine\n");
        continue;
    }

    // Determine latest patch
    $latestPatchNum = 0;
    $latestPatchVersion = $releaseLine;
    foreach ($patchData as $version => $data) {
        $pNum = getPatchNumber($version);
        if ($pNum > $latestPatchNum) {
            $latestPatchNum = $pNum;
            $latestPatchVersion = $version;
        }
    }

    // Step 3: For each patch with an APSB, fetch vulnerability details
    foreach ($patchData as $version => &$data) {
        $apsbId = $data['apsb'] ?? '';
        if ($apsbId === '') {
            continue;
        }

        if (isset($apsbCache[$apsbId])) {
            $data['vulnerabilities'] = $apsbCache[$apsbId];
            continue;
        }

        $apsbUrl = $data['url'] ?? '';
        if ($apsbUrl === '') {
            continue;
        }

        fwrite(STDERR, "  Fetching vulnerabilities for $apsbId...\n");
        $apsbHtml = fetchUrl($apsbUrl);
        if ($apsbHtml === false) {
            fwrite(STDERR, "    WARNING: Failed to fetch $apsbId\n");
            continue;
        }

        $vulnerabilities = extractVulnerabilities($apsbHtml);
        if (!empty($vulnerabilities)) {
            $apsbCache[$apsbId] = $vulnerabilities;
            $data['vulnerabilities'] = $vulnerabilities;
            fwrite(STDERR, "    Found " . count($vulnerabilities) . " vulnerability categories\n");
        } else {
            fwrite(STDERR, "    WARNING: No vulnerabilities parsed from $apsbId\n");
        }
    }
    unset($data);

    // Build per-release-line file
    $output = [
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'release_line' => $releaseLine,
        'latest_patch' => $latestPatchVersion,
        'patches' => $patchData,
    ];

    if (isset($supportDates[$releaseLine])) {
        $output['support'] = $supportDates[$releaseLine];
    }

    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($dryRun) {
        echo "=== $releaseLine ===\n";
        echo $json . "\n\n";
    } else {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
        $filePath = "$outputDir/$releaseLine.json";
        file_put_contents($filePath, $json . "\n");
        fwrite(STDERR, "  Written $filePath (" . count($patchData) . " patches, latest: $latestPatchVersion)\n");
    }
}

if ($dryRun) {
    fwrite(STDERR, "\n--dry-run: Output not written to files\n");
}

fwrite(STDERR, "\nDone. APSB bulletins fetched: " . count($apsbCache) . "\n");

// --- Functions ---

function fetchUrl(string $url): string|false
{
    $escapedUrl = escapeshellarg($url);
    $content = shell_exec("curl -sL --max-time 30 $escapedUrl 2>/dev/null");
    return is_string($content) && $content !== '' ? $content : false;
}

/**
 * Extract release lines and their patch versions from the versions overview page.
 */
function extractReleaseLines(string $html): array
{
    $releaseLines = [];

    preg_match_all('/<h2[^>]*id="(\d+)"[^>]*>.*?<\/h2>/s', $html, $headingMatches, PREG_OFFSET_CAPTURE);

    foreach ($headingMatches[1] as $i => $match) {
        $headingId = $match[0];
        $offset = $match[1];

        $version = implode('.', str_split($headingId));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            continue;
        }

        if (str_contains($version, 'beta')) {
            continue;
        }

        $nextOffset = isset($headingMatches[0][$i + 1])
            ? $headingMatches[0][$i + 1][1]
            : strlen($html);
        $section = substr($html, $offset, $nextOffset - $offset);

        preg_match_all(
            '/>' . preg_quote($version, '/') . '(-p\d+)?</',
            $section,
            $versionMatches
        );

        $patches = [];
        foreach ($versionMatches[0] as $vm) {
            $v = trim($vm, '><');
            if (!str_contains($v, 'beta')) {
                $patches[] = $v;
            }
        }

        if (!empty($patches)) {
            $releaseLines[$version] = $patches;
        }
    }

    return $releaseLines;
}

/**
 * Extract patch data from a patch notes page.
 */
function extractPatchData(string $html, string $releaseLine): array
{
    $patches = [];

    $escapedLine = preg_quote($releaseLine, '/');
    preg_match_all(
        '/<h2[^>]*id="[^"]*"[^>]*>\s*(' . $escapedLine . '(?:-p\d+)?)\s*<\/h2>/i',
        $html,
        $h2Matches,
        PREG_OFFSET_CAPTURE
    );

    if (empty($h2Matches[1])) {
        return [];
    }

    foreach ($h2Matches[1] as $i => $match) {
        $patchVersion = trim($match[0]);
        $offset = $match[1];

        if (str_contains($patchVersion, 'beta')) {
            continue;
        }

        $nextOffset = isset($h2Matches[0][$i + 1])
            ? $h2Matches[0][$i + 1][1]
            : strlen($html);
        $section = substr($html, $offset, $nextOffset - $offset);

        $apsb = '';
        $apsbUrl = '';
        if (preg_match('/https:\/\/helpx\.adobe\.com\/security\/products\/magento\/(apsb[\w-]+)\.html/', $section, $apsbMatch)) {
            $apsb = strtoupper($apsbMatch[1]);
            $apsbUrl = $apsbMatch[0];
        }

        $date = '';
        if (preg_match(
            '/(?:Released|Release date)[:\s]*(\w+ \d{1,2},?\s*\d{4})/i',
            $section,
            $dateMatch
        )) {
            $date = formatDate($dateMatch[1]);
        }

        $highlights = extractHighlights($section);

        if ($apsb === '' && empty($highlights)) {
            continue;
        }

        $patchEntry = [];
        if ($apsb !== '') {
            $patchEntry['apsb'] = $apsb;
        }
        if ($date !== '') {
            $patchEntry['date'] = $date;
        }
        if ($apsbUrl !== '') {
            $patchEntry['url'] = $apsbUrl;
        }
        if (!empty($highlights)) {
            $patchEntry['highlights'] = $highlights;
        }

        $patches[$patchVersion] = $patchEntry;
    }

    return $patches;
}

/**
 * Extract highlight items from a patch section.
 */
function extractHighlights(string $sectionHtml): array
{
    $highlights = [];

    if (!preg_match('/<h3[^>]*>.*?Highlights.*?<\/h3>(.*?)(?:<h[23]|$)/si', $sectionHtml, $highlightSection)) {
        if (!preg_match('/<ul>(.*?)<\/ul>/si', $sectionHtml, $highlightSection)) {
            return [];
        }
    }

    $content = $highlightSection[1] ?? $highlightSection[0];

    preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $content, $liMatches);

    foreach ($liMatches[1] as $li) {
        $text = strip_tags($li);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ($text !== '') {
            $highlights[] = $text;
        }
    }

    return $highlights;
}

/**
 * Extract vulnerability details from an APSB bulletin page.
 *
 * Parses the "Vulnerability Details" table and consolidates by category:
 *   [
 *     {
 *       "category": "Cross-site Scripting (Stored XSS)",
 *       "impacts": ["Privilege escalation", "Security feature bypass"],
 *       "severities": ["Critical", "Important"]
 *     },
 *     ...
 *   ]
 */
function extractVulnerabilities(string $html): array
{
    // Find the Vulnerability Details section
    $pos = strpos($html, 'id="Vulnerabilitydetails"');
    if ($pos === false) {
        return [];
    }

    // Find the next <table> after this heading
    $tableStart = strpos($html, '<table', $pos);
    if ($tableStart === false) {
        return [];
    }

    $tableEnd = strpos($html, '</table>', $tableStart);
    if ($tableEnd === false) {
        return [];
    }

    $tableHtml = substr($html, $tableStart, $tableEnd - $tableStart + 8);

    // Extract all rows
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $tableHtml, $rowMatches);

    if (empty($rowMatches[1])) {
        return [];
    }

    // Consolidate by category: category -> {impacts: set, severities: set}
    $categories = [];

    foreach ($rowMatches[1] as $row) {
        // Skip header row (contains <th>)
        if (str_contains($row, '<th')) {
            continue;
        }

        // Extract <td> cells
        preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cellMatches);
        if (count($cellMatches[1]) < 3) {
            continue;
        }

        $category = cleanCellText($cellMatches[1][0]);
        $impact = cleanCellText($cellMatches[1][1]);
        $severity = cleanCellText($cellMatches[1][2]);

        if ($category === '' || $impact === '' || $severity === '') {
            continue;
        }

        // Normalize category name (lowercase for grouping key)
        $key = strtolower($category);

        if (!isset($categories[$key])) {
            $categories[$key] = [
                'category' => $category,
                'impacts' => [],
                'severities' => [],
            ];
        }

        // Add unique impacts and severities
        if (!in_array($impact, $categories[$key]['impacts'])) {
            $categories[$key]['impacts'][] = $impact;
        }
        if (!in_array($severity, $categories[$key]['severities'])) {
            $categories[$key]['severities'][] = $severity;
        }
    }

    return array_values($categories);
}

/**
 * Clean HTML from a table cell and return plain text.
 */
function cleanCellText(string $html): string
{
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', trim($text));
    // Remove common artifacts
    $text = trim($text, " \t\n\r\0\x0B\xC2\xA0");
    return $text;
}

/**
 * Extract support end dates from the lifecycle policy page.
 * Returns ['2.4.8' => ['regular_end' => '2028-04-11', 'extended_end' => null], ...]
 */
function extractSupportDates(string $html): array
{
    $dates = [];

    // The lifecycle table is a div-based table with 6 columns per row
    // Find the table containing "End of regular support"
    if (!preg_match('/End of regular support.*?(<div class="table[^"]*".*?<\/div>\s*<\/div>)/si', $html, $tableMatch)) {
        // Fallback: look for the div.table that contains release version rows
        if (!preg_match('/<div class="table 0-row-6[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>/si', $html, $tableMatch)) {
            return [];
        }
    }

    $tableHtml = $tableMatch[0];

    // Extract rows: "Adobe Commerce 2.4.X" followed by GA date, regular end, extended end
    // Each cell is a <div>. We match 4 consecutive cells after the release name.
    preg_match_all(
        '/Adobe Commerce (\d+\.\d+\.\d+)<\/div>\s*<div[^>]*>(.*?)<\/div>\s*<div[^>]*>(.*?)<\/div>\s*<div[^>]*>(.*?)<\/div>/si',
        $tableHtml,
        $rowMatches,
        PREG_SET_ORDER
    );

    foreach ($rowMatches as $match) {
        $releaseLine = $match[1];
        $gaDate = cleanCellText($match[2]); // GA date (not needed)
        $regularEnd = cleanCellText($match[3]);
        $extendedEnd = cleanCellText($match[4]);

        // Clean footnote markers (e.g., "20262" -> "2026")
        $regularEnd = preg_replace('/(\d{4})\d*$/', '$1', $regularEnd);
        $extendedEnd = preg_replace('/(\d{4})\d*$/', '$1', $extendedEnd);

        $support = [
            'regular_end' => formatDate($regularEnd),
            'extended_end' => null,
        ];

        if ($extendedEnd !== '' && strtolower($extendedEnd) !== 'n/a') {
            $support['extended_end'] = formatDate($extendedEnd);
        }

        // Only add if we got at least the regular end date
        if ($support['regular_end'] !== '') {
            $dates[$releaseLine] = $support;
        }
    }

    return $dates;
}

/**
 * Convert a date string like "March 10, 2026" to "2026-03-10".
 */
function formatDate(string $dateStr): string
{
    $timestamp = strtotime(trim($dateStr));
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
}

/**
 * Get the patch number from a version string.
 */
function getPatchNumber(string $version): int
{
    if (preg_match('/-p(\d+)$/', $version, $matches)) {
        return (int) $matches[1];
    }
    return 0;
}