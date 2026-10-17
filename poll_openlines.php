#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Скрипт автоматического опроса Открытых линий и чатов Битрикс24 (для Cron и автоматизаций OpenClaw).
 * 
 * Использование в CLI / Cron:
 *   php poll_openlines.php [--forward] [--limit=30] [--all] [--force] [--json] [--line=24]
 * 
 * Пример записи в crontab (каждую минуту):
 *   * * * * * php /var/www/html/poll_openlines.php --forward >> /var/www/html/logs/cron_poller.log 2>&1
 * 
 * Использование по HTTP (автоматизации OpenClaw):
 *   GET /poll_openlines.php?forward=1&token=ВАШ_MCP_HTTP_AUTH_TOKEN
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\OpenLinesPoller;

Bootstrap::init();

$isCli = (php_sapi_name() === 'cli');

// 1. Обработка параметров запуска (CLI vs HTTP)
if ($isCli) {
    $shortopts = "f::l::a::j::h::";
    $longopts  = ["forward::", "limit::", "all::", "force::", "json::", "help::", "line::"];
    $options = getopt($shortopts, $longopts);

    if (isset($options['h']) || isset($options['help'])) {
        echo "Bitrix24 OpenLines Poller for OpenClaw\n";
        echo "Использование: php poll_openlines.php [параметры]\n\n";
        echo "Параметры:\n";
        echo "  --forward, -f   Автоматически отправлять новые сообщения клиентов в OpenClaw Gateway\n";
        echo "  --limit=N, -l   Количество проверяемых диалогов (по умолчанию: 30)\n";
        echo "  --all, -a       Опрашивать все типы диалогов (по умолчанию только Открытые линии)\n";
        echo "  --force         Игнорировать кеш ранее просмотренных сообщений\n";
        echo "  --json, -j      Вывести результат в формате JSON\n";
        echo "  --line=N        Опрашивать только диалоги Открытой линии с указанным ID\n";
        echo "  --help, -h      Показать данную справку\n";
        exit(0);
    }

    $forward = isset($options['forward']) || isset($options['f']);
    $limit = (int)($options['limit'] ?? ($options['l'] ?? 30));
    $onlyOpenlines = !isset($options['all']) && !isset($options['a']);
    $force = isset($options['force']);
    $lineId = (string)($options['line'] ?? '');
    $isJson = isset($options['json']) || isset($options['j']);
} else {
    // Режим HTTP
    header('Content-Type: application/json; charset=utf-8');

    // Проверка токена авторизации (если задан MCP_HTTP_AUTH_TOKEN)
    $requiredToken = defined('MCP_HTTP_AUTH_TOKEN') ? (string)MCP_HTTP_AUTH_TOKEN : '';
    if ($requiredToken !== '') {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $queryToken = (string)($_GET['token'] ?? '');

        $providedToken = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $providedToken = trim(substr($authHeader, 7));
        } elseif ($queryToken !== '') {
            $providedToken = $queryToken;
        }

        if (!hash_equals($requiredToken, $providedToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Неверный токен авторизации (MCP_HTTP_AUTH_TOKEN)']);
            exit;
        }
    }

    $forward = !empty($_GET['forward']) || !empty($_POST['forward']);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    $onlyOpenlines = empty($_GET['all']) && empty($_POST['all']);
    $force = !empty($_GET['force']) || !empty($_POST['force']);
    $lineId = (string)($_GET['line'] ?? ($_POST['line'] ?? ''));
    $isJson = true;
}

// 2. Выполнение опроса
$poller = new OpenLinesPoller();
$result = $poller->poll([
    'forward'        => $forward,
    'limit'          => $limit > 0 ? $limit : 30,
    'only_openlines' => $onlyOpenlines,
    'force'          => $force,
    'line_id'        => $lineId,
]);

// 3. Вывод результата
if ($isJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// Человекопонятный вывод в терминал
$time = date('Y-m-d H:i:s');
echo "=== [{$time}] Отчет опроса Открытых линий Битрикс24 ===\n";

if (empty($result['success'])) {
    echo "⚠️ Ошибка опроса API: " . ($result['message'] ?? 'Неизвестная ошибка') . " (" . ($result['error'] ?? 'api_error') . ")\n";
    echo "Подсказка: укажите действительный C_REST_WEB_HOOK_URL в settings.php.\n";
    exit(1);
}

if ($lineId !== '') {
    echo "Фильтр по линии: {$lineId}\n";
}
echo "Проверено диалогов: " . ($result['total_checked'] ?? 0) . "\n";
echo "Обнаружено активных: " . ($result['detected_count'] ?? 0) . "\n";
echo "Передано в OpenClaw: " . ($result['forwarded_count'] ?? 0) . "\n\n";

if (empty($result['dialogs'])) {
    echo "Диалогов, требующих внимания, не найдено.\n";
    exit(0);
}

foreach ($result['dialogs'] as $i => $d) {
    $num = $i + 1;
    $badge = match ($d['marker']) {
        'UNANSWERED_CLIENT' => '🚨 ТРЕБУЕТСЯ ОТВЕТ',
        'BOT_REPLIED'       => 'ℹ️ БОТ ОТВЕТИЛ',
        'OPERATOR_ACTIVE'   => '👤 ОПЕРАТОР В СЕТИ',
        'SYSTEM_EVENT'      => '⚙️ СИСТЕМА',
        default             => '💬 ДИАЛОГ',
    };

    $prio = $d['priority'] === 'URGENT' ? '🔥 СРОЧНО' : '🟢 ОБЫЧНЫЙ';

    echo "------------------------------------------------------------\n";
    echo "#{$num} [{$badge}] [{$prio}] {$d['dialog_id']} ({$d['title']})\n";
    $speakerLabel = match ($d['last_speaker']) {
        'CLIENT'   => 'Клиент',
        'OPERATOR' => 'Оператор',
        'BOT'      => 'Бот',
        'SYSTEM'   => 'Система',
        default    => 'Автор',
    };
    echo "{$speakerLabel}: {$d['last_message']['author']} | Ожидание: {$d['waiting_time_human']} | Непрочитанных: {$d['unread_count']}\n";
    echo "CRM привязки: " . json_encode($d['crm_bindings'], JSON_UNESCAPED_UNICODE) . "\n";
    echo "Сообщение: \"{$d['last_message']['text']}\"\n";
}
echo "------------------------------------------------------------\n";
exit(0);
