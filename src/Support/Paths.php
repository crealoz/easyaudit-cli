<?php
namespace EasyAudit\Support;

final class Paths
{
    public static function configDir(): string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        $base = $xdg && $xdg !== '' ? $xdg : rtrim(getenv('HOME') ?: sys_get_temp_dir(), '/') . '/.config';
        return $base . '/easyaudit';
    }

    public static function configFile(): string
    {
        return self::configDir() . '/config.json';
    }
}
