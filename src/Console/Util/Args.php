<?php
namespace EasyAudit\Console\Util;

final class Args
{
    /**
     * Parse command line arguments into options and positional arguments.
     * Options are prefixed with `--` and can be in the form `--key=value` or `--flag`.
     * Positional arguments are collected in the second array.
     * @param array $argv
     * @return array
     */
    public static function parse(array $argv): array
    {
        $opts = [];
        $rest = [];
        foreach ($argv as $a) {
            if (str_starts_with($a, '--')) {
                $eq = strpos($a, '=');
                if ($eq !== false) {
                    $k = substr($a, 2, $eq - 2);
                    $v = substr($a, $eq + 1);
                    if (isset($opts[$k])) {
                        if (!is_array($opts[$k])) $opts[$k] = [$opts[$k]];
                        $opts[$k][] = $v;
                    } else {
                        $opts[$k] = $v;
                    }
                } else {
                    $opts[substr($a, 2)] = true;
                }
            } else {
                $rest[] = $a;
            }
        }
        return [$opts, $rest];
    }

    /**
     * Get a string option from the parsed options array.
     * If the option is not set or is an array, return the default value.
     * @param array $opts
     * @param string $key
     * @param string|null $default
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
     * @param array $opts
     * @param string $key
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
     * @param array $opts
     * @param string $key
     * @param bool $default
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
