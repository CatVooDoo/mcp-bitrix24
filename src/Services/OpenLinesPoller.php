<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Сервис периодического опроса Открытых линий и чатов Битрикс24 (Poller для Cron / Automation).
 * Обнаруживает новые и неотвеченные сообщения клиентов, размечает их структурированными
 * маркерами и опционально передает в OpenClaw Gateway.
 */
class OpenLinesPoller
{
    private const URGENT_KEYWORDS = [
        'срочно',
        'ошибка',
        'не работает',
        'жалоба',
        'претензия',
        'верните деньги',
        'возврат',
        'суд',
        'поломка',
        'где заказ',
        'когда заказ',
        'проблема',
        'авария',
        'help',
        'sos',
    ];

    private readonly string $cacheFile;

    public function __construct(
        private readonly ChatService $chatService = new ChatService(),
        private readonly CrmService $crmService = new CrmService(),
        private readonly BitrixClient $client = new BitrixClient()
    ) {
        $this->cacheFile = dirname(__DIR__, 2) . '/logs/cache/poller_last_seen.json';
    }

    /**
     * Основной метод опроса диалогов.
     *
     * @param array $options Опции:
     *   - 'forward' (bool): Отправлять ли события в OpenClaw Gateway (по умолчанию false)
     *   - 'limit' (int): Максимальное количество проверяемых диалогов (по умолчанию 30)
     *   - 'only_openlines' (bool): Опрашивать только Открытые линии (по умолчанию true)
     *   - 'force' (bool): Игнорировать кеш ранее просмотренных сообщений (по умолчанию false)
     *   - 'min_wait_seconds' (int): Минимальное время ожидания ответа клиентом для повторного алерта
     * @return array Результаты опроса со статистикой и найденными диалогами
     */
    public function poll(array $options = []): array
    {
        $forward = !empty($options['forward']);
        $limit = isset($options['limit']) ? (int)$options['limit'] : 30;
        $onlyOpenlines = !isset($options['only_openlines']) || (bool)$options['only_openlines'];
        $force = !empty($options['force']);

        Logger::info('Starting OpenLines poller run', [
            'forward'        => $forward,
            'limit'          => $limit,
            'only_openlines' => $onlyOpenlines,
        ], 'poller');

        $recentRes = $this->chatService->getRecentDialogs($onlyOpenlines, $limit);
        if (!$recentRes['success']) {
            Logger::error('Poller failed to fetch recent dialogs: ' . ($recentRes['message'] ?? ''), [], 'poller');
            return [
                'success' => false,
                'error'   => $recentRes['error'] ?? 'api_error',
                'message' => $recentRes['message'] ?? 'Ошибка получения диалогов',
                'items'   => [],
            ];
        }

        $items = $recentRes['items'] ?? [];
        $lastSeen = $this->loadLastSeen();
        $detectedDialogs = [];
        $forwardedCount = 0;
        $botId = defined('B24_BOT_ID') ? (int)B24_BOT_ID : 0;

        foreach ($items as $item) {
            $dialogId = (string)($item['id'] ?? '');
            $chatId = (int)($item['chat_id'] ?? 0);
            if ($dialogId === '' && $chatId <= 0) {
                continue;
            }

            $effectiveDialogId = $dialogId !== '' ? $dialogId : ('chat' . $chatId);
            $unreadCount = (int)($item['counter'] ?? 0);
            $messageData = (array)($item['message'] ?? []);
            $messageId = (int)($messageData['id'] ?? 0);
            $messageText = trim((string)($messageData['text'] ?? ''));
            $authorId = (int)($messageData['author_id'] ?? 0);
            $userData = (array)($item['user'] ?? []);
            $authorName = trim(($userData['name'] ?? '') . ' ' . ($userData['last_name'] ?? '')) ?: "Пользователь #{$authorId}";
            $messageDate = (string)($messageData['date'] ?? '');
            $messageTimestamp = !empty($messageDate) ? strtotime($messageDate) : time();
            $timeElapsedSeconds = max(0, time() - $messageTimestamp);

            // Определение роли автора последнего сообщения
            $isBot = ($botId > 0 && $authorId === $botId) || ($userData['is_bot'] ?? false);
            $isExtranet = ($userData['extranet'] ?? false) || ($userData['external_auth_id'] ?? '') === 'imconnector';
            
            if ($isBot) {
                $lastSpeaker = 'BOT';
            } elseif ($isExtranet || ($item['type'] ?? '') === 'lines') {
                // Если автор не сотрудник Битрикс24 или тип диалога Открытая линия с внешним клиентом
                $lastSpeaker = ($userData['work_position'] ?? '') === '' && ($userData['department'] ?? []) === [] ? 'CLIENT' : 'OPERATOR';
                if ($authorId > 0 && empty($userData['work_position']) && empty($userData['departments'])) {
                    $lastSpeaker = 'CLIENT';
                }
            } else {
                $lastSpeaker = 'OPERATOR';
            }

            // Проверка новизны сообщения по кешу
            $previousSeen = $lastSeen[$effectiveDialogId] ?? null;
            $previousMessageId = is_array($previousSeen) ? (int)($previousSeen['last_message_id'] ?? 0) : 0;
            $isNewMessage = $messageId > 0 && ($messageId !== $previousMessageId || $force);

            // Определение статусов и маркеров диалога
            $status = 'WAITING_CLIENT';
            $marker = 'CLIENT_ANSWERED';
            $needsAttention = false;

            if ($lastSpeaker === 'CLIENT') {
                $needsAttention = true;
                $status = 'NEED_REPLY';
                $marker = 'UNANSWERED_CLIENT';
            } elseif ($lastSpeaker === 'BOT') {
                $status = 'BOT_REPLIED';
                $marker = 'BOT_REPLIED';
            } elseif ($lastSpeaker === 'OPERATOR') {
                $status = 'OPERATOR_HANDLED';
                $marker = 'OPERATOR_ACTIVE';
            }

            // Определение срочности (приоритета)
            $isUrgent = false;
            $matchedKeyword = null;
            $lowerText = mb_strtolower($messageText);

            foreach (self::URGENT_KEYWORDS as $keyword) {
                if (mb_strpos($lowerText, $keyword) !== false) {
                    $isUrgent = true;
                    $matchedKeyword = $keyword;
                    break;
                }
            }

            if ($unreadCount >= 3 || $timeElapsedSeconds >= 600) {
                $isUrgent = true;
            }

            $priority = $isUrgent ? 'URGENT' : 'NORMAL';

            // Привязки CRM из диалога
            $entityData1 = (string)($item['entity_data_1'] ?? ($item['ENTITY_DATA_1'] ?? ''));
            $entityData2 = (string)($item['entity_data_2'] ?? ($item['ENTITY_DATA_2'] ?? ''));
            $crmBindings = CrmService::parseEntityData1($entityData1 . '|' . $entityData2);

            $dialogMeta = [
                'dialog_id'            => $effectiveDialogId,
                'chat_id'              => $chatId,
                'title'                => (string)($item['title'] ?? ''),
                'unread_count'         => $unreadCount,
                'is_new_message'       => $isNewMessage,
                'needs_attention'      => $needsAttention,
                'marker'               => $marker,
                'status'               => $status,
                'priority'             => $priority,
                'matched_keyword'      => $matchedKeyword,
                'last_speaker'         => $lastSpeaker,
                'waiting_time_seconds' => $timeElapsedSeconds,
                'waiting_time_human'   => $this->formatElapsedTime($timeElapsedSeconds),
                'last_message'         => [
                    'id'        => $messageId,
                    'text'      => $messageText,
                    'date'      => $messageDate,
                    'timestamp' => $messageTimestamp,
                    'author_id' => $authorId,
                    'author'    => $authorName,
                ],
                'crm_bindings'         => $crmBindings,
            ];

            // Формирование текстового промпта с маркерами для агента
            $dialogMeta['agent_prompt'] = $this->buildAgentPrompt($dialogMeta);

            $detectedDialogs[] = $dialogMeta;

            // Если включен форвардинг в OpenClaw и требуется ответ клиента (и сообщение новое)
            if ($forward && $needsAttention && $isNewMessage) {
                $forwardOk = $this->forwardToOpenClaw($dialogMeta);
                if ($forwardOk) {
                    $forwardedCount++;
                }
            }

            // Обновляем состояние в памяти
            if ($messageId > 0) {
                $lastSeen[$effectiveDialogId] = [
                    'last_message_id' => $messageId,
                    'last_speaker'    => $lastSpeaker,
                    'timestamp'       => time(),
                ];
            }
        }

        // Сохранение обновленного состояния кеша
        $this->saveLastSeen($lastSeen);

        Logger::info("Poller finished: checked " . count($items) . " dialogs, detected " . count($detectedDialogs) . ", forwarded {$forwardedCount}", [], 'poller');

        return [
            'success'         => true,
            'total_checked'   => count($items),
            'detected_count'  => count($detectedDialogs),
            'forwarded_count' => $forwardedCount,
            'dialogs'         => $detectedDialogs,
        ];
    }

