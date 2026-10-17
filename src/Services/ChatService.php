<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Сервис для работы с чатами, открытыми линиями и ботами Битрикс24.
 * Разрешены операции отправки сообщений, индикатора набора текста и перевода на оператора.
 */
class ChatService
{
    public function __construct(
        private readonly BitrixClient $client = new BitrixClient()
    ) {}

    /**
     * Чтение истории сообщений чата или открытой линии (im.dialog.messages.get).
     *
     * @param string|int $dialogId Идентификатор диалога (например, 'chat123' или 123)
     * @param int $limit Максимальное количество сообщений (по умолчанию 30)
     * @return array Массив сообщений и метаданных пользователей/файлов
     */
    public function getHistory(string|int $dialogId, int $limit = 30): array
    {
        $normalizedDialogId = $this->normalizeDialogId($dialogId);

        $response = $this->client->call('im.dialog.messages.get', [
            'DIALOG_ID' => $normalizedDialogId,
            'LIMIT'     => $limit,
        ]);

        if (!$response['success']) {
            return [
                'success'  => false,
                'error'    => $response['error'] ?? 'api_error',
                'message'  => $response['error_description'] ?? "Не удалось получить историю диалога {$normalizedDialogId}",
                'messages' => [],
            ];
        }

        $res = $response['result'] ?? [];

        return [
            'success'  => true,
            'dialogId' => $normalizedDialogId,
            'messages' => $res['messages'] ?? [],
            'users'    => $res['users'] ?? [],
            'files'    => $res['files'] ?? [],
        ];
    }

    /**
     * Отправка ответа в чат (imbot.message.add или im.message.add).
     *
     * @param string|int $dialogId Идентификатор диалога
     * @param string $message Текст ответа
     * @param array $options Дополнительные параметры (SYSTEM, ATTACH, KEYBOARD, etc.)
     * @return array Результат отправки
     */
    public function sendReply(string|int $dialogId, string $message, array $options = []): array
    {
        $normalizedDialogId = $this->normalizeDialogId($dialogId);
        $botId = defined('B24_BOT_ID') ? (int)B24_BOT_ID : 0;

        if ($botId > 0) {
            $params = array_merge([
                'BOT_ID'    => $botId,
                'DIALOG_ID' => $normalizedDialogId,
                'MESSAGE'   => $message,
            ], $options);

            if (defined('B24_BOT_CLIENT_ID') && (string)B24_BOT_CLIENT_ID !== '') {
                $params['CLIENT_ID'] = (string)B24_BOT_CLIENT_ID;
            }

            $response = $this->client->call('imbot.message.add', $params);
        } else {
            $params = array_merge([
                'DIALOG_ID' => $normalizedDialogId,
                'MESSAGE'   => $message,
            ], $options);

            $response = $this->client->call('im.message.add', $params);
        }

        if (!$response['success']) {
            Logger::error("Failed to send reply to {$normalizedDialogId}", [
                'error' => $response['error_description'] ?? $response['error'],
            ], 'chat');

            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка при отправке сообщения в чат',
            ];
        }

        Logger::info("Message sent to {$normalizedDialogId}", [
            'message_id' => $response['result'] ?? null,
        ], 'chat');

        return [
            'success'    => true,
            'message_id' => $response['result'] ?? null,
            'dialogId'   => $normalizedDialogId,
        ];
    }

    /**
     * Отправка индикатора набора текста (typing).
     */
    public function sendTyping(string|int $dialogId): array
    {
        $normalizedDialogId = $this->normalizeDialogId($dialogId);
        $botId = defined('B24_BOT_ID') ? (int)B24_BOT_ID : 0;

        if ($botId > 0) {
            $response = $this->client->call('imbot.chat.sendTyping', [
                'BOT_ID'    => $botId,
                'DIALOG_ID' => $normalizedDialogId,
            ]);
        } else {
            $response = $this->client->call('im.chat.sendTyping', [
                'DIALOG_ID' => $normalizedDialogId,
            ]);
        }

        return [
            'success' => $response['success'],
            'result'  => $response['result'] ?? null,
        ];
    }

    /**
     * Перевод диалога Открытой линии на живого оператора.
     * Опционально отправляет системную сводку оператору.
     *
     * @param string|int $dialogId Идентификатор диалога или чата
     * @param string $summaryNote Внутренняя сводка диалога для оператора
     * @return array Результат перевода
     */
    public function transferToOperator(string|int $dialogId, string $summaryNote = ''): array
    {
        $normalizedDialogId = $this->normalizeDialogId($dialogId);
        $chatId = $this->extractChatId($normalizedDialogId);

        // 1. Если передана сводка, публикуем внутреннюю системную заметку для оператора
        if ($summaryNote !== '') {
            $this->sendReply($normalizedDialogId, "🤖 [Сводка ИИ для оператора]:\n" . $summaryNote, [
                'SYSTEM' => 'Y',
            ]);
        }

        // 2. Вызов перевода диалога на оператора
        $botId = defined('B24_BOT_ID') ? (int)B24_BOT_ID : 0;
        $params = ['CHAT_ID' => $chatId];
        if ($botId > 0) {
            $params['BOT_ID'] = $botId;
        }

        $response = $this->client->call('imopenlines.bot.session.operator', $params);

        if (!$response['success']) {
            // Попытка альтернативного метода imopenlines.bot.session.transfer
            $fallback = $this->client->call('imopenlines.bot.session.transfer', $params);
            if (!$fallback['success']) {
                Logger::error("Failed to transfer chat {$chatId} to operator", [
                    'primary_error'  => $response['error_description'] ?? $response['error'],
                    'fallback_error' => $fallback['error_description'] ?? $fallback['error'],
                ], 'chat');

                return [
                    'success' => false,
                    'error'   => $response['error'] ?? 'transfer_failed',
                    'message' => $response['error_description'] ?? 'Не удалось перевести диалог на оператора',
                ];
            }

            $response = $fallback;
        }

        Logger::info("Chat {$chatId} successfully transferred to operator", [], 'chat');

        return [
            'success' => true,
            'chatId'  => $chatId,
            'message' => 'Диалог успешно переведен на оператора',
        ];
    }

    /**
     * Нормализация dialogId к формату Bitrix24 (например, 'chat123').
     */
    public function normalizeDialogId(string|int $dialogId): string
    {
        $strId = trim((string)$dialogId);

        if (is_numeric($strId)) {
            return 'chat' . $strId;
        }

        return $strId;
    }

    /**
     * Извлечение числового ID чата из строки вида 'chat123'.
     */
    public function extractChatId(string|int $dialogId): int
    {
        $strId = trim((string)$dialogId);
        if (preg_match('/^chat(\d+)$/i', $strId, $matches)) {
            return (int)$matches[1];
        }

        return (int)$strId;
    }
}
