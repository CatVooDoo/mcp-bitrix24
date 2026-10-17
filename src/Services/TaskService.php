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

    /**
     * Создание задачи в строгом соответствии с 6 пунктами регламента компании:
     * 1. Название: "<Группа проектов> | <Смысл задачи> <Дата DD.MM.YYYY>"
     * 2. Описание: Краткий план выполнения и/или ссылки на алгоритмы
     * 3. Участники: RESPONSIBLE_ID (обяз.), ACCOMPLICES, AUDITORS
     * 4. Крайний срок: DEADLINE (обяз., кроме задач-мануалов)
     * 5. Проектная группа: GROUP_ID (обяз.)
     * 6. Учет времени: ALLOW_TIME_TRACKING = 'Y' + TIME_ESTIMATE (секунды, обяз.)
     */
    public function createTask(array $data): array
    {
        // 1. Формирование названия задачи по регламенту
        $title = $this->buildTaskTitle($data);
        if ($title === '') {
            return [
                'success' => false,
                'error'   => 'missing_title',
                'message' => 'Не указано название задачи. По регламенту название состоит из 3 частей: "<Группа проектов> | <Смысл задачи> <Дата DD.MM.YYYY>".',
            ];
        }

        // 3. Проверка ответственного
        $responsibleId = (int)($data['responsible_id'] ?? 0);
        if ($responsibleId <= 0) {
            return [
                'success' => false,
                'error'   => 'missing_responsible_id',
                'message' => 'Не указан ID ответственного сотрудника (responsible_id).',
            ];
        }

        // 5. Проектная группа (Обязательно!)
        $groupId = (int)($data['group_id'] ?? 0);
        if ($groupId <= 0 && !empty($data['project_group_name'])) {
            $groupId = $this->findGroupIdByName((string)$data['project_group_name']);
        }
        if ($groupId <= 0) {
            return [
                'success' => false,
                'error'   => 'missing_group_id',
                'message' => 'Обязательно необходимо выбрать проектную группу задачи (group_id). Если группа неизвестна, уточните у менеджера или воспользуйтесь поиском b24_get_workgroups.',
            ];
        }

        // 4. Крайний срок выполнения (DEADLINE)
        $isManual = !empty($data['is_manual']);
        $deadline = !empty($data['deadline']) ? trim((string)$data['deadline']) : '';
        if ($deadline === '' && !$isManual) {
            return [
                'success' => false,
                'error'   => 'missing_deadline',
                'message' => 'Согласно регламенту, необходимо указать крайний срок выполнения (deadline). Бессрочными могут быть только задачи-мануалы (укажите is_manual: true).',
            ];
        }

        // 6. Учет времени и предварительные трудозатраты (Обязательно!)
        $timeEstimateSeconds = $this->calculateTimeEstimateSeconds($data);
        if ($timeEstimateSeconds <= 0) {
            return [
                'success' => false,
                'error'   => 'missing_time_estimate',
                'message' => 'Согласно регламенту, обязательно указать предварительные временные трудозатраты (time_estimate_hours или time_estimate_minutes/seconds).',
            ];
        }

        // 2. Описание задачи и краткий план действий
        $description = $this->buildTaskDescription($data);
        if ($description === '') {
            return [
                'success' => false,
                'error'   => 'missing_description_or_plan',
                'message' => 'Согласно регламенту (пункт 2), описание задачи обязательно должно содержать краткий план действий по выполнению (plan_steps / description) и/или ссылки на алгоритмы (links).',
            ];
        }

        // Формирование полей tasks.task.add
        $fields = [
            'TITLE'               => $title,
            'DESCRIPTION'         => $description,
            'RESPONSIBLE_ID'      => $responsibleId,
            'GROUP_ID'            => $groupId,
            'ALLOW_TIME_TRACKING' => 'Y',
            'TIME_ESTIMATE'       => $timeEstimateSeconds,
        ];

        if ($deadline !== '') {
            $fields['DEADLINE'] = $deadline;
        }

        if (!empty($data['created_by'])) {
            $fields['CREATED_BY'] = (int)$data['created_by'];
        }

        if (!empty($data['accomplices']) && is_array($data['accomplices'])) {
            $fields['ACCOMPLICES'] = array_map('intval', $data['accomplices']);
        }

        if (!empty($data['auditors']) && is_array($data['auditors'])) {
            $fields['AUDITORS'] = array_map('intval', $data['auditors']);
        }

        // Привязки к CRM
        $crmBindings = $this->collectCrmBindings($data);
        if (!empty($crmBindings)) {
            $fields['UF_CRM_TASK'] = $crmBindings;
        }

        if (isset($data['priority'])) {
            $fields['PRIORITY'] = (int)$data['priority'];
        }

        Logger::info("Creating task according to регламент: {$title}", [
            'responsible_id' => $responsibleId,
            'group_id'       => $groupId,
            'time_estimate'  => $timeEstimateSeconds,
        ], 'tasks');

        $response = $this->client->call('tasks.task.add', ['fields' => $fields]);
        if (!$response['success']) {
            Logger::error("Failed to create task {$title}", [
                'error' => $response['error_description'] ?? $response['error'],
            ], 'tasks');

            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка при создании задачи в Битрикс24',
            ];
        }

        $taskId = (int)($response['result']['task']['id'] ?? ($response['result'] ?? 0));

        Logger::info("Task created successfully with ID {$taskId}", ['title' => $title], 'tasks');

        return [
            'success'        => true,
            'task_id'        => $taskId,
            'title'          => $title,
            'responsible_id' => $responsibleId,
            'group_id'       => $groupId,
            'time_estimate'  => $timeEstimateSeconds,
            'deadline'       => $deadline,
            'message'        => "Задача #{$taskId} успешно поставлена в соответствии с регламентом.",
        ];
    }

    /**
     * Поиск и получение проектных рабочих групп (sonet_group.get).
     */
    public function getWorkgroups(array $filter = [], int $limit = 50): array
    {
        $b24Filter = ['%ACTIVE' => 'Y'];
        if (!empty($filter['search_query'])) {
            $b24Filter['%NAME'] = (string)$filter['search_query'];
        }
        if (!empty($filter['id'])) {
            $b24Filter['ID'] = (int)$filter['id'];
        }

        $params = [
            'ORDER'  => ['NAME' => 'ASC'],
            'FILTER' => $b24Filter,
        ];

        $response = $this->client->call('sonet_group.get', $params);
        if (!$response['success']) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? 'api_error',
                'message' => $response['error_description'] ?? 'Ошибка получения рабочих групп',
                'groups'  => [],
            ];
        }

        $groups = $response['result'] ?? [];
        if ($limit > 0 && count($groups) > $limit) {
            $groups = array_slice($groups, 0, $limit);
        }

        return [
            'success' => true,
            'groups'  => $groups,
            'total'   => count($groups),
        ];
    }

    /**
     * Автопоиск ID группы по части названия.
     */
    public function findGroupIdByName(string $name): int
    {
        $res = $this->getWorkgroups(['search_query' => trim($name)], 10);
        if (!$res['success'] || empty($res['groups'])) {
            return 0;
        }

        $trimmed = mb_strtolower(trim($name));
        foreach ($res['groups'] as $group) {
            $groupName = mb_strtolower((string)($group['NAME'] ?? ''));
            if ($groupName === $trimmed || mb_strpos($groupName, $trimmed) !== false) {
                return (int)$group['ID'];
            }
        }

        return (int)($res['groups'][0]['ID'] ?? 0);
    }

    /**
     * Формирование названия задачи по регламенту:
     * "<Группа проектов> | <Смысл задачи> <Дата постановки DD.MM.YYYY>"
     */
    private function buildTaskTitle(array $data): string
    {
        $dateStr = !empty($data['task_date']) ? trim((string)$data['task_date']) : date('d.m.Y');
        $groupName = trim((string)($data['project_group_name'] ?? ''));
        $taskSummary = trim((string)($data['task_summary'] ?? ''));

        if ($groupName !== '' && $taskSummary !== '') {
            return "{$groupName} | {$taskSummary} {$dateStr}";
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return '';
        }

        // Если дата в формате DD.MM.YYYY еще не присутствует в конце названия, добавляем ее
        if (!preg_match('/\b\d{2}\.\d{2}\.\d{4}\b$/', $title)) {
            $title .= " {$dateStr}";
        }

        // Если нет разделителя группы и группа указана, добавляем
        if (!str_contains($title, '|') && $groupName !== '') {
            $title = "{$groupName} | {$title}";
        }

        return $title;
    }

    /**
     * Сборка описания с кратким планом действий (Пункт 2 регламента).
     */
    private function buildTaskDescription(array $data): string
    {
        $description = trim((string)($data['description'] ?? ''));
        $planSteps = (array)($data['plan_steps'] ?? []);

        if (!empty($planSteps)) {
            $planText = "\n\n### Краткий план действий:\n";
            foreach ($planSteps as $i => $step) {
                $num = $i + 1;
                $planText .= "{$num}. {$step}\n";
            }
            $description .= $planText;
        }

        if (!empty($data['links']) && is_array($data['links'])) {
            $linksText = "\n### Ссылки на алгоритмы и материалы:\n";
            foreach ($data['links'] as $link) {
                $linksText .= "- {$link}\n";
            }
            $description .= $linksText;
        }

        return $description;
    }

    /**
     * Расчет предварительных трудозатрат в секундах (Пункт 6 регламента).
     */
    private function calculateTimeEstimateSeconds(array $data): int
    {
        if (isset($data['time_estimate_seconds']) && (int)$data['time_estimate_seconds'] > 0) {
            return (int)$data['time_estimate_seconds'];
        }

        if (isset($data['time_estimate_hours']) && (float)$data['time_estimate_hours'] > 0) {
            return (int)round((float)$data['time_estimate_hours'] * 3600);
        }

        if (isset($data['time_estimate_minutes']) && (int)$data['time_estimate_minutes'] > 0) {
            return (int)$data['time_estimate_minutes'] * 60;
        }

        return 0;
    }

    /**
     * Сбор привязок к CRM.
     */
    private function collectCrmBindings(array $data): array
    {
        $bindings = (array)($data['crm_bindings'] ?? []);

        if (!empty($data['lead_id'])) {
            $bindings[] = 'L_' . (int)$data['lead_id'];
        }
        if (!empty($data['contact_id'])) {
            $bindings[] = 'C_' . (int)$data['contact_id'];
        }
        if (!empty($data['company_id'])) {
            $bindings[] = 'CO_' . (int)$data['company_id'];
        }
        if (!empty($data['deal_id'])) {
            $bindings[] = 'D_' . (int)$data['deal_id'];
        }

        return array_values(array_unique(array_filter($bindings)));
    }
}
