<?php

namespace EasyAudit\Core\Report;

interface ReporterInterface
{
    public function generate(array $findings): string;
}