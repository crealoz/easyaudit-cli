<?php

namespace EasyAudit\Core\Report;

class SarifReporter implements ReporterInterface
{


    public function generate(array $findings): string
    {
        $results = [];

        foreach ($findings as $finding) {
            $locations = [];
            foreach ($finding['files'] ?? [] as $location) {
                $locations[] = [
                    'physicalLocation' => [
                        'artifactLocation' => [
                            'uri' => $location['file'] ?? ''
                        ],
                        'region' => [
                            'startLine' => $location['line'] ?? 1
                        ]
                    ]
                ];
            }
            if (empty($locations)) {
                continue;
            }
            $results[] = [
                'ruleId'   => $finding['ruleId'] ?? 'EASYAUDIT',
                'message'  => ['text' => $finding['message'] ?? ''],
                'locations'=> $locations
            ];
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
                'results' => $results
            ]]
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}