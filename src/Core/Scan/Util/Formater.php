<?php

namespace EasyAudit\Core\Scan\Util;

use EasyAudit\Support\Paths;

class Formater
{
    public static function formatError(
        string $file,
        int $startLine,
        string $message = '',
        string $severity = 'warning',
        int $endLine = 0,
        array $metadata = []
    ): array {
        $result = [
            'file' => Paths::getAbsolutePath($file),
            'startLine' => $startLine,
            'endLine' => $endLine === 0 ? $startLine : $endLine,
            'message' => $message,
            'severity' => $severity,
        ];

        if (!empty($metadata)) {
            $result['metadata'] = $metadata;
        }

        return $result;
    }
}