<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Mcp;

use Bitrix24Mcp\Services\CrmService;
use Bitrix24Mcp\Services\ContractService;
use Bitrix24Mcp\Services\TaskService;
use Bitrix24Mcp\Services\ChatService;
use Bitrix24Mcp\Services\OpenLinesPoller;
use Bitrix24Mcp\Core\Logger;

/**
 * Набор инструментов MCP Фазы 1 (Агент поддержки/чата) со строгим Read-Only доступом к CRM и задачам.
 */
class ChatAgentTools
{
    public function __construct(
        private readonly CrmService $crmService = new CrmService(),
        private readonly ContractService $contractService = new ContractService(),
        private readonly TaskService $taskService = new TaskService(),
        private readonly ChatService $chatService = new ChatService(),
        private readonly OpenLinesPoller $poller = new OpenLinesPoller()
    ) {}

    /**
     * Возвращает список определений 11 инструментов MCP со схемами inputSchema.
     */
    public function getDefinitions(): array
    {
        return [
            [
                'name'        => 'b24_get_client_dossier',
                'description' => 'Быстрый пакетный сбор полного досье клиента (Контакт, Компания, Сделки, Договоры, последние 10 задач) за 1 вызов по chat_id, company_id, contact_id, deal_id или lead_id.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'chat_id'    => ['type' => 'string', 'description' => 'ID чата или диалога (например, "chat123" или "123")'],
                        'contact_id' => ['type' => 'integer', 'description' => 'ID контакта в CRM'],
                        'company_id' => ['type' => 'integer', 'description' => 'ID компании в CRM'],
                        'deal_id'    => ['type' => 'integer', 'description' => 'ID сделки в CRM'],
                        'lead_id'    => ['type' => 'integer', 'description' => 'ID лида в CRM'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_search_crm_by_comm',
                'description' => 'Поиск Лидов, Контактов и Компаний в Битрикс24 по номеру телефона или адресу email (crm.duplicate.findbycomm).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'   => [
                            'type'        => 'string',
                            'enum'        => ['PHONE', 'EMAIL'],
                            'description' => 'Тип канала связи (PHONE или EMAIL)',
                        ],
                        'values' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Список номеров телефонов или email-адресов для поиска',
                        ],
                    ],
                    'required'   => ['type', 'values'],
                ],
            ],
            [
                'name'        => 'b24_get_leads',
                'description' => 'Поиск и чтение Лидов (по lead_id или фильтрам: company_id, contact_id, status_id, search_query). Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'lead_id'      => ['type' => 'integer', 'description' => 'ID конкретного лида'],
                        'status_id'    => ['type' => 'string', 'description' => 'Статус лида (например, "NEW", "IN_PROCESS")'],
                        'company_id'   => ['type' => 'integer', 'description' => 'ID привязанной компании'],
                        'contact_id'   => ['type' => 'integer', 'description' => 'ID привязанного контакта'],
                        'search_query' => ['type' => 'string', 'description' => 'Текстовый поиск по названию лида'],
                        'limit'        => ['type' => 'integer', 'description' => 'Максимальное количество записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_deals',
                'description' => 'Поиск и чтение Сделок (по deal_id или фильтрам: company_id, contact_id, stage_id, category_id, search_query). Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'deal_id'      => ['type' => 'integer', 'description' => 'ID конкретной сделки'],
                        'stage_id'     => ['type' => 'string', 'description' => 'Стадия сделки (например, "WON", "EXECUTING")'],
                        'category_id'  => ['type' => 'integer', 'description' => 'ID направления (воронки) сделок'],
                        'company_id'   => ['type' => 'integer', 'description' => 'ID компании'],
                        'contact_id'   => ['type' => 'integer', 'description' => 'ID контакта'],
                        'search_query' => ['type' => 'string', 'description' => 'Текстовый поиск по названию сделки'],
                        'limit'        => ['type' => 'integer', 'description' => 'Лимит записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_companies',
                'description' => 'Поиск и чтение Компаний (по company_id или search_query) со всеми пользовательскими UF_CRM_* полями. Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'company_id'   => ['type' => 'integer', 'description' => 'ID конкретной компании'],
                        'search_query' => ['type' => 'string', 'description' => 'Поиск по названию компании'],
                        'company_type' => ['type' => 'string', 'description' => 'Тип компании (CUSTOMER, SUPPLIER и т.д.)'],
                        'limit'        => ['type' => 'integer', 'description' => 'Лимит записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_contacts',
                'description' => 'Поиск и чтение Контактов (по contact_id, company_id или search_query). Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'contact_id'   => ['type' => 'integer', 'description' => 'ID конкретного контакта'],
                        'company_id'   => ['type' => 'integer', 'description' => 'ID компании контакта'],
                        'search_query' => ['type' => 'string', 'description' => 'Поиск по имени/фамилии контакта'],
                        'limit'        => ['type' => 'integer', 'description' => 'Лимит записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_contracts',
                'description' => 'Поиск и чтение Смарт-процесса «ДОГОВОРЫ» (crm.item.list/get) по contract_id, company_id, contact_id или search_query. Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'contract_id'  => ['type' => 'integer', 'description' => 'ID конкретного договора'],
                        'company_id'   => ['type' => 'integer', 'description' => 'ID компании'],
                        'contact_id'   => ['type' => 'integer', 'description' => 'ID контакта'],
                        'stage_id'     => ['type' => 'string', 'description' => 'Стадия договора'],
                        'search_query' => ['type' => 'string', 'description' => 'Поиск по названию договора'],
                        'limit'        => ['type' => 'integer', 'description' => 'Лимит записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_tasks',
                'description' => 'Получение списка задач с фильтрацией по CRM (company_id, contact_id, deal_id, lead_id), ответственному, статусу или группе. Read-only.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'company_id'     => ['type' => 'integer', 'description' => 'ID компании (UF_CRM_TASK CO_*)'],
                        'contact_id'     => ['type' => 'integer', 'description' => 'ID контакта (UF_CRM_TASK C_*)'],
                        'deal_id'        => ['type' => 'integer', 'description' => 'ID сделки (UF_CRM_TASK D_*)'],
                        'lead_id'        => ['type' => 'integer', 'description' => 'ID лида (UF_CRM_TASK L_*)'],
                        'responsible_id' => ['type' => 'integer', 'description' => 'ID ответственного сотрудника'],
                        'group_id'       => ['type' => 'integer', 'description' => 'ID проекта / рабочей группы'],
                        'status'         => ['type' => 'integer', 'description' => 'Код статуса (2=Ждет, 3=В работе, 4=Контроль, 5=Завершена, 6=Отложена)'],
                        'search_query'   => ['type' => 'string', 'description' => 'Поиск по заголовку задачи'],
                        'limit'          => ['type' => 'integer', 'description' => 'Лимит записей (по умолчанию 20)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_get_task_and_chat_details',
                'description' => 'Получение исчерпывающих деталей задачи (task_id): карточка, зафиксированные результаты, комментарии и сообщения из встроенного чата задачи.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'              => ['type' => 'integer', 'description' => 'ID задачи'],
                        'only_public_comments' => ['type' => 'boolean', 'description' => 'Фильтровать только публичные комментарии (по B24_PUBLIC_COMMENT_PREFIX)'],
                    ],
                    'required'   => ['task_id'],
                ],
            ],
            [
                'name'        => 'b24_get_chat_history',
                'description' => 'Чтение истории сообщений из любого чата или Открытой линии Битрикс24 (dialog_id или chat_id, limit).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'dialog_id' => ['type' => 'string', 'description' => 'Идентификатор диалога (например, "chat123" или числовой ID чата)'],
                        'limit'     => ['type' => 'integer', 'description' => 'Количество последних сообщений (по умолчанию 30)'],
                    ],
                    'required'   => ['dialog_id'],
                ],
            ],
            [
                'name'        => 'b24_chat_action',
                'description' => 'Выполнение разрешенных действий в чате: reply (отправка ответа), typing (индикатор набора) или transfer_to_operator (перевод на оператора с передачей сводки).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'action'       => [
                            'type'        => 'string',
                            'enum'        => ['reply', 'typing', 'transfer_to_operator'],
                            'description' => 'Тип действия в чате',
                        ],
                        'dialog_id'    => ['type' => 'string', 'description' => 'Идентификатор диалога (например, "chat123" или ID чата)'],
                        'message'      => ['type' => 'string', 'description' => 'Текст ответа (обязательно для действия reply)'],
                        'summary_note' => ['type' => 'string', 'description' => 'Сводка контекста диалога для живого оператора при передаче (для transfer_to_operator)'],
                    ],
                    'required'   => ['action', 'dialog_id'],
                ],
            ],
            [
                'name'        => 'b24_poll_openlines',
                'description' => 'Опрос активных сессий Открытых линий и чатов для выявления новых и неотвеченных сообщений клиентов с маркерами статуса ([STATUS: NEED_REPLY]) и срочности ([PRIORITY: URGENT]).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'          => ['type' => 'integer', 'description' => 'Количество проверяемых диалогов (по умолчанию 30)'],
                        'only_openlines' => ['type' => 'boolean', 'description' => 'Опрашивать только Открытые линии (по умолчанию true)'],
                        'force'          => ['type' => 'boolean', 'description' => 'Игнорировать кеш ранее просмотренных сообщений (по умолчанию false)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_create_task',
                'description' => 'Постановка новой задачи в Битрикс24 по регламенту компании: 1. Название "<Группа проектов> | <Смысл задачи> <Дата DD.MM.YYYY>", 2. Описание с кратким планом действий, 3. Участники (responsible_id, accomplices, auditors), 4. Крайний срок (deadline), 5. Проектная группа (group_id), 6. Учет времени (time_estimate_hours / time_estimate_minutes).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_group_name'    => ['type' => 'string', 'description' => 'Пункт 1: Название проектной группы для заголовка (например, "HR", "DEV", "SALES")'],
                        'task_summary'          => ['type' => 'string', 'description' => 'Пункт 1: Смысл задачи (например, "Заполнить документы по кандидату Андрей Высоцкий")'],
                        'title'                 => ['type' => 'string', 'description' => 'Пункт 1: Полное название задачи, если передается сразу в формате "<Группа> | <Смысл> <DD.MM.YYYY>"'],
                        'task_date'             => ['type' => 'string', 'description' => 'Пункт 1: Дата постановки (DD.MM.YYYY, по умолчанию текущая дата)'],
                        'description'           => ['type' => 'string', 'description' => 'Пункт 2: Описание задачи. Обязательно должно содержать краткий план выполнения и/или ссылки на алгоритмы'],
                        'plan_steps'            => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Пункт 2: Пункты краткого плана действий (список шагов)'],
                        'links'                 => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Пункт 2: Ссылки на алгоритмы, регламенты и материалы'],
                        'responsible_id'        => ['type' => 'integer', 'description' => 'Пункт 3: ID ответственного сотрудника (по умолчанию Олег Тихов 882)'],
                        'accomplices'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Пункт 3: ID соисполнителей'],
                        'auditors'              => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Дополнительные наблюдатели. Минаев Никита (1650) и Акифьев Андрей (1) всегда добавляются автоматически'],
                        'deadline'              => ['type' => 'string', 'description' => 'Пункт 4: Крайний срок выполнения (ISO 8601 или YYYY-MM-DD HH:MM:SS, обязательно кроме задач-мануалов)'],
                        'is_manual'             => ['type' => 'boolean', 'description' => 'Пункт 4: Флаг задачи-мануала (только мануалы могут не иметь дедлайна)'],
                        'group_id'              => ['type' => 'integer', 'description' => 'Пункт 5: ID проектной группы (обязательно). Если не известен, воспользуйтесь b24_get_workgroups'],
                        'time_estimate_hours'   => ['type' => 'number', 'description' => 'Пункт 6: Предварительные трудозатраты в часах (обязательно, например 1.5)'],
                        'time_estimate_minutes' => ['type' => 'integer', 'description' => 'Пункт 6: Предварительные трудозатраты в минутах (например, 90)'],
                        'crm_bindings'          => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Привязки к сущностям CRM (например, ["C_12", "D_34"])'],
                        'checklist'             => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Пункты чек-листа задачи (добавляются автоматически после создания)'],
                    ],
                    'required'   => ['responsible_id'],
                ],
            ],
            [
                'name'        => 'b24_get_workgroups',
                'description' => 'Поиск и получение списка проектных рабочих групп Битрикс24 (sonet_group.get) для выбора group_id при постановке задачи.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search_query' => ['type' => 'string', 'description' => 'Поиск группы по названию (например, "HR" или "Разработка")'],
                        'limit'        => ['type' => 'integer', 'description' => 'Максимальное количество групп (по умолчанию 20)'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Вызов инструмента по имени с валидацией аргументов.
     */
    public function execute(string $toolName, array $arguments): array
    {
        return match ($toolName) {
            'b24_get_client_dossier'        => $this->executeGetClientDossier($arguments),
            'b24_search_crm_by_comm'        => $this->executeSearchCrmByComm($arguments),
            'b24_get_leads'                 => $this->executeGetLeads($arguments),
            'b24_get_deals'                 => $this->executeGetDeals($arguments),
            'b24_get_companies'             => $this->executeGetCompanies($arguments),
            'b24_get_contacts'              => $this->executeGetContacts($arguments),
            'b24_get_contracts'             => $this->executeGetContracts($arguments),
            'b24_get_tasks'                 => $this->executeGetTasks($arguments),
            'b24_get_task_and_chat_details' => $this->executeGetTaskDetails($arguments),
            'b24_get_chat_history'          => $this->executeGetChatHistory($arguments),
            'b24_chat_action'               => $this->executeChatAction($arguments),
            'b24_poll_openlines'            => $this->executePollOpenlines($arguments),
            'b24_create_task'               => $this->executeCreateTask($arguments),
            'b24_get_workgroups'            => $this->executeGetWorkgroups($arguments),
            default                         => [
                'success' => false,
                'error'   => 'tool_not_found',
                'message' => "Инструмент {$toolName} не найден в ChatAgentTools.",
            ],
        };
    }

    private function executeCreateTask(array $args): array
    {
        return $this->taskService->createTask($args);
    }

    private function executeGetWorkgroups(array $args): array
    {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->taskService->getWorkgroups($args, $limit);
    }

    private function executePollOpenlines(array $args): array
    {
        return $this->poller->poll($args);
    }

    private function executeGetClientDossier(array $args): array
    {
        return $this->crmService->getClientDossier($args);
    }

    private function executeSearchCrmByComm(array $args): array
    {
        $type = (string)($args['type'] ?? '');
        $values = (array)($args['values'] ?? []);
        return $this->crmService->searchByComm($type, $values);
    }

    private function executeGetLeads(array $args): array
    {
        if (!empty($args['lead_id'])) {
            return $this->crmService->getLead((int)$args['lead_id']);
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->crmService->getLeads($args, $limit);
    }

    private function executeGetDeals(array $args): array
    {
        if (!empty($args['deal_id'])) {
            return $this->crmService->getDeal((int)$args['deal_id']);
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->crmService->getDeals($args, $limit);
    }

    private function executeGetCompanies(array $args): array
    {
        if (!empty($args['company_id'])) {
            return $this->crmService->getCompany((int)$args['company_id']);
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->crmService->getCompanies($args, $limit);
    }

    private function executeGetContacts(array $args): array
    {
        if (!empty($args['contact_id'])) {
            return $this->crmService->getContact((int)$args['contact_id']);
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->crmService->getContacts($args, $limit);
    }

    private function executeGetContracts(array $args): array
    {
        if (!empty($args['contract_id'])) {
            return $this->contractService->getContract((int)$args['contract_id']);
        }
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->contractService->getContracts($args, $limit);
    }

    private function executeGetTasks(array $args): array
    {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 20;
        return $this->taskService->getTasks($args, $limit);
    }

    private function executeGetTaskDetails(array $args): array
    {
        $taskId = (int)($args['task_id'] ?? 0);
        $onlyPublic = !empty($args['only_public_comments']);
        return $this->taskService->getTaskFullContext($taskId, $onlyPublic);
    }

    private function executeGetChatHistory(array $args): array
    {
        $dialogId = (string)($args['dialog_id'] ?? '');
        $limit = isset($args['limit']) ? (int)$args['limit'] : 30;
        return $this->chatService->getHistory($dialogId, $limit);
    }

    private function executeChatAction(array $args): array
    {
        $action = (string)($args['action'] ?? '');
        $dialogId = (string)($args['dialog_id'] ?? '');

        return match ($action) {
            'reply' => (function () use ($dialogId, $args): array {
                $message = (string)($args['message'] ?? '');
                if ($message === '') {
                    return ['success' => false, 'error' => 'missing_message', 'message' => 'Параметр message обязателен для действия reply'];
                }
                return $this->chatService->sendReply($dialogId, $message);
            })(),
            'typing' => $this->chatService->sendTyping($dialogId),
            'transfer_to_operator' => (function () use ($dialogId, $args): array {
                $summary = (string)($args['summary_note'] ?? '');
                return $this->chatService->transferToOperator($dialogId, $summary);
            })(),
            default => [
                'success' => false,
                'error'   => 'invalid_action',
                'message' => "Неизвестное действие {$action}. Допустимы: reply, typing, transfer_to_operator",
            ],
        };
    }
}
