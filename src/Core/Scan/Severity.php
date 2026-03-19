<?php

namespace EasyAudit\Core\Scan;

enum Severity: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    /**
     * Map internal severity to SARIF spec-compliant level.
     */
    public function toSarif(): string
    {
        return match ($this) {
            self::HIGH => 'error',
            self::MEDIUM => 'warning',
            self::LOW => 'note',
        };
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'High',
            self::MEDIUM => 'Medium',
            self::LOW => 'Low',
        };
    }

    public static function default(): self
    {
        return self::MEDIUM;
    }
}
