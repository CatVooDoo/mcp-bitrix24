<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Mcp;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Архитектурный задел для Фазы 2 (ПМ-Агент / AI Project Manager).
 * По умолчанию ОТКЛЮЧЕН константой B24_ENABLE_PM_AGENT_TOOLS = false.
 * Содержит методы на создание и изменение задач, комментариев и стадий сделок.
 */
class PmAgentTools
{
    public function __construct(
        private readonly BitrixClient $client = new BitrixClient()
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
                'description' => '[Фаза 2: ПМ-Агент] Создание новой задачи в Битрикс24 (tasks.task.add).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'          => ['type' => 'string', 'description' => 'Название задачи'],
                        'description'    => ['type' => 'string', 'description' => 'Описание задачи'],
                        'responsible_id' => ['type' => 'integer', 'description' => 'ID ответственного сотрудника'],
                        'deadline'       => ['type' => 'string', 'description' => 'Крайний срок (ISO 8601 или YYYY-MM-DD HH:MM:SS)'],
                        'group_id'       => ['type' => 'integer', 'description' => 'ID проекта / группы'],
                        'crm_bindings'   => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Привязки к CRM (например, ["D_123", "CO_456"])',
                        ],
                    ],
                    'required'   => ['title', 'responsible_id'],
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
            'b24_create_task'       => $this->createTask($arguments),
            'b24_update_task'       => $this->updateTask($arguments),
            'b24_add_task_comment'  => $this->addTaskComment($arguments),
            'b24_set_task_result'   => $this->setTaskResult($arguments),
            'b24_update_deal_stage' => $this->updateDealStage($arguments),
            default                 => [
                'success' => false,
                'error'   => 'tool_not_found',
                'message' => "Инструмент {$toolName} не найден в PmAgentTools.",
            ],
        };
    }

    private function createTask(array $args): array
    {
        $fields = [
            'TITLE'          => (string)($args['title'] ?? ''),
            'RESPONSIBLE_ID' => (int)($args['responsible_id'] ?? 0),
        ];

        if (!empty($args['description'])) {
            $fields['DESCRIPTION'] = (string)$args['description'];
        }
        if (!empty($args['deadline'])) {
            $fields['DEADLINE'] = (string)$args['deadline'];
        }
        if (!empty($args['group_id'])) {
            $fields['GROUP_ID'] = (int)$args['group_id'];
        }
        if (!empty($args['crm_bindings'])) {
            $fields['UF_CRM_TASK'] = (array)$args['crm_bindings'];
        }

        $res = $this->client->call('tasks.task.add', ['fields' => $fields]);
        return [
            'success' => $res['success'],
            'taskId'  => $res['result']['task']['id'] ?? ($res['result'] ?? null),
            'raw'     => $res['result'] ?? null,
            'error'   => $res['error_description'] ?? $res['error'] ?? null,
        ];
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
