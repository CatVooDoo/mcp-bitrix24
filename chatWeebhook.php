<?php

declare(strict_types=1);

/**
 * Точка входа для исходящих вебхуков Битрикс24 (chatWeebhook.php).
 * Важно: название файла пишется строго с двумя "e" (chatWeebhook.php).
 * Принимает события: ONIMBOTMESSAGEADD, ONIMBOTJOINCHAT, ONIMCOMMANDADD, ONIMMESSAGEADD, ONIMCONNECTORMESSAGEADD.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\CrmService;

Bootstrap::init();

// Чтение входящих данных (POST urlencoded или JSON)
$rawInput = (string)file_get_contents('php://input');
$requestData = $_POST;

if (empty($requestData) && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $requestData = $decoded;
    }
}

if (empty($requestData)) {
    $requestData = $_REQUEST;
}

// 1. Валидация токена исходящего вебхука
$expectedToken = defined('B24_OUTGOING_WEBHOOK_TOKEN') ? (string)B24_OUTGOING_WEBHOOK_TOKEN : '';
if ($expectedToken !== '') {
    $incomingToken = $requestData['auth']['application_token']
        ?? ($requestData['application_token'] ?? '');

    if (!hash_equals($expectedToken, (string)$incomingToken)) {
        Logger::warning('Unauthorized webhook attempt: token mismatch', [
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], 'webhook');

        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => 'Invalid application token']);
        exit;
    }
}

$event = (string)($requestData['event'] ?? 'ONIMBOTMESSAGEADD');
$data = (array)($requestData['data'] ?? []);
$params = (array)($data['PARAMS'] ?? []);
$user = (array)($data['USER'] ?? []);
$chat = (array)($data['CHAT'] ?? []);

// 2. Предотвращение зацикливания (игнорирование системных сообщений, ботов и собственных ответов)
$isSystem = ($params['SYSTEM'] ?? 'N') === 'Y';
$isBotUser = ($user['IS_BOT'] ?? 'N') === 'Y';
$authorId = (int)($params['FROM_USER_ID'] ?? ($params['AUTHOR_ID'] ?? ($user['ID'] ?? 0)));
$botId = defined('B24_BOT_ID') ? (int)B24_BOT_ID : 0;

if ($isSystem || $isBotUser) {
    Logger::debug('Ignoring system or bot message in webhook', [
        'isSystem'  => $isSystem,
        'isBotUser' => $isBotUser,
        'authorId'  => $authorId,
    ], 'webhook');
    http_response_code(200);
    echo json_encode(['result' => 'ignored_system_or_bot_message']);
    exit;
}

if ($botId > 0 && $authorId === $botId) {
    Logger::debug('Ignoring self-message from bot in webhook', ['authorId' => $authorId], 'webhook');
    http_response_code(200);
    echo json_encode(['result' => 'ignored_self_message']);
    exit;
}

// 3. Извлечение ключевых параметров диалога
$chatId = (int)($params['TO_CHAT_ID'] ?? ($params['CHAT_ID'] ?? ($chat['ID'] ?? 0)));
$dialogId = (string)($params['DIALOG_ID'] ?? ($chatId > 0 ? 'chat' . $chatId : ''));
$message = trim((string)($params['MESSAGE'] ?? ''));
$files = (array)($params['FILES'] ?? []);

if ($chatId <= 0 && $dialogId === '') {
    Logger::warning('Webhook missing chatId and dialogId', ['request' => $requestData], 'webhook');
    http_response_code(200);
    echo json_encode(['result' => 'missing_chat_identifiers']);
    exit;
}

// Защита от эхо-петли при B24_BOT_ID = 0 (проверка маркера недавно отправленного ответа бота)
$normalizedDialogKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dialogId ?: ('chat' . $chatId));
$selfReplyLockFile = __DIR__ . "/logs/cache/last_bot_reply_{$normalizedDialogKey}.json";
if (file_exists($selfReplyLockFile)) {
    $lastBotReply = json_decode((string)file_get_contents($selfReplyLockFile), true);
    if (
        is_array($lastBotReply)
        && (time() - (int)($lastBotReply['timestamp'] ?? 0)) <= 10
        && ($lastBotReply['hash'] ?? '') === md5($message)
    ) {
        Logger::debug('Ignoring echo of bot reply in webhook', ['dialogId' => $dialogId], 'webhook');
        http_response_code(200);
        echo json_encode(['result' => 'ignored_bot_echo']);
        exit;
    }
}

// Игнорируем пустые события без текста и без файлов
if ($message === '' && empty($files)) {
    http_response_code(200);
    echo json_encode(['result' => 'ignored_empty_message']);
    exit;
}

// 4. Проверка дебаунсинга (исправлен путь __DIR__ вместо dirname(__DIR__, 2))
$debounceSeconds = defined('B24_DEBOUNCE_SECONDS') ? (int)B24_DEBOUNCE_SECONDS : 0;
if ($debounceSeconds > 0 && $chatId > 0) {
    $lockFile = __DIR__ . "/logs/cache/debounce_{$chatId}.lock";
    $lastTime = file_exists($lockFile) ? (int)file_get_contents($lockFile) : 0;
    $now = time();

    if (($now - $lastTime) < $debounceSeconds) {
        Logger::info("Debounce active for chat {$chatId}, message skipped/buffered", [], 'webhook');
        http_response_code(200);
        echo json_encode(['result' => 'debounced']);
        exit;
    }

    @file_put_contents($lockFile, (string)$now, LOCK_EX);
}

// 5. Немедленный ответ 200 OK Битрикс24, чтобы избежать таймаутов вебхука
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode(['result' => true, 'status' => 'queued']);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

// ---------------------------------------------------------------------------------
// АСИНХРОННАЯ ФОНОВАЯ ОБРАБОТКА (соединение с Битрикс24 уже закрыто с кодом 200 OK)
// ---------------------------------------------------------------------------------

Logger::info("Processing webhook event {$event} for chat {$chatId}", [
    'dialogId' => $dialogId,
    'authorId' => $authorId,
    'message'  => mb_substr($message, 0, 100),
], 'webhook');

// 6. Парсинг привязок к CRM из PARAMS[CHAT_ENTITY_DATA_1/2] и CHAT[ENTITY_DATA_1/2]
$combinedEntityData = implode('|', array_filter([
    (string)($params['CHAT_ENTITY_DATA_1'] ?? ''),
    (string)($params['CHAT_ENTITY_DATA_2'] ?? ''),
    (string)($chat['ENTITY_DATA_1'] ?? ($chat['entity_data_1'] ?? '')),
    (string)($chat['ENTITY_DATA_2'] ?? ($chat['entity_data_2'] ?? '')),
]));
$crmBindings = CrmService::parseEntityData1($combinedEntityData);

// 7. Обогащение контекста: быстрый сбор Досье клиента через CrmService (1 batch запрос)
$crmService = new CrmService();
$dossierResult = $crmService->getClientDossier([
    'contact_id' => $crmBindings['contactId'],
    'company_id' => $crmBindings['companyId'],
    'lead_id'    => $crmBindings['leadId'],
    'deal_id'    => $crmBindings['dealId'],
    'chat_id'    => $chatId,
]);

$clientDossier = $dossierResult['success'] ? ($dossierResult['dossier'] ?? null) : null;

// 8. Формирование контекстного промпта для OpenClaw (чтобы модель видела dialog_id и досье)
$authorName = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')) ?: "User #{$authorId}";
$effectiveDialogId = $dialogId !== '' ? $dialogId : ('chat' . $chatId);
$dossierJson = $clientDossier !== null
    ? json_encode($clientDossier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : 'не найдено (при необходимости используй b24_search_crm_by_comm или b24_get_chat_history)';

$agentPrompt = implode("\n", array_filter([
    "[ВХОДЯЩЕЕ СООБЩЕНИЕ BITRIX24]",
    "dialog_id (для ответа через b24_chat_action): {$effectiveDialogId}",
    "chat_id: {$chatId}",
    "Автор: {$authorName} (ID: {$authorId})",
    "Привязки CRM: " . json_encode($crmBindings, JSON_UNESCAPED_UNICODE),
    "Авто-досье клиента (CRM + Договоры + Задачи): {$dossierJson}",
    !empty($files) ? "Вложения: " . json_encode($files, JSON_UNESCAPED_UNICODE) : null,
    "---",
    "Текст сообщения клиента:",
    $message !== '' ? $message : '[Сообщение без текста, прикреплен файл]',
]));

// 9. Отправка в OpenClaw шлюз
$openClawUrl = defined('OPENCLAW_GATEWAY_URL') ? (string)OPENCLAW_GATEWAY_URL : '';
$openClawToken = defined('OPENCLAW_HOOK_TOKEN') ? (string)OPENCLAW_HOOK_TOKEN : '';

if ($openClawUrl !== '') {
    $openClawPayload = [
        'sessionKey'    => 'b24_chat_' . $chatId,
        'message'       => $agentPrompt,
        'rawMessage'    => $message,
        'event'         => $event,
        'chatId'        => $chatId,
        'dialogId'      => $effectiveDialogId,
        'files'         => $files,
        'author'        => [
            'id'       => $authorId,
            'name'     => $user['NAME'] ?? '',
            'lastName' => $user['LAST_NAME'] ?? '',
        ],
        'crmContext'    => $crmBindings,
        'clientDossier' => $clientDossier,
        'timestamp'     => time(),
    ];

    $ch = curl_init($openClawUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($openClawPayload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_filter([
        'Content-Type: application/json',
        $openClawToken !== '' ? 'Authorization: Bearer ' . $openClawToken : null,
    ]));

    $gwResponse = curl_exec($ch);
    $gwHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $gwError = curl_error($ch);
    curl_close($ch);

    if ($gwError !== '') {
        Logger::error("OpenClaw gateway connection failed: {$gwError}", ['url' => $openClawUrl], 'webhook');
    } else {
        Logger::info("Forwarded to OpenClaw (HTTP {$gwHttpCode})", [
            'sessionKey' => 'b24_chat_' . $chatId,
            'response'   => mb_substr((string)$gwResponse, 0, 200),
        ], 'webhook');
    }
} else {
    Logger::warning('OPENCLAW_GATEWAY_URL is not configured', [], 'webhook');
}
