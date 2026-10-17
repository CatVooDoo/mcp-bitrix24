<?php

declare(strict_types=1);

namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Core\Logger;

/**
 * Сервис для чтения задач, комментариев, результатов и внутренних чатов задач.
 * Строгий Read-Only режим для Фазы 1.
 */
class TaskService
{
    public const STATUS_MAP = [
        1 => 'Новая',
        2 => 'Ждет выполнения',
        3 => 'В работе',
        4 => 'Ждет контроля',
        5 => 'Завершена',
        6 => 'Отложена',
        7 => 'Отклонена',
    ];

    public function __construct(
        private readonly BitrixClient $client = new BitrixClient()
    ) {}

    /**
     * Поиск и получение списка задач с фильтрацией по CRM и ответственным.
     *
     * @param array $filter Фильтры (lead_id, contact_id, company_id, deal_id, responsible_id, status, group_id)
     * @param int $limit Ограничение количества
     * @param array $order Сортировка
     * @return array Массив задач с добавленным текстовым статусом
     */
    public function getTasks(array $filter = [], int $limit = 20, array $order = ['CREATED_DATE' => 'DESC']): array
    {
        $b24Filter = $this->buildCrmTaskFilter($filter);

        $params = [
            'filter' => $b24Filter,
            'select' => [
                'ID',
                'TITLE',
                'STATUS',
                'DEADLINE',
                'RESPONSIBLE_ID',
                'CREATED_BY',
                'GROUP_ID',
                'STAGE_ID',
                'UF_CRM_TASK',
                'CHAT_ID',
            ],
            'order'  => $order,
        ];

        $response = $this->client->call('tasks.task.list', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка при получении списка задач',
                'tasks'   => [],
            ];
        }

        $rawTasks = $response['result']['tasks'] ?? [];
        $tasks = [];

        foreach ($rawTasks as $rawTask) {
            $task = is_array($rawTask) ? $rawTask : (array)$rawTask;
            $statusCode = isset($task['status']) ? (int)$task['status'] : 0;
            $task['statusText'] = self::STATUS_MAP[$statusCode] ?? "Неизвестный статус ({$statusCode})";
            $tasks[] = $task;

            if ($limit > 0 && count($tasks) >= $limit) {
                break;
            }
        }

