<?php

namespace EasyAudit\Core\Report;

class JsonReporter implements ReporterInterface
{

    public function generate(array $findings): string
    {
        return json_encode($findings, JSON_PRETTY_PRINT);
    }
}