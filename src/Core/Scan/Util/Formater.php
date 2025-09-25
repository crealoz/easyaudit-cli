<?php

namespace EasyAudit\Core\Scan\Util;

class Formater
{
    public static function formatError(string $file, int $line): array
    {
        return [
            'file' => $file,
            'line' => $line,
        ];
    }
}