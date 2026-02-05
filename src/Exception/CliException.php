<?php

namespace EasyAudit\Exception;

/**
 * Exception for CLI errors that should exit with a specific code.
 * The exception code is used as the exit code.
 */
class CliException extends \RuntimeException
{
    public function __construct(string $message, int $exitCode = 1)
    {
        parent::__construct($message, $exitCode);
    }
}