        return [
            'success' => true,
            'tasks'   => $tasks,
            'total'   => $response['total'] ?? count($tasks),
        ];
    }

    /**
     * Получение карточки задачи (tasks.task.get).
     */
    public function getTask(int $taskId): array
    {
        $response = $this->client->call('tasks.task.get', [
            'taskId' => $taskId,
            'select' => ['*', 'UF_*', 'CHAT_ID'],
        ]);

        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? "Не удалось получить задачу #{$taskId}",
                'task'    => null,
            ];
        }

        $task = $response['result']['task'] ?? null;
        if (is_array($task)) {
            $statusCode = isset($task['status']) ? (int)$task['status'] : 0;
            $task['statusText'] = self::STATUS_MAP[$statusCode] ?? "Неизвестный статус ({$statusCode})";
        }

        return [
            'success' => true,
            'task'    => $task,
        ];
    }

    /**
     * Получение зафиксированных результатов задачи (tasks.task.result.list).
     */
    public function getTaskResults(int $taskId): array
    {
        $response = $this->client->call('tasks.task.result.list', [
            'taskId' => $taskId,
        ]);

        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка получения результатов задачи',
                'results' => [],
            ];
        }

        return [
            'success' => true,
            'results' => $response['result'] ?? [],
        ];
    }

    /**
     * Получение комментариев задачи (task.commentitem.getlist).
     * Важно: параметры передаются строго как упорядоченный список [$taskId, ['POST_DATE' => 'ASC'], []].
     */
    public function getTaskComments(int $taskId, bool $onlyPublic = false): array
    {
        $response = $this->client->call('task.commentitem.getlist', [
            $taskId,
            ['POST_DATE' => 'ASC'],
            [],
        ]);

        if (!$response['success']) {
            return [
                'success'  => false,
                'error'    => $response['error'] ?? 'api_error',
                'message'  => $response['error_description'] ?? 'Ошибка получения комментариев задачи',
                'comments' => [],
            ];
        }

        $rawComments = $response['result'] ?? [];
        $comments = [];

        $publicPrefix = defined('B24_PUBLIC_COMMENT_PREFIX') ? (string)B24_PUBLIC_COMMENT_PREFIX : '';

        foreach ($rawComments as $rawComment) {
            $comment = is_array($rawComment) ? $rawComment : (array)$rawComment;
            $postMessage = (string)($comment['POST_MESSAGE'] ?? '');

            $isPublic = true;
            if ($publicPrefix !== '') {
                $isPublic = str_starts_with(trim($postMessage), $publicPrefix);
            }

            $comment['IS_PUBLIC'] = $isPublic;

            if ($onlyPublic && !$isPublic) {
                continue;
            }

            $comments[] = $comment;
        }

        return [
            'success'  => true,
            'comments' => $comments,
            'total'    => count($comments),
        ];
    }

    /**
     * Получение сообщений встроенного чата задачи (im.dialog.messages.get).
     */
    public function getTaskChatMessages(int|string $chatId, int $limit = 30): array
    {
        if (empty($chatId)) {
            return [
                'success'  => true,
                'messages' => [],
            ];
        }

        $dialogId = str_starts_with((string)$chatId, 'chat') ? (string)$chatId : 'chat' . $chatId;

        $response = $this->client->call('im.dialog.messages.get', [
            'DIALOG_ID' => $dialogId,
            'LIMIT'     => $limit,
        ]);

        if (!$response['success']) {
            return [
                'success'  => false,
                'error'    => $response['error'] ?? 'api_error',
                'message'  => $response['error_description'] ?? 'Ошибка получения сообщений чата задачи',
                'messages' => [],
            ];
        }

        return [
            'success'  => true,
            'messages' => $response['result']['messages'] ?? ($response['result'] ?? []),
            'users'    => $response['result']['users'] ?? [],
            'files'    => $response['result']['files'] ?? [],
        ];
    }

    /**
     * Получение полного контекста задачи (4 источника: карточка, результаты, комментарии, чат задачи).
     */
    public function getTaskFullContext(int $taskId, bool $onlyPublicComments = false): array
    {
        $taskData = $this->getTask($taskId);
        if (!$taskData['success'] || empty($taskData['task'])) {
            return [
                'success' => false,
                'error'   => $taskData['error'] ?? 'not_found',
                'message' => $taskData['message'] ?? "Задача #{$taskId} не найдена",
            ];
        }

        $task = $taskData['task'];

        // 2. Результаты задачи
        $resultsData = $this->getTaskResults($taskId);
        $results = $resultsData['success'] ? ($resultsData['results'] ?? []) : [];

        // 3. Комментарии
        $commentsData = $this->getTaskComments($taskId, $onlyPublicComments);
        $comments = $commentsData['success'] ? ($commentsData['comments'] ?? []) : [];

        // 4. Встроенный чат задачи (если доступен chatId)
        $chatMessages = [];
        $chatId = $task['chatId'] ?? ($task['CHAT_ID'] ?? null);
        if (!empty($chatId)) {
            $chatData = $this->getTaskChatMessages((int)$chatId, 30);
            if ($chatData['success']) {
                $chatMessages = $chatData['messages'] ?? [];
            }
        }

        return [
            'success'      => true,
            'taskId'       => $taskId,
            'task'         => $task,
            'results'      => $results,
            'comments'     => $comments,
            'chatMessages' => $chatMessages,
        ];
    }

    /**
     * Формирование фильтра задач с префиксами UF_CRM_TASK.
     */
    private function buildCrmTaskFilter(array $filter): array
    {
        $b24Filter = [];

        // Обработка связей с CRM
        if (!empty($filter['lead_id'])) {
            $b24Filter['UF_CRM_TASK'] = 'L_' . (int)$filter['lead_id'];
        } elseif (!empty($filter['contact_id'])) {
            $b24Filter['UF_CRM_TASK'] = 'C_' . (int)$filter['contact_id'];
        } elseif (!empty($filter['company_id'])) {
            $b24Filter['UF_CRM_TASK'] = 'CO_' . (int)$filter['company_id'];
        } elseif (!empty($filter['deal_id'])) {
            $b24Filter['UF_CRM_TASK'] = 'D_' . (int)$filter['deal_id'];
        } elseif (!empty($filter['UF_CRM_TASK'])) {
            $b24Filter['UF_CRM_TASK'] = $filter['UF_CRM_TASK'];
        }

        if (!empty($filter['responsible_id'])) {
            $b24Filter['RESPONSIBLE_ID'] = (int)$filter['responsible_id'];
        }

        if (!empty($filter['group_id'])) {
            $b24Filter['GROUP_ID'] = (int)$filter['group_id'];
        }

        if (isset($filter['status']) && $filter['status'] !== '') {
            $b24Filter['REAL_STATUS'] = (int)$filter['status'];
        }

        if (!empty($filter['search_query'])) {
            $b24Filter['%TITLE'] = (string)$filter['search_query'];
        }

        return $b24Filter;
    }
}