    /**
     * Формирование структурированного промпта с маркерами для LLM-агента OpenClaw.
     */
    public function buildAgentPrompt(array $meta): string
    {
        $markerHeader = match ($meta['marker']) {
            'UNANSWERED_CLIENT' => '🚨 [ОТКРЫТАЯ ЛИНИЯ: ТРЕБУЕТСЯ ОТВЕТ ИИ-АГЕНТА]',
            'BOT_REPLIED'       => 'ℹ️ [ОТКРЫТАЯ ЛИНИЯ: БОТ УЖЕ ОТВЕТИЛ, ОЖИДАНИЕ КЛИЕНТА]',
            'OPERATOR_ACTIVE'   => '👤 [ОТКРЫТАЯ ЛИНИЯ: РАБОТАЕТ ЖИВОЙ ОПЕРАТОР]',
            default             => '💬 [ОТКРЫТАЯ ЛИНИЯ]',
        };

        $priorityBadge = $meta['priority'] === 'URGENT'
            ? "🔥 [PRIORITY: URGENT] (Внимание: обнаружен триггер срочности или долгое ожидание)"
            : "🟢 [PRIORITY: NORMAL]";

        $lines = [
            $markerHeader,
            $priorityBadge,
            "[MARKER: {$meta['marker']}]",
            "[STATUS: {$meta['status']}]",
            "[LAST_SPEAKER: {$meta['last_speaker']}]",
            "[DIALOG_ID: {$meta['dialog_id']}] (для b24_chat_action)",
            "[CHAT_ID: {$meta['chat_id']}]",
            "[UNREAD_MESSAGES: {$meta['unread_count']}]",
            "[WAITING_TIME: {$meta['waiting_time_human']}]",
            "[CLIENT: {$meta['last_message']['author']} (ID: {$meta['last_message']['author_id']})]",
            "[CRM_BINDINGS: " . json_encode($meta['crm_bindings'], JSON_UNESCAPED_UNICODE) . "]",
        ];

        if (!empty($meta['matched_keyword'])) {
            $lines[] = "[URGENT_TRIGGER_KEYWORD: '{$meta['matched_keyword']}']";
        }

        $lines[] = "---";
        $lines[] = "[ПОСЛЕДНЕЕ СООБЩЕНИЕ ДИАЛОГА]:";
        $lines[] = $meta['last_message']['text'] !== '' ? $meta['last_message']['text'] : '[Вложение/системное событие]';

        return implode("\n", $lines);
    }

