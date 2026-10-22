<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Core;

/**
 * Менеджер конфигураций, шаблонов сообщений и промптов.
 * Позволяет ИИ-агенту и скриптам динамически читать и обновлять шаблоны без изменения PHP-кода.
 */
class Config
{
    private static array $cache = [];
    private static ?string $configDir = null;

    public static function getConfigDir(): string
    {
        if (self::$configDir === null) {
            self::$configDir = dirname(__DIR__, 2) . '/config';
        }
        return self::$configDir;
    }

    /**
     * Получить значение из конфигурационного файла с поддержкой точечной нотации (dot-notation).
     *
     * Пример:
     * Config::get('dialog_templates', 'greetings.default');
     * Config::get('service_catalog', 'company.lead_developer_name');
     */
    public static function get(string $fileName, ?string $path = null, mixed $default = null): mixed
    {
        $data = self::loadFile($fileName);
        if ($data === null) {
            return $default;
        }

        if ($path === null || $path === '') {
            return $data;
        }

        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Подстановка параметров в шаблон строки {param_name}.
     */
    public static function render(string $template, array $params = []): string
    {
        foreach ($params as $key => $value) {
            $replacement = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $template = str_replace('{' . $key . '}', $replacement, $template);
        }
        return $template;
    }

    /**
     * Получить и сразу отренедерить шаблон диалога.
     */
    public static function getRendered(string $fileName, string $path, array $params = [], string $default = ''): string
    {
        $tpl = (string)self::get($fileName, $path, $default);
        if ($tpl === '') {
            return $default;
        }
        return self::render($tpl, $params);
    }

    /**
     * Сохранение конфигурации (если ИИ-агент или администратор перезаписывает настройки/шаблоны).
     */
    public static function set(string $fileName, array $data): bool
    {
        $dir = self::getConfigDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filePath = $dir . '/' . basename($fileName, '.json') . '.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $res = @file_put_contents($filePath . '.tmp', $json, LOCK_EX);
        if ($res === false || !@rename($filePath . '.tmp', $filePath)) {
            return false;
        }

        self::$cache[basename($fileName, '.json')] = $data;
        return true;
    }

    private static function loadFile(string $fileName): ?array
    {
        $baseName = basename($fileName, '.json');
        if (isset(self::$cache[$baseName])) {
            return self::$cache[$baseName];
        }

        $filePath = self::getConfigDir() . '/' . $baseName . '.json';
        if (!is_file($filePath)) {
            return null;
        }

        $raw = @file_get_contents($filePath);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                self::$cache[$baseName] = $decoded;
                return $decoded;
            }
        } catch (\Throwable $e) {
            Logger::error("Config parse error in {$filePath}: " . $e->getMessage(), [], 'config');
        }

        return null;
    }
}
