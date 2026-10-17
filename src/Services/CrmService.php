<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Сервис для чтения сущностей CRM (Лиды, Сделки, Контакты, Компании)
 * и формирования единого Досье клиента через пакетный запрос CRest::callBatch.
 * Строгий Read-Only режим для Фазы 1.
 */
class CrmService
{
    public function __construct(
        private readonly BitrixClient $client = new BitrixClient(),
        private readonly ContractService $contractService = new ContractService()
    ) {}

    // ==========================================
    // ЛИДЫ (Leads)
    // ==========================================

    /**
     * Получение информации по конкретному лиду (crm.lead.get).
     */
    public function getLead(int $leadId): array
    {
        $response = $this->client->call('crm.lead.get', ['ID' => $leadId]);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Не удалось получить лид #{$leadId}",
                'lead'    => null,
            ];
        }

        return [
            'success' => true,
            'lead'    => $response['result'] ?? null,
        ];
    }

    /**
     * Поиск и фильтрация лидов (crm.lead.list).
     */
    public function getLeads(array $filter = [], int $limit = 20, array $order = ['ID' => 'DESC']): array
    {
        $b24Filter = [];
        if (!empty($filter['id'])) {
            $b24Filter['ID'] = (int)$filter['id'];
        }
        if (!empty($filter['status_id'])) {
            $b24Filter['STATUS_ID'] = $filter['status_id'];
        }
        if (!empty($filter['company_id'])) {
            $b24Filter['COMPANY_ID'] = (int)$filter['company_id'];
        }
        if (!empty($filter['contact_id'])) {
            $b24Filter['CONTACT_ID'] = (int)$filter['contact_id'];
        }
        if (!empty($filter['assigned_by_id'])) {
            $b24Filter['ASSIGNED_BY_ID'] = (int)$filter['assigned_by_id'];
        }
        if (!empty($filter['search_query'])) {
            $b24Filter['%TITLE'] = $filter['search_query'];
        }

        $params = [
            'filter' => $b24Filter,
            'select' => [
                'ID',
                'TITLE',
                'NAME',
                'LAST_NAME',
                'STATUS_ID',
                'COMPANY_ID',
                'CONTACT_ID',
                'ASSIGNED_BY_ID',
                'OPPORTUNITY',
                'CURRENCY_ID',
                'DATE_CREATE',
                'PHONE',
                'EMAIL',
                'UF_*',
            ],
            'order'  => $order,
        ];

        $response = $this->client->call('crm.lead.list', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка получения списка лидов',
                'leads'   => [],
            ];
        }

        $leads = $response['result'] ?? [];
        if ($limit > 0 && count($leads) > $limit) {
            $leads = array_slice($leads, 0, $limit);
        }

        return [
            'success' => true,
            'leads'   => $leads,
            'total'   => $response['total'] ?? count($leads),
        ];
    }

    // ==========================================
    // СДЕЛКИ (Deals)
    // ==========================================

    /**
     * Получение информации по сделке (crm.deal.get).
     */
    public function getDeal(int $dealId): array
    {
        $response = $this->client->call('crm.deal.get', ['ID' => $dealId]);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Не удалось получить сделку #{$dealId}",
                'deal'    => null,
            ];
        }

        return [
            'success' => true,
            'deal'    => $response['result'] ?? null,
        ];
    }

    /**
     * Поиск и фильтрация сделок (crm.deal.list).
     */
    public function getDeals(array $filter = [], int $limit = 20, array $order = ['DATE_CREATE' => 'DESC']): array
    {
        $b24Filter = [];
        if (!empty($filter['id'])) {
            $b24Filter['ID'] = (int)$filter['id'];
        }
        if (!empty($filter['company_id'])) {
            $b24Filter['COMPANY_ID'] = (int)$filter['company_id'];
        }
        if (!empty($filter['contact_id'])) {
            $b24Filter['CONTACT_ID'] = (int)$filter['contact_id'];
        }
        if (!empty($filter['stage_id'])) {
            $b24Filter['STAGE_ID'] = $filter['stage_id'];
        }
        if (!empty($filter['category_id'])) {
            $b24Filter['CATEGORY_ID'] = (int)$filter['category_id'];
        }
        if (!empty($filter['assigned_by_id'])) {
            $b24Filter['ASSIGNED_BY_ID'] = (int)$filter['assigned_by_id'];
        }
        if (!empty($filter['search_query'])) {
            $b24Filter['%TITLE'] = $filter['search_query'];
        }

        $params = [
            'filter' => $b24Filter,
            'select' => [
                'ID',
                'TITLE',
                'STAGE_ID',
                'CATEGORY_ID',
                'COMPANY_ID',
                'CONTACT_ID',
                'ASSIGNED_BY_ID',
                'OPPORTUNITY',
                'CURRENCY_ID',
                'BEGINDATE',
                'CLOSEDATE',
                'UF_*',
            ],
            'order'  => $order,
        ];

        $response = $this->client->call('crm.deal.list', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка получения списка сделок',
                'deals'   => [],
            ];
        }

        $deals = $response['result'] ?? [];
        if ($limit > 0 && count($deals) > $limit) {
            $deals = array_slice($deals, 0, $limit);
        }

        return [
            'success' => true,
            'deals'   => $deals,
            'total'   => $response['total'] ?? count($deals),
        ];
    }

    // ==========================================
    // КОНТАКТЫ (Contacts)
    // ==========================================

    /**
     * Получение информации по контакту (crm.contact.get).
     */
    public function getContact(int $contactId): array
    {
        $response = $this->client->call('crm.contact.get', ['ID' => $contactId]);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Не удалось получить контакт #{$contactId}",
                'contact' => null,
            ];
        }

        return [
            'success' => true,
            'contact' => $response['result'] ?? null,
        ];
    }

    /**
     * Поиск и фильтрация контактов (crm.contact.list).
     * Поддерживает поиск по Имени (NAME), Фамилии (LAST_NAME) и полному ФИО.
     */
    public function getContacts(array $filter = [], int $limit = 20, array $order = ['ID' => 'DESC']): array
    {
        $baseFilter = [];
        if (!empty($filter['id'])) {
            $baseFilter['ID'] = (int)$filter['id'];
        }
        if (!empty($filter['company_id'])) {
            $baseFilter['COMPANY_ID'] = (int)$filter['company_id'];
        }
        if (!empty($filter['assigned_by_id'])) {
            $baseFilter['ASSIGNED_BY_ID'] = (int)$filter['assigned_by_id'];
        }

        $selectFields = [
            'ID',
            'NAME',
            'SECOND_NAME',
            'LAST_NAME',
            'POST',
            'COMPANY_ID',
            'ASSIGNED_BY_ID',
            'PHONE',
            'EMAIL',
            'UF_*',
        ];

        $searchQuery = trim((string)($filter['search_query'] ?? ''));

        // Если задан текстовый поиск, ищем пакетно по LAST_NAME и NAME (т.к. в Б24 нет поля TITLE у контактов)
        if ($searchQuery !== '') {
            $parts = preg_split('/\s+/u', $searchQuery, -1, PREG_SPLIT_NO_EMPTY);
            $batch = [
                'by_last_name' => [
                    'method' => 'crm.contact.list',
                    'params' => [
                        'filter' => array_merge($baseFilter, ['%LAST_NAME' => $searchQuery]),
                        'select' => $selectFields,
                        'order'  => $order,
                    ],
                ],
                'by_name' => [
                    'method' => 'crm.contact.list',
                    'params' => [
                        'filter' => array_merge($baseFilter, ['%NAME' => $searchQuery]),
                        'select' => $selectFields,
                        'order'  => $order,
                    ],
                ],
            ];

            if (is_array($parts) && count($parts) >= 2) {
                $batch['by_full_1'] = [
                    'method' => 'crm.contact.list',
                    'params' => [
                        'filter' => array_merge($baseFilter, ['%LAST_NAME' => $parts[0], '%NAME' => $parts[1]]),
                        'select' => $selectFields,
                        'order'  => $order,
                    ],
                ];
                $batch['by_full_2'] = [
                    'method' => 'crm.contact.list',
                    'params' => [
                        'filter' => array_merge($baseFilter, ['%NAME' => $parts[0], '%LAST_NAME' => $parts[1]]),
                        'select' => $selectFields,
                        'order'  => $order,
                    ],
                ];
            }

            $batchRes = $this->client->callBatch($batch);
            $merged = [];
            foreach (($batchRes['result'] ?? []) as $list) {
                if (is_array($list)) {
                    foreach ($list as $contact) {
                        if (is_array($contact) && isset($contact['ID'])) {
                            $merged[(int)$contact['ID']] = $contact;
                        }
                    }
                }
            }

            $contacts = array_values($merged);
            if ($limit > 0 && count($contacts) > $limit) {
                $contacts = array_slice($contacts, 0, $limit);
            }

            return [
                'success'  => true,
                'contacts' => $contacts,
                'total'    => count($contacts),
            ];
        }

        $params = [
            'filter' => $baseFilter,
            'select' => $selectFields,
            'order'  => $order,
        ];

        $response = $this->client->call('crm.contact.list', $params);
        if (!$response['success']) {
            return [
                'success'  => false,
                'error'    => $response['error'] ?? 'api_error',
                'message'  => $response['error_description'] ?? 'Ошибка получения списка контактов',
                'contacts' => [],
            ];
        }

        $contacts = $response['result'] ?? [];
        if ($limit > 0 && count($contacts) > $limit) {
            $contacts = array_slice($contacts, 0, $limit);
        }

        return [
            'success'  => true,
            'contacts' => $contacts,
            'total'    => $response['total'] ?? count($contacts),
        ];
    }

    // ==========================================
    // КОМПАНИИ (Companies)
    // ==========================================

    /**
     * Получение информации по компании (crm.company.get) включая пользовательские UF_* поля.
     */
    public function getCompany(int $companyId): array
    {
        $response = $this->client->call('crm.company.get', ['ID' => $companyId]);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Не удалось получить компанию #{$companyId}",
                'company' => null,
            ];
        }

        return [
            'success' => true,
            'company' => $response['result'] ?? null,
        ];
    }

    /**
     * Поиск и фильтрация компаний (crm.company.list).
     */
    public function getCompanies(array $filter = [], int $limit = 20, array $order = ['ID' => 'DESC']): array
    {
        $b24Filter = [];
        if (!empty($filter['id'])) {
            $b24Filter['ID'] = (int)$filter['id'];
        }
        if (!empty($filter['company_type'])) {
            $b24Filter['COMPANY_TYPE'] = $filter['company_type'];
        }
        if (!empty($filter['industry'])) {
            $b24Filter['INDUSTRY'] = $filter['industry'];
        }
        if (!empty($filter['assigned_by_id'])) {
            $b24Filter['ASSIGNED_BY_ID'] = (int)$filter['assigned_by_id'];
        }
        if (!empty($filter['search_query'])) {
            $b24Filter['%TITLE'] = $filter['search_query'];
        }

        $params = [
            'filter' => $b24Filter,
            'select' => [
                'ID',
                'TITLE',
                'COMPANY_TYPE',
                'INDUSTRY',
                'ASSIGNED_BY_ID',
                'PHONE',
                'EMAIL',
                'WEB',
                'UF_*',
            ],
            'order'  => $order,
        ];

        $response = $this->client->call('crm.company.list', $params);
        if (!$response['success']) {
            return [
                'success'   => false,
                'error'     => $response['error'] ?? 'api_error',
                'message'   => $response['error_description'] ?? 'Ошибка получения списка компаний',
                'companies' => [],
            ];
        }

        $companies = $response['result'] ?? [];
        if ($limit > 0 && count($companies) > $limit) {
            $companies = array_slice($companies, 0, $limit);
        }

        return [
            'success'   => true,
            'companies' => $companies,
            'total'     => $response['total'] ?? count($companies),
        ];
    }

    // ==========================================
    // ПОИСК ПО ТЕЛЕФОНУ И EMAIL (crm.duplicate.findbycomm)
    // ==========================================

    /**
     * Поиск сущностей CRM по номеру телефона или email.
     *
     * @param string $type 'PHONE' или 'EMAIL'
     * @param array $values Массив номеров или адресов
     * @return array Список найденных сущностей (LEAD, CONTACT, COMPANY)
     */
    public function searchByComm(string $type, array $values): array
    {
        $normalizedType = strtoupper(trim($type));
        if (!in_array($normalizedType, ['PHONE', 'EMAIL'], true)) {
            return [
                'success' => false,
                'error'   => 'invalid_type',
                'message' => 'Тип коммуникации должен быть PHONE или EMAIL',
                'found'   => [],
            ];
        }

        $params = [
            'type'   => $normalizedType,
            'values' => array_values($values),
        ];

        $response = $this->client->call('crm.duplicate.findbycomm', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка при поиске по коммуникациям',
                'found'   => [],
            ];
        }

        $rawFound = $response['result'] ?? [];
        $found = [
            'LEAD'    => $rawFound['LEAD'] ?? [],
            'CONTACT' => $rawFound['CONTACT'] ?? [],
            'COMPANY' => $rawFound['COMPANY'] ?? [],
        ];

        return [
            'success' => true,
            'type'    => $normalizedType,
            'found'   => $found,
        ];
    }

    // ==========================================
    // ЕДИНОЕ ДОСЬЕ КЛИЕНТА (Batch Lookup)
    // ==========================================

    /**
     * Сборка единого досье клиента за один пакетный запрос CRest::callBatch.
     * Возвращает Контакт, Компанию, Сделки, Договоры и последние 10 Задач.
     *
     * @param array $params (contact_id, company_id, lead_id, deal_id, chat_id)
     * @return array Структурированное досье
     */
    public function getClientDossier(array $params): array
    {
        $contactId = !empty($params['contact_id']) ? (int)$params['contact_id'] : 0;
        $companyId = !empty($params['company_id']) ? (int)$params['company_id'] : 0;
        $leadId = !empty($params['lead_id']) ? (int)$params['lead_id'] : 0;
        $dealId = !empty($params['deal_id']) ? (int)$params['deal_id'] : 0;
        $chatId = !empty($params['chat_id']) ? (string)$params['chat_id'] : '';

        // Если передан только chat_id, пробуем извлечь привязки CRM из чата
        if (empty($contactId) && empty($companyId) && empty($leadId) && empty($dealId) && !empty($chatId)) {
            $chatBindings = $this->extractCrmBindingsFromChat($chatId);
            $contactId = $chatBindings['contactId'] ?? 0;
            $companyId = $chatBindings['companyId'] ?? 0;
            $leadId = $chatBindings['leadId'] ?? 0;
            $dealId = $chatBindings['dealId'] ?? 0;
        }

        // Если нет ни одного CRM ID, формируем пустой результат с предупреждением
        if (empty($contactId) && empty($companyId) && empty($leadId) && empty($dealId)) {
            return [
                'success' => false,
                'message' => 'Не удалось определить CRM-сущности (contact_id, company_id, lead_id или deal_id).',
                'dossier' => null,
            ];
        }

        $contractsTypeId = $this->contractService->getEntityTypeId();

        // Формирование пакетного запроса
        $batch = [];

        if ($contactId > 0) {
            $batch['contact'] = [
                'method' => 'crm.contact.get',
                'params' => ['ID' => $contactId],
            ];
        }

        if ($companyId > 0) {
            $batch['company'] = [
                'method' => 'crm.company.get',
                'params' => ['ID' => $companyId],
            ];
        } elseif ($contactId > 0) {
            // Если компания не передана явно, запрашиваем компанию контакта через макрос пакета
            $batch['company'] = [
                'method' => 'crm.company.get',
                'params' => ['ID' => '$result[contact][COMPANY_ID]'],
            ];
        }

        if ($leadId > 0) {
            $batch['lead'] = [
                'method' => 'crm.lead.get',
                'params' => ['ID' => $leadId],
            ];
        }

        if ($dealId > 0) {
            $batch['deal'] = [
                'method' => 'crm.deal.get',
                'params' => ['ID' => $dealId],
            ];
        }

        // Сделки
        $dealFilter = [];
        if ($companyId > 0) {
            $dealFilter['COMPANY_ID'] = $companyId;
        } elseif ($contactId > 0) {
            $dealFilter['CONTACT_ID'] = $contactId;
        }

        if (!empty($dealFilter)) {
            $batch['deals'] = [
                'method' => 'crm.deal.list',
                'params' => [
                    'filter' => $dealFilter,
                    'select' => ['ID', 'TITLE', 'STAGE_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'BEGINDATE', 'CLOSEDATE', 'ASSIGNED_BY_ID'],
                    'order'  => ['DATE_CREATE' => 'DESC'],
                ],
            ];
        }

        // Договоры (Смарт-процесс)
        if ($contractsTypeId > 0) {
            $contractFilter = ['entityTypeId' => $contractsTypeId];
            if ($companyId > 0) {
                $contractFilter['filter'] = ['companyId' => $companyId];
            } elseif ($contactId > 0) {
                $contractFilter['filter'] = ['contactId' => $contactId];
            }

            if (isset($contractFilter['filter'])) {
                $contractFilter['select'] = ['id', 'title', 'stageId', 'opportunity', 'currencyId', 'begindate', 'closedate'];
                $contractFilter['order'] = ['id' => 'DESC'];
                $batch['contracts'] = [
                    'method' => 'crm.item.list',
                    'params' => $contractFilter,
                ];
            }
        }

        // Последние 10 задач
        $taskCrmPrefix = null;
        if ($companyId > 0) {
            $taskCrmPrefix = 'CO_' . $companyId;
        } elseif ($contactId > 0) {
            $taskCrmPrefix = 'C_' . $contactId;
        } elseif ($leadId > 0) {
            $taskCrmPrefix = 'L_' . $leadId;
        } elseif ($dealId > 0) {
            $taskCrmPrefix = 'D_' . $dealId;
        }

        if ($taskCrmPrefix !== null) {
            $batch['tasks'] = [
                'method' => 'tasks.task.list',
                'params' => [
                    'filter' => ['UF_CRM_TASK' => $taskCrmPrefix],
                    'select' => ['ID', 'TITLE', 'STATUS', 'DEADLINE', 'RESPONSIBLE_ID', 'STAGE_ID', 'CHAT_ID'],
                    'order'  => ['CREATED_DATE' => 'DESC'],
                ],
            ];
        }

        $batchResponse = $this->client->callBatch($batch);
        $results = $batchResponse['result'] ?? [];

        // Декорация статусов задач
        $tasksList = [];
        $rawTasks = $results['tasks']['tasks'] ?? ($results['tasks'] ?? []);
        if (is_array($rawTasks)) {
            $statusMap = TaskService::STATUS_MAP;
            foreach ($rawTasks as $rawTask) {
                $t = is_array($rawTask) ? $rawTask : (array)$rawTask;
                $st = isset($t['status']) ? (int)$t['status'] : 0;
                $t['statusText'] = $statusMap[$st] ?? "Статус {$st}";
                $tasksList[] = $t;
                if (count($tasksList) >= 10) {
                    break;
                }
            }
        }

        $contractsList = $results['contracts']['items'] ?? ($results['contracts'] ?? []);

        return [
            'success' => true,
            'dossier' => [
                'contact'   => $results['contact'] ?? null,
                'company'   => $results['company'] ?? null,
                'lead'      => $results['lead'] ?? null,
                'deal'      => $results['deal'] ?? null,
                'deals'     => $results['deals'] ?? [],
                'contracts' => $contractsList,
                'tasks'     => $tasksList,
            ],
        ];
    }

    /**
     * Извлечение связей с сущностями CRM из чата или сущности диалога (учитывает entity_data_1 и entity_data_2).
     */
    public function extractCrmBindingsFromChat(string|int $chatId): array
    {
        $dialogId = str_starts_with((string)$chatId, 'chat') ? (string)$chatId : 'chat' . $chatId;

        $response = $this->client->call('im.dialog.get', ['DIALOG_ID' => $dialogId]);
        if (!$response['success'] || empty($response['result'])) {
            return [];
        }

        $entityData1 = $response['result']['entity_data_1']
            ?? $response['result']['ENTITY_DATA_1']
            ?? '';
        $entityData2 = $response['result']['entity_data_2']
            ?? $response['result']['ENTITY_DATA_2']
            ?? '';

        return self::parseEntityData1($entityData1 . '|' . $entityData2);
    }

    /**
     * Парсинг строки ENTITY_DATA_1 формата:
     * Y|LEAD|10|COMPANY|45|CONTACT|120|DEAL|300
     */
    public static function parseEntityData1(string $entityData): array
    {
        $bindings = [
            'leadId'    => 0,
            'companyId' => 0,
            'contactId' => 0,
            'dealId'    => 0,
        ];

        if ($entityData === '') {
            return $bindings;
        }

        if (preg_match('/LEAD\|(\d+)/i', $entityData, $m)) {
            $bindings['leadId'] = (int)$m[1];
        }
        if (preg_match('/COMPANY\|(\d+)/i', $entityData, $m)) {
            $bindings['companyId'] = (int)$m[1];
        }
        if (preg_match('/CONTACT\|(\d+)/i', $entityData, $m)) {
            $bindings['contactId'] = (int)$m[1];
        }
        if (preg_match('/DEAL\|(\d+)/i', $entityData, $m)) {
            $bindings['dealId'] = (int)$m[1];
        }

        return $bindings;
    }
}