    /**
     * Отправка обнаруженного диалога в OpenClaw Gateway.
     */
    private function forwardToOpenClaw(array $meta): bool
    {
        $url = defined('OPENCLAW_GATEWAY_URL') ? (string)OPENCLAW_GATEWAY_URL : '';
        if ($url === '') {
            return false;
        }

        $token = defined('OPENCLAW_HOOK_TOKEN') ? (string)OPENCLAW_HOOK_TOKEN : '';

        // Обогащение досье клиента
        $dossierResult = $this->crmService->getClientDossier([
            'contact_id' => $meta['crm_bindings']['contactId'] ?? 0,
            'company_id' => $meta['crm_bindings']['companyId'] ?? 0,
            'lead_id'    => $meta['crm_bindings']['leadId'] ?? 0,
            'deal_id'    => $meta['crm_bindings']['dealId'] ?? 0,
            'chat_id'    => $meta['chat_id'],
        ]);

        $payload = [
            'sessionKey'    => 'b24_chat_' . $meta['chat_id'],
            'message'       => $meta['agent_prompt'],
            'rawMessage'    => $meta['last_message']['text'],
            'event'         => 'ONOPENLINESPOLL',
            'chatId'        => $meta['chat_id'],
            'dialogId'      => $meta['dialog_id'],
            'marker'        => $meta['marker'],
            'status'        => $meta['status'],
            'priority'      => $meta['priority'],
            'crmContext'    => $meta['crm_bindings'],
            'clientDossier' => $dossierResult['success'] ? ($dossierResult['dossier'] ?? null) : null,
            'timestamp'     => time(),
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_filter([
            'Content-Type: application/json',
            $token !== '' ? 'Authorization: Bearer ' . $token : null,
        ]));

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            Logger::error("Poller forward to OpenClaw failed: {$err}", ['dialogId' => $meta['dialog_id']], 'poller');
            return false;
        }

        Logger::info("Poller forwarded dialog {$meta['dialog_id']} to OpenClaw (HTTP {$code})", [], 'poller');
        return true;
    }

    /**
     * Чтение состояния просмотренных сообщений из кеша.
     */
    private function loadLastSeen(): array
    {
        if (file_exists($this->cacheFile)) {
            $data = json_decode((string)file_get_contents($this->cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    /**
     * Сохранение состояния просмотренных сообщений.
     */
    private function saveLastSeen(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Человекопонятный формат прошедшего времени.
     */
    private function formatElapsedTime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} сек. назад";
        }

        $minutes = (int)floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} мин. назад";
        }

        $hours = (int)floor($minutes / 60);
        $remMin = $minutes % 60;
        return "{$hours} ч. {$remMin} мин. назад";
    }
}
