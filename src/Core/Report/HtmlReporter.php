<?php

namespace EasyAudit\Core\Report;

class HtmlReporter implements ReporterInterface
{
    public function generate(array $findings): string
    {
        $scanPath = $findings['metadata']['scan_path'] ?? 'Unknown';
        $scanDate = date('Y-m-d H:i:s');

        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;
        $rules = $this->getRules($findings);

        $rulesHtml = '';
        foreach ($rules as $rule) {
            $highCount += $rule['highCount'];
            $mediumCount += $rule['mediumCount'];
            $lowCount += $rule['lowCount'];
            $severity = 'low';
            if ($rule['highCount'] > 0) {
                $severity = 'high';
            } elseif ($rule['mediumCount'] > 0) {
                $severity = 'medium';
            }

            $badge = $this->severityBadge($severity);
            $count = count($rule['files']);
            $rowsHtml = '';

            foreach ($rule['files'] as $file) {
                $rawPath = $file['file'] ?? '';
                if ($scanPath !== 'Unknown' && str_starts_with($rawPath, $scanPath)) {
                    $rawPath = ltrim(substr($rawPath, strlen($scanPath)), '/');
                }
                $filePath = htmlspecialchars($rawPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $line = (int)($file['startLine'] ?? $file['line'] ?? 1);
                $message = htmlspecialchars($file['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $fileSev = $file['severity'] ?? 'medium';
                $sevBadge = $this->severityBadge($fileSev);

                $rowsHtml .= <<<ROW
                <tr>
                    <td class="cell-file" title="{$filePath}">{$filePath}</td>
                    <td class="cell-line">{$line}</td>
                    <td class="cell-severity">{$sevBadge}</td>
                    <td class="cell-message">{$message}</td>
                </tr>
ROW;
            }

            $ruleName = htmlspecialchars($rule['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $ruleLongDesc = $this->formatDescription($rule['longDescription']);

            $rulesHtml .= <<<RULE
            <details class="rule-card" data-severity="{$severity}" closed>
                <summary class="rule-header">
                    <span class="rule-title">{$ruleName}</span>
                    {$badge}
                    <span class="rule-count">{$count} issue(s)</span>
                </summary>
                <div class="rule-body">
                    <div class="rule-description">{$ruleLongDesc}</div>
                    <table class="findings-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Line</th>
                                <th>Severity</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rowsHtml}
                        </tbody>
                    </table>
                </div>
            </details>
RULE;
        }

        $total = $highCount + $mediumCount + $lowCount;

        $scanPathHtml = htmlspecialchars($scanPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scanDateHtml = htmlspecialchars($scanDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($total === 0) {
            $rulesHtml = '<div class="no-issues">No issues found.</div>';
        }

        $css = file_get_contents(__DIR__ . '/../../assets/report.css');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src https://crealoz.fr; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none';">
<title>EasyAudit Report</title>
<style>
{$css}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <button class="print-btn" onclick="window.print()">Print / PDF</button>
        <div class="header-top">
            <img src="https://crealoz.fr/wp-content/uploads/2023/09/Crealoz-logo-white-01-1-2048x720.png" alt="Crealoz" class="header-logo">
            <h1>EasyAudit Report</h1>
        </div>
        <div class="meta">
            <span>Path: {$scanPathHtml}</span>
            <span>Date: {$scanDateHtml}</span>
        </div>
    </div>

    <div class="summary">
        <div class="summary-card card-total" data-filter="all">
            <div class="label">Total Issues</div>
            <div class="value">{$total}</div>
        </div>
        <div class="summary-card card-high" data-filter="high">
            <div class="label">High</div>
            <div class="value">{$highCount}</div>
        </div>
        <div class="summary-card card-medium" data-filter="medium">
            <div class="label">Medium</div>
            <div class="value">{$mediumCount}</div>
        </div>
        <div class="summary-card card-low" data-filter="low">
            <div class="label">Low</div>
            <div class="value">{$lowCount}</div>
        </div>
    </div>

    {$rulesHtml}

    <div class="footer">
        Generated by <a href="https://github.com/crealoz/easyaudit-cli">Crealoz EasyAudit CLI</a> &mdash; {$scanDateHtml}<br>
        <a href="https://github.com/sponsors/crealoz" class="support screen-only">💜 Support this project 💜</a>
        <span class="print-only">To support this open-source project: donate at https://github.com/sponsors/crealoz</span>
    </div>
</div>
<script>
(function(){
    var container = document.querySelector('.container');
    document.querySelectorAll('.summary-card[data-filter]').forEach(function(card){
        card.addEventListener('click', function(){
            var f = card.getAttribute('data-filter');
            var current = container.getAttribute('data-filter');
            container.setAttribute('data-filter', (f === current || f === 'all') ? '' : f);
        });
    });
})();
</script>
</body>
</html>
HTML;
    }

    private function getRules(array $findings): array
    {
        $rules = [];
        foreach ($findings as $finding) {
            if (!is_array($finding) || empty($finding['files']) || !is_array($finding['files'])) {
                continue;
            }

            $ruleId = $finding['ruleId'] ?? 'unknown';
            $ruleName = $finding['name'] ?? $ruleId;
            $shortDesc = $finding['shortDescription'] ?? '';
            $longDesc = $finding['longDescription'] ?? '';
            $files = $finding['files'];

            $counts = array_count_values(array_map(fn($f) => $f['severity'] ?? 'medium', $files));

            $rules[] = [
                'ruleId' => $ruleId,
                'name' => $ruleName,
                'shortDescription' => $shortDesc,
                'longDescription' => $longDesc,
                'files' => $files,
                'highCount' => $counts['high'] ?? 0,
                'mediumCount' => $counts['medium'] ?? 0,
                'lowCount' => $counts['low'] ?? 0,
            ];
        }
        return $rules;
    }

    private function severityBadge(string $severity): string
    {
        return match ($severity) {
            'high' => '<span class="badge badge-high">High</span>',
            'medium' => '<span class="badge badge-medium">Medium</span>',
            default => '<span class="badge badge-low">Low</span>',
        };
    }

    private function formatDescription(string $text): string
    {
        $labels = ['Impact:', 'Why change:', 'How to fix:'];
        $paragraphs = explode("\n", $text);
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ($labels as $label) {
                $escapedLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if (str_starts_with($escaped, $escapedLabel)) {
                    $escaped = '<strong>' . $escapedLabel . '</strong>' . substr($escaped, strlen($escapedLabel));
                    break;
                }
            }
            $html .= '<p>' . $escaped . '</p>';
        }
        return $html;
    }
}
