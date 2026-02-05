<?php

namespace EasyAudit\Service;

class Logger
{
    private string $logDir = 'logs';

    /**
     * Log errors to a file in the logs directory.
     *
     * @param array $errors Associative array of file => error message
     */
    public function logErrors(array $errors): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        $logFile = $this->logDir . '/fix-apply-errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $content = "[$timestamp] Fix-apply errors:\n";

        foreach ($errors as $file => $error) {
            $content .= "  File: $file\n  Error: $error\n\n";
        }

        file_put_contents($logFile, $content, FILE_APPEND);
    }

    /**
     * Log when API returns no changes for a file.
     * Saves the request payload for debugging.
     *
     * @param string $filePath    Path to the file
     * @param array  $rules       Rules that were sent to API
     * @param string $fileContent Content that was sent to API
     */
    public function logNoChanges(string $filePath, array $rules, string $fileContent): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }

        $logFile = $this->logDir . '/fix-apply-no-changes.log';
        $timestamp = date('Y-m-d H:i:s');

        $content = "[$timestamp] No changes generated\n";
        $content .= "File: $filePath\n";
        $content .= "Rules: " . json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $content .= "Content:\n$fileContent\n";
        $content .= str_repeat('=', 80) . "\n\n";

        file_put_contents($logFile, $content, FILE_APPEND);
    }
}
