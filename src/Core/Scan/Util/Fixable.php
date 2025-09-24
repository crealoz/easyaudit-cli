<?php

namespace EasyAudit\Core\Scan\Util;

class Fixable
{
    private array $fixableTypes = [
        ''
    ];

    public function isFixable(string $type): bool
    {
        return true;
    }
}