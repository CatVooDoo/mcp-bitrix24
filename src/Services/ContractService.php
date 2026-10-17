<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Сервис для чтения смарт-процесса «ДОГОВОРЫ» (crm.type.list, crm.item.list, crm.item.get).
 * Использует camelCase параметры и возвращает camelCase поля.
 */
class ContractService
{
    private const CACHE_FILE_NAME = 'contracts_entity_type.json';

    public function __construct(
        private readonly BitrixClient $client = new BitrixClient()
    ) {}

    /**
     * Определение entityTypeId смарт-процесса "ДОГОВОРЫ".
     * Если константа B24_CONTRACTS_ENTITY_TYPE_ID === 0, производит автопоиск через crm.type.list
     * с кешированием в logs/cache/contracts_entity_type.json.
     */
    public function getEntityTypeId(): int
    {
        if (defined('B24_CONTRACTS_ENTITY_TYPE_ID') && (int)B24_CONTRACTS_ENTITY_TYPE_ID > 0) {
            return (int)B24_CONTRACTS_ENTITY_TYPE_ID;
        }

        $cacheDir = dirname(__DIR__, 2) . '/logs/cache';
        $cacheFile = $cacheDir . '/' . self::CACHE_FILE_NAME;

        if (file_exists($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['entityTypeId'])) {
                return (int)$cached['entityTypeId'];
            }
        }

        // Автопоиск смарт-процесса через crm.type.list
        $response = $this->client->call('crm.type.list', []);
        if (!$response['success']) {
            Logger::error('Failed to fetch crm.type.list for contracts discovery', [
                'error' => $response['error_description'] ?? $response['error'],
            ], 'contracts');
            return 0;
        }

        $types = $response['result']['types'] ?? [];
        $foundTypeId = 0;
        $foundTitle = '';

        foreach ($types as $type) {
            $title = (string)($type['title'] ?? '');
            if (mb_stripos($title, 'договор') !== false) {
                $foundTypeId = (int)($type['entityTypeId'] ?? 0);
                $foundTitle = $title;
                break;
            }
        }

        if ($foundTypeId > 0) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }

            @file_put_contents($cacheFile, json_encode([
                'entityTypeId' => $foundTypeId,
                'title'        => $foundTitle,
                'cached_at'    => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

            Logger::info("Auto-discovered contracts entityTypeId: {$foundTypeId} ({$foundTitle})", [], 'contracts');
            return $foundTypeId;
        }

        Logger::warning('Smart process for "ДОГОВОРЫ" not found via crm.type.list', [], 'contracts');
        return 0;
    }

    /**
     * Поиск и получение списка договоров (crm.item.list).
     *
     * @param array $filter Фильтры (поддерживает snake_case и camelCase)
     * @param int $limit Ограничение выборки (по умолчанию 20)
     * @param array $order Сортировка (например, ['id' => 'DESC'])
     * @return array Массив найденных договоров и метаданные
     */
    public function getContracts(array $filter = [], int $limit = 20, array $order = ['id' => 'DESC']): array
    {
        $entityTypeId = $this->getEntityTypeId();
        if ($entityTypeId <= 0) {
            return [
                'success' => false,
                'error'   => 'entity_type_not_found',
                'message' => 'Не удалось определить entityTypeId для смарт-процесса "ДОГОВОРЫ". Укажите B24_CONTRACTS_ENTITY_TYPE_ID в settings.php.',
                'items'   => [],
            ];
        }

        $camelFilter = $this->normalizeFilter($filter);

        $params = [
            'entityTypeId' => $entityTypeId,
            'filter'       => $camelFilter,
            'select'       => [
                'id',
                'title',
                'stageId',
                'categoryId',
                'companyId',
                'contactId',
                'opportunity',
                'currencyId',
                'begindate',
                'closedate',
                'assignedById',
                'createdTime',
                'updatedTime',
                'ufCrm*',
            ],
            'order'        => $order,
        ];

        $response = $this->client->call('crm.item.list', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка получения договоров',
                'items'   => [],
            ];
        }

        $items = $response['result']['items'] ?? [];
        if ($limit > 0 && count($items) > $limit) {
            $items = array_slice($items, 0, $limit);
        }

        return [
            'success'      => true,
            'entityTypeId' => $entityTypeId,
            'items'        => $items,
            'total'        => $response['total'] ?? count($items),
        ];
    }

    /**
     * Получение детальной информации по конкретному договору (crm.item.get).
     */
    public function getContract(int $contractId): array
    {
        $entityTypeId = $this->getEntityTypeId();
        if ($entityTypeId <= 0) {
            return [
                'success' => false,
                'error'   => 'entity_type_not_found',
                'message' => 'Не удалось определить entityTypeId для смарт-процесса "ДОГОВОРЫ".',
                'item'    => null,
            ];
        }

        $response = $this->client->call('crm.item.get', [
            'entityTypeId' => $entityTypeId,
            'id'           => $contractId,
        ]);

        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Ошибка получения договора #{$contractId}",
                'item'    => null,
            ];
        }

        return [
            'success'      => true,
            'entityTypeId' => $entityTypeId,
            'item'         => $response['result']['item'] ?? null,
        ];
    }

    /**
     * Приведение фильтра к обязательному для crm.item.list camelCase виду.
     */
    private function normalizeFilter(array $filter): array
    {
        $normalized = [];

        foreach ($filter as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $mappedKey = match (strtolower((string)$key)) {
                'id', 'contract_id', 'contractid' => 'id',
                'company_id', 'companyid'         => 'companyId',
                'contact_id', 'contactid'         => 'contactId',
                'assigned_by_id', 'assignedbyid' => 'assignedById',
                'stage_id', 'stageid'             => 'stageId',
                'category_id', 'categoryid'       => 'categoryId',
                'title', 'search_query', 'query'  => '%title',
                '%title'                          => '%title',
                default                           => $key,
            };

            $normalized[$mappedKey] = $value;
        }

        return $normalized;
    }
}
