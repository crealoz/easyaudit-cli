<?php

namespace EasyAudit\Core\Scan\Util;

class Formater
{
    public static function formatError(string $file, int $line, string $message = '', string $severity = 'warning'): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'message' => $message,
            'severity' => $severity,
        ];
    }
}