<?php

namespace EasyAudit\Console\Util;

class Confirm
{

    /**
     * Ask the user a yes/no question and return their response as a boolean.
     *
     * @param string $q The question to ask the user.
     * @param bool $defaultYes If true, the default answer is "yes" (Y/n). If false, the default answer is "no" (y/N).
     * @return bool True if the user answered "yes", false if they answered "no".
     */
    public static function confirm(string $q, bool $defaultYes = true): bool
    {
        $suffix = $defaultYes ? " [Y/n]: " : " [y/N]: ";
        fwrite(STDOUT, $q . $suffix);
        $in = strtolower(trim(fgets(STDIN) ?: ''));
        if ($in === '') {
            return $defaultYes;
        }
        return in_array($in, ['y','yes'], true);
    }
}