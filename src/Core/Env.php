<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

final class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
    public static function bool(string $key, bool $default = false): bool
    {
        return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }
    public static function root(): string { return dirname(__DIR__, 2); }
    public static function dataDir(): string { return self::get('B24_DATA_DIR', self::root() . '/var'); }
}
