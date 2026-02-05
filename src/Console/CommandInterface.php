<?php

namespace EasyAudit\Console;

interface CommandInterface
{
    /**
     * Execute the command.
     */
    public function run(array $argv): int;

    /**
     * Get command description (short, for main help listing).
     */
    public function getDescription(): string;

    /**
     * Get command usage synopsis.
     * Example: "scan [options] <path>"
     */
    public function getSynopsis(): string;

    /**
     * Get full help text for the command.
     */
    public function getHelp(): string;
}
