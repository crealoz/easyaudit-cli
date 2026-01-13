<?php

namespace EasyAudit\Console\Util;

class Filenames
{
    /**
     * Sanitize a file path to create a valid patch filename.
     *
     * @param string $filePath Original file path
     * @return string Sanitized filename (without extension)
     */
    public static function sanitize(string $filePath): string
    {
        // Remove leading slashes
        $filename = ltrim($filePath, '/');

        // Replace path separators with underscores
        $filename = str_replace(['/', '\\'], '_', $filename);

        // Remove .php or .xml extension (will add .patch)
        return preg_replace('/\.(php|xml)$/', '', $filename);
    }
}
