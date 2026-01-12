<?php

namespace EasyAudit\Core\Scan;

/**
 * Maps ruleIds to external tools that can fix them automatically.
 * Issues with these ruleIds will be excluded from the report and
 * a summary will be printed suggesting to run the external tool.
 */
class ExternalToolMapping
{
    /**
     * Mapping of ruleId => tool configuration
     */
    public const MAPPINGS = [
        'magento.code.useless-object-manager-import' => [
            'tool' => 'php-cs-fixer',
            'command' => 'php-cs-fixer fix --rules=no_unused_imports',
            'description' => 'unused imports',
        ],
    ];

    /**
     * Check if a ruleId can be fixed by an external tool
     */
    public static function isExternallyFixable(string $ruleId): bool
    {
        return isset(self::MAPPINGS[$ruleId]);
    }

    /**
     * Get the command to run for a given ruleId
     */
    public static function getCommand(string $ruleId): ?string
    {
        return self::MAPPINGS[$ruleId]['command'] ?? null;
    }

    /**
     * Get the tool name for a given ruleId
     */
    public static function getTool(string $ruleId): ?string
    {
        return self::MAPPINGS[$ruleId]['tool'] ?? null;
    }

    /**
     * Get the description for a given ruleId
     */
    public static function getDescription(string $ruleId): ?string
    {
        return self::MAPPINGS[$ruleId]['description'] ?? null;
    }
}
