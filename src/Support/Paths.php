<?php
namespace EasyAudit\Support;

final class Paths
{
    public static function configDir(): string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        $base = $xdg && $xdg !== '' ? $xdg : rtrim(getenv('HOME') ?: sys_get_temp_dir(), '/') . '/.config';
        if (!is_dir($base. '/easyaudit') && !@mkdir($base. '/easyaudit', 0700, true) && !is_dir($base. '/easyaudit')) {
            throw new \RuntimeException('Failed to create config directory: ' . $base. '/easyaudit');
        }
        return $base . '/easyaudit';
    }

    public static function configFile(): string
    {
        return self::configDir() . '/config.json';
    }

    public static function updateConfigFile(array $data): void
    {
        $f = self::configFile();
        @chmod(dirname($f), 0700);
        $existing = json_decode(@file_get_contents($f) ?: '{}', true);
        $json = json_encode(array_merge($existing, $data), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($f, $json) === false) {
            throw new \RuntimeException('Failed to write config data to file.');
        }
        @chmod($f, 0600);
    }

    public static function getConfig(mixed $entry): mixed
    {
        $f = self::configFile();
        if (!is_file($f) || !is_readable($f)) {
            return '';
        }
        $content = file_get_contents($f);
        if ($content === false) {
            return '';
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return '';
        }
        if (is_array($entry)) {
            $result = [];
            foreach ($entry as $e) {
                $result[$e] = $data[$e] ?? '';
            }
            return $result;
        }
        if (!isset($data[$entry])) {
            return '';
        }
        return (string)$data[$entry] ?? '';
    }

    /**
     * Convert a relative path to an absolute path.
     * If the path is already absolute, return it as is.
     * If the path contains ../ or ./, resolve it.
     * @param string $path
     * @return string
     */
    public static function getAbsolutePath(string $path): string
    {
        if ($path === '' || $path === '.' || $path === './') {
            return getcwd() ?: '/';
        }

        if ($path[0] === '/') {
            return $path;
        }

        // tente realpath, sinon compose avec CWD
        $rp = realpath($path);
        if ($rp !== false) {
            return $rp;
        }

        $cwd = getcwd() ?: '/';
        return rtrim($cwd, '/').'/'.ltrim($path, './');
    }
}
