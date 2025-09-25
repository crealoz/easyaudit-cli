<?php

namespace EasyAudit\Core\Report;

class SarifReporter implements ReporterInterface
{


    public function generate(array $findings): string
    {
        $results = [];

        $scanRoot = getenv('GITHUB_WORKSPACE') ?: (defined('EA_SCAN_PATH') ? EA_SCAN_PATH : getcwd());
        $root = rtrim(realpath($scanRoot) ?: $scanRoot, '/\\') . '/';

        foreach ($findings as $finding) {
            if (empty($finding['files']) || !is_array($finding['files'])) {
                continue;
            }
            foreach ($finding['files'] as $location) {
                $abs = str_replace('\\', '/', $location['file'] ?? '');
                $rel = ltrim(str_replace($root, '', $abs), '/');
                $uri = $rel !== '' ? $rel : basename($abs); // fallback propre
                $results[] = [
                    'ruleId' => $finding['ruleId'] ?? 'EASYAUDIT',
                    'message' => ['text' => $finding['message'] ?? ''],
                    'locations' => [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $uri ?? '',
                                "uriBaseId" => "SRCROOT"
                            ],
                            'region' => [
                                'startLine' => $location['line'] ?? 1
                            ]
                        ]
                    ]
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
                        'version' => '1.0.0'
                    ]
                ],
                'originalUriBaseIds' => ['SRCROOT' => ['uri' => 'file:///']],
                'results' => $results
            ]]
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}