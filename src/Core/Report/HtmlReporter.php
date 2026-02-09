<?php

namespace EasyAudit\Core\Report;

class HtmlReporter implements ReporterInterface
{
    public function generate(array $findings): string
    {
        $scanPath = $findings['metadata']['scan_path'] ?? 'Unknown';
        $scanDate = date('Y-m-d H:i:s');

        $errors = 0;
        $warnings = 0;
        $notes = 0;
        $rules = [];

        foreach ($findings as $key => $finding) {
            if (!is_array($finding) || empty($finding['files']) || !is_array($finding['files'])) {
                continue;
            }

            $ruleId = $finding['ruleId'] ?? 'unknown';
            $ruleName = $finding['name'] ?? $ruleId;
            $shortDesc = $finding['shortDescription'] ?? '';
            $files = $finding['files'];

            $ruleErrors = 0;
            $ruleWarnings = 0;
            $ruleNotes = 0;

            foreach ($files as $file) {
                $sev = $file['severity'] ?? 'warning';
                match ($sev) {
                    'error' => $ruleErrors++,
                    'warning' => $ruleWarnings++,
                    default => $ruleNotes++,
                };
            }

            $errors += $ruleErrors;
            $warnings += $ruleWarnings;
            $notes += $ruleNotes;

            $rules[] = [
                'ruleId' => $ruleId,
                'name' => $ruleName,
                'shortDescription' => $shortDesc,
                'files' => $files,
                'errorCount' => $ruleErrors,
                'warningCount' => $ruleWarnings,
                'noteCount' => $ruleNotes,
            ];
        }

        $total = $errors + $warnings + $notes;

        $rulesHtml = '';
        foreach ($rules as $rule) {
            $severity = 'note';
            if ($rule['errorCount'] > 0) {
                $severity = 'error';
            } elseif ($rule['warningCount'] > 0) {
                $severity = 'warning';
            }

            $badge = $this->severityBadge($severity);
            $count = count($rule['files']);
            $rowsHtml = '';

            foreach ($rule['files'] as $file) {
                $rawPath = $file['file'] ?? '';
                if ($scanPath !== 'Unknown' && str_starts_with($rawPath, $scanPath)) {
                    $rawPath = ltrim(substr($rawPath, strlen($scanPath)), '/');
                }
                $filePath = htmlspecialchars($rawPath, ENT_QUOTES, 'UTF-8');
                $line = (int)($file['startLine'] ?? $file['line'] ?? 1);
                $message = htmlspecialchars($file['message'] ?? '', ENT_QUOTES, 'UTF-8');
                $fileSev = $file['severity'] ?? 'warning';
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

            $ruleName = htmlspecialchars($rule['name'], ENT_QUOTES, 'UTF-8');
            $ruleDesc = htmlspecialchars($rule['shortDescription'], ENT_QUOTES, 'UTF-8');

            $rulesHtml .= <<<RULE
            <details class="rule-card" data-severity="{$severity}" closed>
                <summary class="rule-header">
                    <span class="rule-title">{$ruleName}</span>
                    {$badge}
                    <span class="rule-count">{$count} issue(s)</span>
                </summary>
                <div class="rule-body">
                    <p class="rule-description">{$ruleDesc}</p>
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

        $scanPathHtml = htmlspecialchars($scanPath, ENT_QUOTES, 'UTF-8');
        $scanDateHtml = htmlspecialchars($scanDate, ENT_QUOTES, 'UTF-8');

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
        <div class="summary-card card-error" data-filter="error">
            <div class="label">Errors</div>
            <div class="value">{$errors}</div>
        </div>
        <div class="summary-card card-warning" data-filter="warning">
            <div class="label">Warnings</div>
            <div class="value">{$warnings}</div>
        </div>
        <div class="summary-card card-note" data-filter="note">
            <div class="label">Notes</div>
            <div class="value">{$notes}</div>
        </div>
    </div>

    {$rulesHtml}

    <div class="footer">Generated by EasyAudit CLI &mdash; {$scanDateHtml}</div>
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

    private function severityBadge(string $severity): string
    {
        return match ($severity) {
            'error' => '<span class="badge badge-error">Error</span>',
            'warning' => '<span class="badge badge-warning">Warning</span>',
            default => '<span class="badge badge-note">Note</span>',
        };
    }
}
