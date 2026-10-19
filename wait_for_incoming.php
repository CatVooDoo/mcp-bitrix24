<?php

declare(strict_types=1);

/**
 * Фоновый слушатель новых сообщений от KelvinMarty в Линии 18.
 * При обнаружении нового сообщения немедленно завершает работу с кодом 0,
 * передавая управление и полный текст сообщения агенту для интеллектуальной обработки.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Services\ChatService;

Bootstrap::init();
date_default_timezone_set('Europe/Moscow');

$stateFile = __DIR__ . '/logs/cache/autoresponder_line18_state.json';
$state = [];
if (file_exists($stateFile)) {
    $state = json_decode((string)file_get_contents($stateFile), true) ?: [];
}

$dialogId = 'chat17478';
$lastRepliedMsgId = (int)($state[$dialogId]['last_replied_msg_id'] ?? 0);
$chatService = new ChatService();

echo "[" . date('Y-m-d H:i:s') . "] Слушатель запущен: мониторинг сообщений KelvinMarty в {$dialogId} (после ID {$lastRepliedMsgId})...\n";

$maxSeconds = 3600; // До 1 часа на сессию ожидания
$startTime = time();

while (time() - $startTime < $maxSeconds) {
    $historyRes = $chatService->getHistory($dialogId, 10);
    if ($historyRes['success'] && !empty($historyRes['messages'])) {
        $messages = $historyRes['messages'];
        usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));

        foreach ($messages as $msg) {
            $msgId = (int)($msg['id'] ?? 0);
            $authorId = (int)($msg['author_id'] ?? 0);
            $text = trim((string)($msg['text'] ?? ''));

            // Сообщение от клиента KelvinMarty (ID: 1712)
            if ($msgId > $lastRepliedMsgId && ($authorId === 1712 || ($authorId > 0 && $authorId !== 1650))) {
                echo "\n=== НОВОЕ ВХОДЯЩЕЕ СООБЩЕНИЕ ОТ KELVINMARTY ===\n";
                echo "MSG_ID: {$msgId}\n";
                echo "AUTHOR_ID: {$authorId}\n";
                echo "DATE: " . ($msg['date'] ?? '') . "\n";
                echo "TEXT: {$text}\n";
                echo "===============================================\n";
                exit(0);
            }
        }
    }

    sleep(5);
}

echo "[" . date('Y-m-d H:i:s') . "] Таймаут сессии (10 мин), плановый перезапуск...\n";
exit(0);
