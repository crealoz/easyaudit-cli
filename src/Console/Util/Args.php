<?php

namespace EasyAudit\Console\Util;

final class Args
{
    /**
     * Short flag to long flag mapping.
     */
    private const SHORT_FLAGS = [
        'h' => 'help',
        'v' => 'verbose',
        'q' => 'quiet',
    ];

    /**
     * Parse command line arguments into options and positional arguments.
     * Options can be:
     *   - Long: `--key=value` or `--flag`
     *   - Short: `-h` (mapped to --help), `-v` (mapped to --verbose)
     * Positional arguments are collected in the second array.
     *
     * @param  array $argv
     * @return array{0: array, 1: string}
     */
    public static function parse(array $argv): array
    {
        $opts = [];
        $rest = '';

        foreach ($argv as $a) {
            if (str_starts_with($a, '--')) {
                self::parseLongOption($a, $opts);
                continue;
            }
            if (str_starts_with($a, '-') && strlen($a) > 1) {
                self::parseShortFlags($a, $opts);
                continue;
            }
            $rest = $a;
        }

        return [$opts, $rest];
    }

    /**
     * Parse a long option (--key=value or --flag).
     */
    private static function parseLongOption(string $arg, array &$opts): void
    {
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $opts[substr($arg, 2)] = true;
            return;
        }

        $k = substr($arg, 2, $eq - 2);
        $v = substr($arg, $eq + 1);

        if (!isset($opts[$k])) {
            $opts[$k] = $v;
            return;
        }

        if (!is_array($opts[$k])) {
            $opts[$k] = [$opts[$k]];
        }
        $opts[$k][] = $v;
    }

    /**
     * Parse short flags (-h, -v, -hv combined).
     */
    private static function parseShortFlags(string $arg, array &$opts): void
    {
        $flags = substr($arg, 1);
        for ($i = 0, $len = strlen($flags); $i < $len; $i++) {
            $short = $flags[$i];
            $opts[self::SHORT_FLAGS[$short] ?? $short] = true;
        }
    }

    /**
     * Get a string option from the parsed options array.
     * If the option is not set or is an array, return the default value.
     *
     * @param  array       $opts
     * @param  string      $key
     * @param  string|null $default
     * @return string|null
     */
    public static function optStr(array $opts, string $key, ?string $default = null): ?string
    {
        return isset($opts[$key]) && !is_array($opts[$key]) ? (string)$opts[$key] : $default;
    }

    /**
     * Get an array option from the parsed options array.
     * If the option is not set, return an empty array.
     * If the option is a single value, return it as a single-element array.
     *
     * @param  array  $opts
     * @param  string $key
     * @return array|string[]
     */
    public static function optArr(array $opts, string $key): array
    {
        if (!isset($opts[$key])) {
            return [];
        }
        return is_array($opts[$key]) ? $opts[$key] : [(string)$opts[$key]];
    }

    /**
     * Get a boolean option from the parsed options array.
     * Recognizes true values: true, "1", "true", "yes", "y", "on" (case insensitive).
     * If the option is not set, return the default value.
     *
     * @param  array  $opts
     * @param  string $key
     * @param  bool   $default
     * @return bool
     */
    public static function optBool(array $opts, string $key, bool $default = false): bool
    {
        if (!isset($opts[$key])) {
            return $default;
        }
        $v = $opts[$key];
        if ($v === true) {
            return true;
        }
        $sv = strtolower((string)$v);
        return in_array($sv, ['1','true','yes','y','on'], true);
    }
}
