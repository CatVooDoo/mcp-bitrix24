<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Mcp;

use Bitrix24Mcp\Core\Logger;

/**
 * Обработчик протокола JSON-RPC 2.0 для Model Context Protocol (MCP).
 * Поддерживает методы initialize, ping, tools/list, tools/call.
 */
class McpRouter
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME      = 'bitrix24-mcp';
    private const SERVER_VERSION   = '1.0.0';

    public function __construct(
        private readonly ChatAgentTools $chatTools = new ChatAgentTools(),
        private readonly PmAgentTools $pmTools = new PmAgentTools()
    ) {}

    /**
     * Обработка входящего JSON-RPC запроса.
     *
     * @param string $rawInput Сырая строка запроса
     * @return string|null JSON-RPC ответ или null (для уведомлений notifications/*)
     */
    public function handle(string $rawInput): ?string
    {
        $trimmed = trim($rawInput);
        if ($trimmed === '') {
            return null;
        }

        $request = json_decode($trimmed, true);
        if (!is_array($request)) {
            return $this->formatErrorResponse(null, -32700, 'Parse error: некорректный JSON');
        }

        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request) || $id === null;
        $method = (string)($request['method'] ?? '');
        $params = (array)($request['params'] ?? []);

        Logger::debug("MCP received method: {$method}", ['id' => $id, 'params' => $params], 'mcp');

        try {
            $result = match ($method) {
                'initialize'                => $this->handleInitialize($params),
                'notifications/initialized' => null, // Уведомление, ответ не требуется
                'ping'                      => new \stdClass(),
                'tools/list'                => $this->handleToolsList($params),
                'tools/call'                => $this->handleToolsCall($params),
                default                     => throw new \InvalidArgumentException("Метод не найден: {$method}", -32601),
            };

            if ($isNotification || $result === null) {
                return null;
            }

            return $this->formatSuccessResponse($id, $result);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() !== 0 ? $e->getCode() : -32602;
            return $this->formatErrorResponse($id, $code, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('Internal error in MCP handler: ' . $e->getMessage(), [
                'method' => $method,
                'id'     => $id,
                'trace'  => $e->getTraceAsString(),
            ], 'mcp');

            return $this->formatErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Обработка handshake initialize.
     */
    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    /**
     * Список доступных инструментов tools/list.
     */
    private function handleToolsList(array $params): array
    {
        $tools = $this->chatTools->getDefinitions();

        // Если включен флаг Фазы 2, подмешиваем инструменты ПМ-агента (без дубликатов по имени)
        if ($this->pmTools->isEnabled()) {
            $existingNames = array_column($tools, 'name');
            foreach ($this->pmTools->getDefinitions() as $pmTool) {
                if (!in_array($pmTool['name'], $existingNames, true)) {
                    $tools[] = $pmTool;
                }
            }
        }

        return [
            'tools' => $tools,
        ];
    }

    /**
     * Вызов инструмента tools/call.
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = (string)($params['name'] ?? '');
        $arguments = (array)($params['arguments'] ?? []);

        if ($toolName === '') {
            throw new \InvalidArgumentException('Параметр name обязателен для tools/call', -32602);
        }

        // 1. Поиск инструмента в ChatAgentTools (Фаза 1)
        $chatToolDefinitions = array_column($this->chatTools->getDefinitions(), 'name');
        if (in_array($toolName, $chatToolDefinitions, true)) {
            $toolResult = $this->chatTools->execute($toolName, $arguments);
            return $this->formatToolCallResult($toolResult);
        }

        // 2. Поиск инструмента в PmAgentTools (Фаза 2)
        if ($this->pmTools->isEnabled()) {
            $pmToolDefinitions = array_column($this->pmTools->getDefinitions(), 'name');
            if (in_array($toolName, $pmToolDefinitions, true)) {
                $toolResult = $this->pmTools->execute($toolName, $arguments);
                return $this->formatToolCallResult($toolResult);
            }
        }

        throw new \InvalidArgumentException("Неизвестный инструмент: {$toolName}", -32601);
    }

    /**
     * Форматирование ответа вызова инструмента по спецификации MCP.
     */
    private function formatToolCallResult(array $toolResult): array
    {
        $isError = !empty($toolResult['error']) || (isset($toolResult['success']) && $toolResult['success'] === false);

        $jsonText = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($jsonText === false) {
            $jsonText = '{"error": "Failed to serialize tool response"}';
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $jsonText,
                ],
            ],
            'isError' => $isError,
        ];
    }

    private function formatSuccessResponse(mixed $id, mixed $result): string
    {
        $response = [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ];

        return (string)json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function formatErrorResponse(mixed $id, int $code, string $message): string
    {
        $response = [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        return (string)json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
