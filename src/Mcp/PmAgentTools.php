<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Mcp;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\TaskService;

/**
 * Архитектурный задел для Фазы 2 (ПМ-Агент / AI Project Manager).
 * По умолчанию ОТКЛЮЧЕН константой B24_ENABLE_PM_AGENT_TOOLS = false.
 * Содержит методы на создание и изменение задач, комментариев и стадий сделок.
 */
class PmAgentTools
{
    public function __construct(
        private readonly BitrixClient $client = new BitrixClient(),
        private readonly TaskService $taskService = new TaskService()
    ) {}

    /**
     * Проверка, включен ли режим ПМ-агента.
     */
    public function isEnabled(): bool
    {
        return defined('B24_ENABLE_PM_AGENT_TOOLS') && B24_ENABLE_PM_AGENT_TOOLS === true;
    }

    /**
     * Возвращает список определений инструментов Фазы 2.
     * Если функционал отключен, возвращает пустой массив во избежание случайных вызовов ИИ-агентом.
     */
    public function getDefinitions(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        return [
            [
                'name'        => 'b24_create_task',
                'description' => '[Фаза 2: ПМ-Агент] Постановка новой задачи в Битрикс24 по регламенту компании: 1. Название "<Группа проектов> | <Смысл задачи> <Дата DD.MM.YYYY>", 2. Описание с кратким планом действий, 3. Участники (responsible_id, accomplices, auditors), 4. Крайний срок (deadline), 5. Проектная группа (group_id), 6. Учет времени (time_estimate_hours / time_estimate_minutes).',
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
                        'responsible_id'        => ['type' => 'integer', 'description' => 'Пункт 3: ID ответственного сотрудника (обязательно)'],
                        'accomplices'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Пункт 3: ID соисполнителей'],
                        'auditors'              => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Пункт 3: ID наблюдателей'],
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
                'name'        => 'b24_add_task_checklist',
                'description' => '[Фаза 2: ПМ-Агент] Добавление пунктов чек-листа в задачу Битрикс24 (task.checklistitem.add).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'ID задачи'],
                        'items'   => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Массив пунктов чек-листа',
                        ],
                    ],
                    'required'   => ['task_id', 'items'],
                ],
            ],
            [
                'name'        => 'b24_get_workgroups',
                'description' => '[Фаза 2: ПМ-Агент] Поиск и получение списка проектных рабочих групп Битрикс24 (sonet_group.get) для выбора group_id при постановке задачи.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search_query' => ['type' => 'string', 'description' => 'Поиск по части названия группы (например, "HR" или "Продажи")'],
                        'limit'        => ['type' => 'integer', 'description' => 'Лимит групп (по умолчанию 50)'],
                    ],
                ],
            ],
            [
                'name'        => 'b24_update_task',
                'description' => '[Фаза 2: ПМ-Агент] Обновление задачи в Битрикс24 (tasks.task.update).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'        => ['type' => 'integer', 'description' => 'ID задачи'],
                        'title'          => ['type' => 'string', 'description' => 'Новое название задачи'],
                        'description'    => ['type' => 'string', 'description' => 'Новое описание'],
                        'responsible_id' => ['type' => 'integer', 'description' => 'Новый ответственный'],
                        'deadline'       => ['type' => 'string', 'description' => 'Новый дедлайн'],
                        'status'         => ['type' => 'integer', 'description' => 'Новый статус задачи'],
                    ],
                    'required'   => ['task_id'],
                ],
            ],
            [
                'name'        => 'b24_add_task_comment',
                'description' => '[Фаза 2: ПМ-Агент] Добавление комментария в задачу (task.commentitem.add).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'ID задачи'],
                        'message' => ['type' => 'string', 'description' => 'Текст комментария'],
                    ],
                    'required'   => ['task_id', 'message'],
                ],
            ],
            [
                'name'        => 'b24_set_task_result',
                'description' => '[Фаза 2: ПМ-Агент] Фиксация официального результата задачи (tasks.task.result.addFromComment).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'    => ['type' => 'integer', 'description' => 'ID задачи'],
                        'comment_id' => ['type' => 'integer', 'description' => 'ID комментария, фиксируемого как результат'],
                    ],
                    'required'   => ['task_id', 'comment_id'],
                ],
            ],
            [
                'name'        => 'b24_update_deal_stage',
                'description' => '[Фаза 2: ПМ-Агент] Перевод сделки на новую стадию (crm.deal.update).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'deal_id'  => ['type' => 'integer', 'description' => 'ID сделки'],
                        'stage_id' => ['type' => 'string', 'description' => 'Идентификатор новой стадии воронки'],
                    ],
                    'required'   => ['deal_id', 'stage_id'],
                ],
            ],
        ];
    }

    /**
     * Вызов инструментов Фазы 2.
     */
    public function execute(string $toolName, array $arguments): array
    {
        if (!$this->isEnabled()) {
            Logger::warning("Attempted to call disabled PM Agent tool: {$toolName}", [], 'pm_agent');
            return [
                'success' => false,
                'error'   => 'pm_tools_disabled',
                'message' => 'Инструменты ПМ-агента (Фаза 2) отключены в текущей конфигурации. Установите B24_ENABLE_PM_AGENT_TOOLS = true в settings.php для их активации.',
            ];
        }

        return match ($toolName) {
            'b24_create_task'        => $this->createTask($arguments),
            'b24_add_task_checklist' => $this->addTaskChecklist($arguments),
            'b24_get_workgroups'     => $this->getWorkgroups($arguments),
            'b24_update_task'        => $this->updateTask($arguments),
            'b24_add_task_comment'   => $this->addTaskComment($arguments),
            'b24_set_task_result'    => $this->setTaskResult($arguments),
            'b24_update_deal_stage'  => $this->updateDealStage($arguments),
            default                  => [
                'success' => false,
                'error'   => 'tool_not_found',
                'message' => "Инструмент {$toolName} не найден в PmAgentTools.",
            ],
        };
    }

    private function createTask(array $args): array
    {
        $res = $this->taskService->createTask($args);

        if (!empty($res['success']) && !empty($res['task_id']) && !empty($args['checklist']) && is_array($args['checklist'])) {
            $checklistRes = $this->addTaskChecklist([
                'task_id' => (int)$res['task_id'],
                'items'   => $args['checklist'],
            ]);
            $res['checklist'] = $checklistRes;
        }

        return $res;
    }

    public function addTaskChecklist(array $args): array
    {
        $taskId = (int)($args['task_id'] ?? 0);
        $items = isset($args['items']) && is_array($args['items']) ? $args['items'] : [];

        if ($taskId <= 0) {
            return [
                'success' => false,
                'error'   => 'missing_task_id',
                'message' => 'Не указан ID задачи (task_id).',
            ];
        }

        if (empty($items)) {
            return [
                'success' => false,
                'error'   => 'empty_items',
                'message' => 'Список пунктов чек-листа пуст.',
            ];
        }

        $batch = [];
        $i = 0;
        foreach ($items as $item) {
            $title = (string)$item;
            if (trim($title) === '') {
                continue;
            }

            $batch['cmd_' . $i] = [
                'method' => 'task.checklistitem.add',
                'params' => [
                    (int)$taskId,
                    [
                        'TITLE'       => (string)$item,
                        'IS_COMPLETE' => 'N',
                        'SORT_INDEX'  => ($i + 1) * 10,
                    ],
                ],
            ];
            $i++;
        }

        if (empty($batch)) {
            return [
                'success' => false,
                'error'   => 'empty_items',
                'message' => 'Не передано ни одного непустого пункта чек-листа.',
            ];
        }

        Logger::info("Adding checklist to task #{$taskId} via batch ({$i} items)", [
            'task_id' => $taskId,
            'count'   => $i,
        ], 'pm_agent');

        $res = $this->client->callBatch($batch);

        return [
            'success'      => $res['success'],
            'taskId'       => $taskId,
            'task_id'      => $taskId,
            'count'        => $i,
            'result'       => $res['result'] ?? null,
            'result_error' => $res['result_error'] ?? null,
            'error'        => $res['error_description'] ?? $res['error'] ?? null,
        ];
    }

    private function getWorkgroups(array $args): array
    {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 50;
        return $this->taskService->getWorkgroups($args, $limit);
    }

    private function updateTask(array $args): array
    {
        $taskId = (int)($args['task_id'] ?? 0);
        $fields = [];

        if (isset($args['title'])) {
            $fields['TITLE'] = (string)$args['title'];
        }
        if (isset($args['description'])) {
            $fields['DESCRIPTION'] = (string)$args['description'];
        }
        if (isset($args['responsible_id'])) {
            $fields['RESPONSIBLE_ID'] = (int)$args['responsible_id'];
        }
        if (isset($args['deadline'])) {
            $fields['DEADLINE'] = (string)$args['deadline'];
        }
        if (isset($args['status'])) {
            $fields['STATUS'] = (int)$args['status'];
        }

        $res = $this->client->call('tasks.task.update', [
            'taskId' => $taskId,
            'fields' => $fields,
        ]);

        return [
            'success' => $res['success'],
            'taskId'  => $taskId,
            'error'   => $res['error_description'] ?? $res['error'] ?? null,
        ];
    }

    private function addTaskComment(array $args): array
    {
        $taskId = (int)($args['task_id'] ?? 0);
        $message = (string)($args['message'] ?? '');

        $res = $this->client->call('task.commentitem.add', [
            $taskId,
            ['POST_MESSAGE' => $message],
        ]);

        return [
            'success'   => $res['success'],
            'commentId' => $res['result'] ?? null,
            'error'     => $res['error_description'] ?? $res['error'] ?? null,
        ];
    }

    private function setTaskResult(array $args): array
    {
        $commentId = (int)($args['comment_id'] ?? 0);

        $res = $this->client->call('tasks.task.result.addFromComment', [
            'commentId' => $commentId,
        ]);

        return [
            'success' => $res['success'],
            'result'  => $res['result'] ?? null,
            'error'   => $res['error_description'] ?? $res['error'] ?? null,
        ];
    }

    private function updateDealStage(array $args): array
    {
        $dealId = (int)($args['deal_id'] ?? 0);
        $stageId = (string)($args['stage_id'] ?? '');

        $res = $this->client->call('crm.deal.update', [
            'ID'     => $dealId,
            'fields' => ['STAGE_ID' => $stageId],
        ]);

        return [
            'success' => $res['success'],
            'dealId'  => $dealId,
            'error'   => $res['error_description'] ?? $res['error'] ?? null,
        ];
    }
}
