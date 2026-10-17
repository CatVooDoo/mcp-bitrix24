<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Core;

/**
 * Структурированный JSON-логгер для записи в файлы без вывода в STDOUT.
 * Критически важен для MCP STDIO режима, чтобы не нарушать парсинг JSON-RPC.
 */
class Logger
{
    private const SENSITIVE_KEYS = [
        'token',
        'auth',
        'password',
        'secret',
        'access_token',
        'refresh_token',
        'application_token',
        'authorization',
        'app_sid',
        'auth_id',
    ];

    private static ?string $logDir = null;

    /**
     * Инициализация пути к директории логов.
     */
    public static function init(?string $logDir = null): void
    {
        if ($logDir !== null) {
            self::$logDir = rtrim($logDir, '/\\');
        } elseif (self::$logDir === null) {
            self::$logDir = dirname(__DIR__, 2) . '/logs';
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0775, true);
        }
    }

    /**
     * Логирование информационного события.
     */
    public static function info(string $message, array $context = [], string $channel = 'mcp'): void
    {
        self::log('INFO', $message, $context, $channel);
    }

    /**
     * Логирование предупреждения.
     */
    public static function warning(string $message, array $context = [], string $channel = 'mcp'): void
    {
        self::log('WARNING', $message, $context, $channel);
    }

    /**
     * Логирование ошибки.
     */
    public static function error(string $message, array $context = [], string $channel = 'mcp'): void
    {
        self::log('ERROR', $message, $context, $channel);
    }

    /**
     * Логирование отладочной информации.
     */
    public static function debug(string $message, array $context = [], string $channel = 'mcp'): void
    {
        self::log('DEBUG', $message, $context, $channel);
    }

    /**
     * Запись структурированного JSON-лога в файл.
     */
    public static function log(string $level, string $message, array $context = [], string $channel = 'mcp'): void
    {
        if (self::$logDir === null) {
            self::init();
        }

        $filePath = self::$logDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $channel) . '.log';

        $entry = [
            'timestamp' => date('c'),
            'level'     => $level,
            'channel'   => $channel,
            'message'   => $message,
            'context'   => self::maskSensitiveData($context),
            'pid'       => getmypid(),
        ];

        $jsonLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonLine === false) {
            $jsonLine = json_encode([
                'timestamp' => date('c'),
                'level'     => 'ERROR',
                'channel'   => $channel,
                'message'   => 'Failed to json_encode log entry: ' . json_last_error_msg(),
                'pid'       => getmypid(),
            ]);
        }

        @file_put_contents($filePath, $jsonLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Маскирование приватных данных (токенов, паролей, секретов).
     */
    private static function maskSensitiveData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitivePattern) {
                if (str_contains($lowerKey, $sensitivePattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                if (is_string($value) && strlen($value) > 8) {
                    $result[$key] = substr($value, 0, 4) . '***' . substr($value, -4);
                } else {
                    $result[$key] = '***MASKED***';
                }
            } elseif (is_array($value)) {
                $result[$key] = self::maskSensitiveData($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
