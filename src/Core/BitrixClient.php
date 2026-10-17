<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Core;

/**
 * Обертка над методами CRest::call и CRest::callBatch с нормализацией ответов и ошибок.
 */
class BitrixClient
{
    /**
     * Вызов единичного метода Bitrix24 REST API.
     *
     * @param string $method Имя метода (например, 'crm.lead.get')
     * @param array $params Параметры запроса
     * @return array Нормализованный массив с ключами 'success', 'result', 'error', 'total', 'next'
     */
    public function call(string $method, array $params = []): array
    {
        if (!class_exists('\CRest')) {
            Logger::error('CRest class is not loaded', ['method' => $method], 'bitrix_api');
            return [
                'success'           => false,
                'error'             => 'crest_not_loaded',
                'error_description' => 'Класс CRest не найден. Проверьте установку библиотеки crest.php.',
                'result'            => null,
            ];
        }

        try {
            $rawResponse = \CRest::call($method, $params);
            return $this->normalizeResponse($method, $params, $rawResponse);
        } catch (\Throwable $e) {
            Logger::error("Bitrix API Exception in {$method}: " . $e->getMessage(), [
                'method' => $method,
                'params' => $params,
                'trace'  => $e->getTraceAsString(),
            ], 'bitrix_api');

            return [
                'success'           => false,
                'error'             => 'exception',
                'error_description' => $e->getMessage(),
                'result'            => null,
            ];
        }
    }

    /**
     * Вызов пакетного метода Bitrix24 REST API (CRest::callBatch).
     *
     * @param array $batch Ассоциативный массив запросов вида ['cmd_name' => 'method?param=value' или ['method', [params]]]
     * @param int $halt Прерывать ли выполнение пакета при ошибке (0 или 1)
     * @return array Нормализованный ответ батча
     */
    public function callBatch(array $batch, int $halt = 0): array
    {
        if (!class_exists('\CRest')) {
            Logger::error('CRest class is not loaded for batch', [], 'bitrix_api');
            return [
                'success'           => false,
                'error'             => 'crest_not_loaded',
                'error_description' => 'Класс CRest не найден при пакетном запросе.',
                'result'            => null,
            ];
        }

        try {
            $rawResponse = \CRest::callBatch($batch, $halt);

            if (!is_array($rawResponse)) {
                Logger::error('Invalid batch response format from Bitrix24', ['raw' => $rawResponse], 'bitrix_api');
                return [
                    'success'           => false,
                    'error'             => 'invalid_response',
                    'error_description' => 'Некорректный формат ответа пакетного запроса от Битрикс24.',
                    'result'            => null,
                ];
            }

            if (!empty($rawResponse['error'])) {
                $errorDesc = $rawResponse['error_description'] 
                    ?? $rawResponse['error_information'] 
                    ?? (is_string($rawResponse['error']) ? $rawResponse['error'] : 'Ошибка API Битрикс24');

                Logger::warning('Batch request completed with error', [
                    'error'             => $rawResponse['error'],
                    'error_description' => $errorDesc,
                ], 'bitrix_api');

                return [
                    'success'           => false,
                    'error'             => $rawResponse['error'],
                    'error_description' => $errorDesc,
                    'result'            => $rawResponse['result']['result'] ?? null,
                    'result_error'      => $rawResponse['result']['result_error'] ?? null,
                    'raw'               => $rawResponse,
                ];
            }

            // Успешный батч
            $batchResult = $rawResponse['result']['result'] ?? ($rawResponse['result'] ?? []);
            $batchErrors = $rawResponse['result']['result_error'] ?? [];

            return [
                'success'      => empty($batchErrors),
                'result'       => $batchResult,
                'result_error' => $batchErrors,
                'raw'          => $rawResponse,
            ];
        } catch (\Throwable $e) {
            Logger::error('Bitrix API Batch Exception: ' . $e->getMessage(), [
                'batch_keys' => array_keys($batch),
                'trace'      => $e->getTraceAsString(),
            ], 'bitrix_api');

            return [
                'success'           => false,
                'error'             => 'exception',
                'error_description' => $e->getMessage(),
                'result'            => null,
            ];
        }
    }

    /**
     * Нормализация ответа API Битрикс24.
     */
    private function normalizeResponse(string $method, array $params, mixed $raw): array
    {
        if (!is_array($raw)) {
            Logger::error("Non-array response from Bitrix24 for {$method}", ['raw' => $raw], 'bitrix_api');
            return [
                'success'           => false,
                'error'             => 'invalid_response',
                'error_description' => 'Битрикс24 вернул некорректный ответ (не JSON/array).',
                'result'            => null,
            ];
        }

        if (!empty($raw['error'])) {
            $errorDesc = $raw['error_description'] 
                ?? $raw['error_information'] 
                ?? (is_string($raw['error']) ? $raw['error'] : 'Ошибка API Битрикс24');

            Logger::warning("Bitrix API error in {$method}: {$errorDesc}", [
                'method' => $method,
                'params' => $params,
                'error'  => $raw['error'],
            ], 'bitrix_api');

            return [
                'success'           => false,
                'error'             => $raw['error'],
                'error_description' => $errorDesc,
                'result'            => null,
                'raw'               => $raw,
            ];
        }

        return [
            'success'           => true,
            'result'            => $raw['result'] ?? null,
            'total'             => $raw['total'] ?? null,
            'next'              => $raw['next'] ?? null,
            'time'              => $raw['time'] ?? null,
            'error'             => null,
            'error_description' => null,
            'raw'               => $raw,
        ];
    }
}
