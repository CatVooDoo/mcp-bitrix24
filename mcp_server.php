<?php

declare(strict_types=1);

/**
 * Двухрежимный MCP-сервер (Model Context Protocol) для Битрикс24.
 * Поддерживает:
 * 1. CLI STDIO Mode (php mcp_server.php) — строчный JSON-RPC 2.0 через STDIN/STDOUT.
 * 2. HTTP POST Mode (/mcp_server.php) — HTTP JSON-RPC 2.0 с Bearer-авторизацией.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Mcp\McpRouter;

// Инициализация окружения, подавление display_errors и регистрация автозагрузки
Bootstrap::init();

$router = new McpRouter();

// ----------------------------------------------------
// РЕЖИМ 1: CLI STDIO (для локального вызова OpenClaw)
// ----------------------------------------------------
if (php_sapi_name() === 'cli') {
    Logger::info('Starting MCP Server in CLI STDIO mode', [], 'mcp');

    $stdin = fopen('php://stdin', 'r');
    if ($stdin === false) {
        Logger::error('Failed to open php://stdin', [], 'mcp');
        exit(1);
    }

    while (!feof($stdin)) {
        $line = fgets($stdin);
        if ($line === false) {
            break;
        }

        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $response = $router->handle($trimmed);
        if ($response !== null) {
            // Вывод ответа строго в STDOUT со сбросом буфера
            fwrite(STDOUT, $response . "\n");
            fflush(STDOUT);
        }
    }

    fclose($stdin);
    Logger::info('MCP Server STDIO session ended', [], 'mcp');
    exit(0);
}

// ----------------------------------------------------
// РЕЖИМ 2: HTTP POST (для удаленного вызова OpenClaw)
// ----------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

// Базовая проверка метода HTTP
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status'  => 'active',
        'server'  => 'bitrix24-mcp',
        'version' => '1.0.0',
        'message' => 'MCP Server is running. Send POST requests with JSON-RPC 2.0 payload.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error'   => ['code' => -32600, 'message' => 'Method Not Allowed. Only POST is accepted.'],
    ]);
    exit;
}

// Проверка токена авторизации (если задан MCP_HTTP_AUTH_TOKEN)
$requiredToken = defined('MCP_HTTP_AUTH_TOKEN') ? (string)MCP_HTTP_AUTH_TOKEN : '';
if ($requiredToken !== '') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode([
            'jsonrpc' => '2.0',
            'error'   => ['code' => -32000, 'message' => 'Missing or invalid Authorization Bearer header.'],
        ]);
        exit;
    }

    $providedToken = trim(substr($authHeader, 7));
    if (!hash_equals($requiredToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'jsonrpc' => '2.0',
            'error'   => ['code' => -32000, 'message' => 'Forbidden: Invalid MCP HTTP Auth Token.'],
        ]);
        exit;
    }
}

// Чтение и обработка сырого тела запроса
$rawInput = (string)file_get_contents('php://input');
if ($rawInput === '') {
    http_response_code(400);
    echo json_encode([
        'jsonrpc' => '2.0',
        'error'   => ['code' => -32700, 'message' => 'Empty request body.'],
    ]);
    exit;
}

$response = $router->handle($rawInput);
if ($response === null) {
    // В случае уведомлений возвращается пустой 204 No Content
    http_response_code(204);
    exit;
}

echo $response;
