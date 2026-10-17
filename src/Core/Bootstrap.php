<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Core;

/**
 * Инициализация окружения, безопасная загрузка CRest, перехват ошибок и автозагрузка классов.
 */
class Bootstrap
{
    private static bool $initialized = false;

    /**
     * Основная точка запуска инициализации приложения.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Подавление любого вывода ошибок в STDOUT для обеспечения чистоты JSON-RPC
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $rootDir = dirname(__DIR__, 2);
        $logsDir = $rootDir . '/logs';
        $errorLogFile = $logsDir . '/mcp_error.log';

        // Создание необходимых директорий
        foreach ([$logsDir, $logsDir . '/cache', $logsDir . '/crest'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        ini_set('error_log', $errorLogFile);

        // Регистрация автозагрузчика классов
        self::registerAutoloader();

        // Инициализация логгера
        Logger::init($logsDir);

        // Регистрация обработчиков ошибок для предотвращения вывода в STDOUT
        self::registerErrorHandlers();

        // Загрузка настроек
        $settingsFile = $rootDir . '/settings.php';
        if (file_exists($settingsFile)) {
            require_once $settingsFile;
        }

        // Безопасная загрузка CRest (с учетом чувствительности к регистру на Ubuntu)
        $crestFile = $rootDir . '/CRest.php';
        if (!file_exists($crestFile)) {
            $crestFile = $rootDir . '/crest.php';
        }

        if (file_exists($crestFile)) {
            require_once $crestFile;
        } else {
            Logger::error('CRest library file not found in ' . $rootDir, [], 'bootstrap');
        }

        self::$initialized = true;
    }

    /**
     * PSR-4 автозагрузка классов пространства имен Bitrix24Mcp.
     */
    private static function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Bitrix24Mcp\\';
            $baseDir = dirname(__DIR__) . '/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Перехват ошибок и исключений без вывода в консоль.
     */
    private static function registerErrorHandlers(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) {
                return false;
            }

            Logger::error("PHP Notice/Warning: {$errstr}", [
                'errno' => $errno,
                'file'  => $errfile,
                'line'  => $errline,
            ], 'php_error');

            return true; // Предотвратить стандартный обработчик PHP
        });

        set_exception_handler(function (\Throwable $exception): void {
            Logger::error('Uncaught Exception: ' . $exception->getMessage(), [
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ], 'uncaught_exception');
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error("Fatal Error: {$error['message']}", [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type'],
                ], 'fatal_error');
            }
        });
    }
}
