<?php

namespace EasyAudit\Core\Report;

class SarifReporter implements ReporterInterface
{
    public function generate(array $findings): string
    {
        $results = [];

        $scanRoot = getenv('GITHUB_WORKSPACE') ?: (defined('EA_SCAN_PATH') ? EA_SCAN_PATH : getcwd());
        $root = rtrim(realpath($scanRoot) ?: $scanRoot, '/\\') . '/';

        $rules = [];
        foreach ($findings as $finding) {
            if (empty($finding['files']) || !is_array($finding['files'])) {
                continue;
            }
            $rules[] = [
                'id' => $finding['ruleId'] ?? 'EASYAUDIT',
                'name' => $finding['name'] ?? 'EasyAudit Finding',
                'shortDescription' => ['text' => $finding['shortDescription'] ?? ''],
                'fullDescription' => ['text' => $finding['longDescription'] ?? ''],
                'help' => ['text' => $finding['longDescription'] ?? ''],
            ];
            foreach ($finding['files'] as $location) {
                $fileField = str_replace('\\', '/', $location['file'] ?? '');
                $startLine = $location['startLine'] ?? $location['line'] ?? 0;
                $endLine = $location['endLine'] ?? null;

                $physicalLocations = $this->buildPhysicalLocations($fileField, (int)$startLine, $endLine, $root);
                if (empty($physicalLocations)) {
                    continue;
                }

                $results[] = [
                    'ruleId' => $finding['ruleId'] ?? 'EASYAUDIT',
                    'level' => match ($location['severity'] ?? 'medium') {
                        'high' => 'error',
                        'medium' => 'warning',
                        'low' => 'note',
                        default => 'warning',
                    },
                    'message' => ['text' => $location['message'] ?? $finding['message'] ?? ''],
                    'locations' => $physicalLocations,
                ];
            }
        }

        $sarif = [
            'version' => '2.1.0',
            '$schema' => 'https://schemastore.azurewebsites.net/schemas/json/sarif-2.1.0.json',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => 'EasyAudit CLI',
                        'informationUri' => 'https://github.com/crealoz/easyaudit-cli',
                        'rules' => $rules
                    ]
                ],
                'originalUriBaseIds' => ['SRCROOT' => ['uri' => 'file:///']],
                'results' => $results
            ]]
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the `locations` array for a single SARIF result.
     *
     * The file field may either be a single path (legacy single-location finding)
     * or a comma-separated list of "path:line" pairs emitted by multi-location
     * findings such as AroundPlugins::deepPluginStack. In the latter case, the
     * outer startLine is 0 since each path carries its own line.
     *
     * @return array<int, array{physicalLocation: array{artifactLocation: array{uri: string, uriBaseId: string}, region?: array<string, int>}}>
     */
    private function buildPhysicalLocations(string $fileField, int $startLine, ?int $endLine, string $root): array
    {
        if ($fileField === '') {
            return [];
        }

        $segments = $this->splitMultiLocation($fileField);
        $isMulti = count($segments) > 1;
        $locations = [];

        foreach ($segments as $segment) {
            if ($isMulti) {
                [$path, $segmentLine] = $this->splitPathAndLine($segment, 0);
            } else {
                $path = $segment;
                $segmentLine = $startLine > 0 ? $startLine : 1;
            }

            $rel = ltrim(str_replace($root, '', $path), '/');
            $uri = $rel !== '' ? $rel : basename($path);

            $region = [];
            if ($segmentLine > 0) {
                $region['startLine'] = $segmentLine;
                if (!$isMulti && $endLine !== null && $endLine !== $startLine) {
                    $region['endLine'] = $endLine;
                }
            }

            $physicalLocation = [
                'artifactLocation' => [
                    'uri' => $uri,
                    'uriBaseId' => 'SRCROOT',
                ],
            ];
            if (!empty($region)) {
                $physicalLocation['region'] = $region;
            }

            $locations[] = ['physicalLocation' => $physicalLocation];
        }

        return $locations;
    }

    /**
     * Split a multi-location file field on ", ". Only splits when the field actually
     * contains comma-separated entries that look like "path:line"; otherwise treats
     * the value as a single path.
     *
     * @return string[]
     */
    private function splitMultiLocation(string $fileField): array
    {
        if (!str_contains($fileField, ', ')) {
            return [$fileField];
        }
        return array_values(array_filter(array_map('trim', explode(', ', $fileField)), 'strlen'));
    }

    /**
     * Split a segment of the form "path:line" into its path and line components.
     * Falls back to the supplied default line when the segment does not embed one.
     *
     * @return array{0: string, 1: int}
     */
    private function splitPathAndLine(string $segment, int $defaultLine): array
    {
        if (preg_match('/^(.*):(\d+)$/', $segment, $matches)) {
            return [$matches[1], (int)$matches[2]];
        }
        return [$segment, $defaultLine];
    }
}
