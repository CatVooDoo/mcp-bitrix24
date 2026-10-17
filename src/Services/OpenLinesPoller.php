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
     *   - 'line_id' (int|string): Опрашивать только диалоги конкретной Открытой линии (по entity_id)
     *   - 'min_wait_seconds' (int): Минимальное время ожидания ответа клиентом для повторного алерта
     * @return array Результаты опроса со статистикой и найденными диалогами
     */
    public function poll(array $options = []): array
    {
        $forward = !empty($options['forward']);
        $limit = isset($options['limit']) ? (int)$options['limit'] : 30;
        $onlyOpenlines = !isset($options['only_openlines']) || (bool)$options['only_openlines'];
        $force = !empty($options['force']);
        $lineId = isset($options['line_id']) && (string)$options['line_id'] !== ''
            ? (string)$options['line_id']
            : '';

        Logger::info('Starting OpenLines poller run', [
            'forward'        => $forward,
            'limit'          => $limit,
            'only_openlines' => $onlyOpenlines,
            'line_id'        => $lineId,
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

        // Фильтр по конкретной Открытой линии (entity_id вида "connector|<LINE_ID>|...")
        if ($lineId !== '' && is_array($items)) {
            $items = array_values(array_filter($items, function ($item) use ($lineId) {
                $entityId = (string)($item['chat']['entity_id'] ?? ($item['entity_id'] ?? ''));
                if ($entityId === '') {
                    return false;
                }
                $parts = explode('|', $entityId);
                return isset($parts[1]) && (string)$parts[1] === $lineId;
            }));
        }

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
            if ($authorId === 0) {
                $authorName = 'Система Битрикс24';
            } else {
                $authorName = trim(($userData['name'] ?? '') . ' ' . ($userData['last_name'] ?? '')) ?: "Пользователь #{$authorId}";
            }
            $messageDate = (string)($messageData['date'] ?? '');
            $messageTimestamp = !empty($messageDate) ? strtotime($messageDate) : time();
            $timeElapsedSeconds = max(0, time() - $messageTimestamp);

            // Определение роли автора последнего сообщения
            $isBot = ($botId > 0 && $authorId === $botId) || ($userData['bot'] ?? false) || ($userData['is_bot'] ?? false);
            $isConnector = ($userData['connector'] ?? false) || ($userData['external_auth_id'] ?? '') === 'imconnector';
            $isExtranet = ($userData['extranet'] ?? false) || ($userData['type'] ?? '') === 'extranet';
            $isEmployee = !empty($userData['departments']) || !empty($userData['work_position']) || ($userData['intranet_user'] ?? false);

            if ($authorId === 0) {
                $lastSpeaker = 'SYSTEM';
            } elseif ($isBot) {
                $lastSpeaker = 'BOT';
            } elseif ($isConnector || $isExtranet) {
                $lastSpeaker = 'CLIENT';
            } elseif ($isEmployee) {
                $lastSpeaker = 'OPERATOR';
            } else {
                $lastSpeaker = 'CLIENT';
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
            } elseif ($lastSpeaker === 'SYSTEM') {
                $status = 'SYSTEM_EVENT';
                $marker = 'SYSTEM_EVENT';
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

            // Долгий срок ожидания или скопившиеся сообщения повышают приоритет только если ответа ожидает клиент
            if (!$isUrgent && $needsAttention) {
                if ($unreadCount >= 3 || $timeElapsedSeconds >= 600) {
                    $isUrgent = true;
                }
            }

            $priority = $isUrgent ? 'URGENT' : 'NORMAL';

            // Привязки CRM из диалога (проверяем chat.entity_data_* и верхнеуровневые entity_data_*)
            $entityData1 = (string)($item['chat']['entity_data_1'] ?? ($item['entity_data_1'] ?? ($item['ENTITY_DATA_1'] ?? '')));
            $entityData2 = (string)($item['chat']['entity_data_2'] ?? ($item['entity_data_2'] ?? ($item['ENTITY_DATA_2'] ?? '')));
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
            'SYSTEM_EVENT'      => '⚙️ [ОТКРЫТАЯ ЛИНИЯ: СИСТЕМНОЕ СОБЫТИЕ]',
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
            $meta['last_speaker'] === 'CLIENT'
                ? "[CLIENT: {$meta['last_message']['author']} (ID: {$meta['last_message']['author_id']})]"
                : "[LAST_SPEAKER_NAME: {$meta['last_message']['author']} (ID: {$meta['last_message']['author_id']}, ROLE: {$meta['last_speaker']})]",
            "[CRM_BINDINGS: " . json_encode($meta['crm_bindings'], JSON_UNESCAPED_UNICODE) . "]",
        ];

        if (!empty($meta['matched_keyword'])) {
            $lines[] = "[URGENT_TRIGGER_KEYWORD: '{$meta['matched_keyword']}']";
        }

        $lines[] = "[ДЕЙСТВИЯ В ЧАТЕ]: Для ответа используй bitrix24__b24_chat_action (action=\"reply\", dialog_id=\"{$meta['dialog_id']}\", message=...).";
        $lines[] = '[ПОСТАНОВКА ЗАДАЧ]: Инструмент bitrix24__b24_create_task ДОСТУПЕН — работа НЕ ограничена режимом «только чтение», создавать задачи разрешено.';
        $lines[] = 'Регламент создания задачи (6 пунктов):';
        $lines[] = '  1) Название: "<Группа проектов> | <Смысл задачи> <DD.MM.YYYY>" (или project_group_name + task_summary);';
        $lines[] = '  2) Описание: обязателен краткий план действий (plan_steps) и/или ссылки (links);';
        $lines[] = '  3) Ответственный (responsible_id, обязателен) + accomplices/auditors;';
        $lines[] = '  4) Дедлайн (deadline, ISO 8601 или YYYY-MM-DD HH:MM:SS; без дедлайна только is_manual=true);';
        $lines[] = '  5) Проектная группа (group_id, обязательна);';
        $lines[] = '  6) Учёт времени (time_estimate_hours / time_estimate_minutes).';
        $lines[] = 'Если group_id неизвестен — найди через bitrix24__b24_get_workgroups или передай project_group_name.';

        $lines[] = "---";
        $lines[] = "[ПОСЛЕДНЕЕ СООБЩЕНИЕ ДИАЛОГА]:";
        $lines[] = $meta['last_message']['text'] !== '' ? $meta['last_message']['text'] : '[Вложение/системное событие]';
        $lines[] = "---";
        $lines[] = '[ИНСТРУКЦИЯ АГЕНТУ]: Ответь клиенту в этом чате через инструмент bitrix24__b24_chat_action ';
        $lines[] = "(action=\"reply\", dialog_id=\"{$meta['dialog_id']}\"). Сначала при необходимости прочитай контекст через " .
                   'bitrix24__b24_get_chat_history и досье через bitrix24__b24_get_client_dossier. ' .
                   'Не отвечай в другие каналы, не дублируй уже отправленные ответы.';

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
            'sessionMode'   => 'persistent',
            'agentId'       => 'main',
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
            'deliver'       => false,
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
