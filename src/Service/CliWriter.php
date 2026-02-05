<?php

namespace EasyAudit\Service;

/**
 * Centralized CLI output with colors and formatting.
 * All methods are static for convenient access without instantiation.
 */
class CliWriter
{
    // Color codes
    private const RESET = "\033[0m";
    private const GREEN = "\033[32m";
    private const RED = "\033[31m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const MAGENTA = "\033[35m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";

    /**
     * Output a success message (green) with newline.
     */
    public static function success(string $message): void
    {
        echo self::GREEN . $message . self::RESET . "\n";
    }

    /**
     * Output an error message (red) with newline.
     */
    public static function error(string $message): void
    {
        echo self::RED . $message . self::RESET . "\n";
    }

    /**
     * Output a warning message (yellow) with newline.
     */
    public static function warning(string $message): void
    {
        echo self::YELLOW . $message . self::RESET . "\n";
    }

    /**
     * Output an info message (blue) with newline.
     */
    public static function info(string $message): void
    {
        echo self::BLUE . $message . self::RESET . "\n";
    }

    /**
     * Return green colored text (no newline).
     */
    public static function green(string $text): string
    {
        return self::GREEN . $text . self::RESET;
    }

    /**
     * Return blue colored text (no newline).
     */
    public static function blue(string $text): string
    {
        return self::BLUE . $text . self::RESET;
    }

    /**
     * Return bold text (no newline).
     */
    public static function bold(string $text): string
    {
        return self::BOLD . $text . self::RESET;
    }

    /**
     * Output a section header (yellow, bold-like).
     */
    public static function section(string $title): void
    {
        echo "\n" . self::YELLOW . $title . self::RESET . "\n";
    }

    /**
     * Output a boxed header (magenta).
     */
    public static function header(string $title): void
    {
        $line = str_repeat('━', 60);
        echo "\n" . self::BOLD . self::MAGENTA . $line . self::RESET . "\n";
        echo self::BOLD . self::MAGENTA . "  $title" . self::RESET . "\n";
        echo self::BOLD . self::MAGENTA . $line . self::RESET . "\n";
    }

    /**
     * Output a processor header (cyan, bold).
     */
    public static function processorHeader(string $name): void
    {
        echo "\n" . self::BOLD . self::CYAN . "▶ $name" . self::RESET . "\n";
    }

    /**
     * Output a skipped message (dim).
     */
    public static function skipped(string $message): void
    {
        echo self::DIM . "  ○ $message" . self::RESET . "\n";
    }

    /**
     * Output a plain line with optional message.
     */
    public static function line(string $message = ''): void
    {
        echo $message . "\n";
    }

    /**
     * Output a label: value pair with colored value.
     *
     * @param string $label The label text
     * @param string $value The value text
     * @param string $color Color for value: 'green', 'red', 'yellow', 'blue'
     */
    public static function labelValue(string $label, string $value, string $color = 'green'): void
    {
        $colorCode = match ($color) {
            'red' => self::RED,
            'yellow' => self::YELLOW,
            'blue' => self::BLUE,
            default => self::GREEN,
        };
        echo $label . ": " . $colorCode . $value . self::RESET . "\n";
    }

    /**
     * Render a progress bar with status and optional credits.
     *
     * @param int $current Current item number
     * @param int $total Total number of items
     * @param string $filename Current file being processed
     * @param string $status Status text
     * @param int|null $credits Optional credits remaining to display
     */
    public static function progressBar(int $current, int $total, string $filename, string $status, ?int $credits = null): void
    {
        $barWidth = 30;
        $progress = $total > 0 ? $current / $total : 0;
        $filled = (int) round($barWidth * $progress);
        $empty = $barWidth - $filled;

        $bar = self::GREEN . str_repeat('█', $filled) . self::RESET . str_repeat('░', $empty);
        $percent = str_pad((int) ($progress * 100), 3, ' ', STR_PAD_LEFT);

        // Truncate filename if too long
        $maxFilenameLen = 25;
        if (strlen($filename) > $maxFilenameLen) {
            $filename = '...' . substr($filename, -($maxFilenameLen - 3));
        }
        $filename = str_pad($filename, $maxFilenameLen);

        $line = "\r[$bar] {$percent}% | $current/$total | $filename | $status";
        if ($credits !== null) {
            $line .= " | $credits credits";
        }

        echo $line;
    }

    /**
     * Clear the current line (for progress bar cleanup).
     */
    public static function clearLine(): void
    {
        echo "\r" . str_repeat(' ', 100) . "\r";
    }

    /**
     * Output a menu item with index and optional count.
     *
     * @param int|string $index Menu index (displayed in blue)
     * @param string $label Menu item label
     * @param int|null $count Optional count to display
     */
    public static function menuItem(int|string $index, string $label, ?int $count = null): void
    {
        $countStr = '';
        if ($count !== null) {
            $plural = $count > 1 ? 's' : '';
            $countStr = " ($count issue$plural)";
        }
        echo "[" . self::BLUE . $index . self::RESET . "] " . $label . $countStr . "\n";
    }

    /**
     * Output an error message to STDERR (red).
     */
    public static function errorToStderr(string $message): void
    {
        fwrite(STDERR, self::RED . $message . self::RESET . "\n");
    }

    /**
     * Output a result line with severity icon, label, and count.
     *
     * @param string $label    The label text
     * @param int    $count    The count to display
     * @param string $severity Severity level: 'error', 'warning', 'note'
     */
    public static function resultLine(string $label, int $count, string $severity = 'error'): void
    {
        [$icon, $color, $boldColor] = match ($severity) {
            'error' => ['✗', self::RED, "\033[1;31m"],
            'warning' => ['!', self::YELLOW, "\033[1;33m"],
            'note' => ['i', self::BLUE, "\033[1;34m"],
            default => ['•', self::GREEN, "\033[1;32m"],
        };

        echo "  {$color}{$icon}" . self::RESET . " {$label}: {$boldColor}{$count}" . self::RESET . "\n";
    }
}
