<?php

declare(strict_types=1);

/**
 * Фоновый слушатель новых сообщений в Линии 18.
 * Мониторит активные диалоги Линии 18 (chat17478 - KelvinMarty, chat27718 - Oleg и др.).
 * При обнаружении нового сообщения немедленно завершает работу с кодом 0,
 * передавая управление и полный контекст сообщения ИИ-агенту для автономной обработки.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Services\ChatService;

Bootstrap::init();
date_default_timezone_set('Europe/Moscow');

$stateFile = __DIR__ . '/logs/cache/autoresponder_line18_state.json';
$chatService = new ChatService();

$dialogsToMonitor = ['chat17478', 'chat27718'];

echo "[" . date('Y-m-d H:i:s') . "] Слушатель запущен: мониторинг чатов [" . implode(', ', $dialogsToMonitor) . "] Линии 18...\n";

$maxSeconds = 3600; // До 1 часа на сессию ожидания
$startTime = time();

while (time() - $startTime < $maxSeconds) {
    // Читаем актуальный state
    $state = [];
    if (file_exists($stateFile)) {
        $state = json_decode((string)file_get_contents($stateFile), true) ?: [];
    }

    foreach ($dialogsToMonitor as $dialogId) {
        $lastRepliedMsgId = (int)($state[$dialogId]['last_replied_msg_id'] ?? 0);

        $historyRes = $chatService->getHistory($dialogId, 10);
        if ($historyRes['success'] && !empty($historyRes['messages'])) {
            $messages = $historyRes['messages'];
            usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));

            foreach ($messages as $msg) {
                $msgId = (int)($msg['id'] ?? 0);
                $authorId = (int)($msg['author_id'] ?? 0);
                $text = trim((string)($msg['text'] ?? ''));

                // Сообщение от клиента/собеседника (не от нашего оператора 1650 и не системное 0)
                if ($msgId > $lastRepliedMsgId && $authorId > 0 && $authorId !== 1650) {
                    echo "\n=== НОВОЕ ВХОДЯЩЕЕ СООБЩЕНИЕ ===\n";
                    echo "DIALOG_ID: {$dialogId}\n";
                    echo "MSG_ID: {$msgId}\n";
                    echo "AUTHOR_ID: {$authorId}\n";
                    echo "DATE: " . ($msg['date'] ?? '') . "\n";
                    echo "TEXT: {$text}\n";
                    echo "=================================\n";
                    exit(0);
                }
            }
        }
    }

    sleep(5);
}

echo "[" . date('Y-m-d H:i:s') . "] Таймаут сессии (1 час), плановый перезапуск...\n";
exit(0);
