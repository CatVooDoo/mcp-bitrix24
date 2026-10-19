<?php

declare(strict_types=1);

/**
 * Автоматический фоновый автоответчик для Открытой линии 24 ("ТЕСТ AI АГЕНТА ЧАТ С КЛИЕНТАМИ").
 *
 * Требования:
 * 1. Максимально отвечает сам в открытой линии (публичные сообщения).
 * 2. Читает CRM строго в режиме Read-Only (Лиды, Сделки, Компании, Контакты, Задачи, Договоры).
 * 3. В скрытом чате (SYSTEM => 'Y') передает обращение и обязательно тегает ответственного:
 *    [USER=1650]Минаев Никита[/USER] (чтобы сразу приходило уведомление/колокольчик).
 * 4. Не переводит сразу на живого менеджера (не отключает бота), удерживает диалог.
 * 5. При необходимости уточняет детали у клиента либо ставит задачу по регламенту компании из 6 пунктов.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\ChatService;
use Bitrix24Mcp\Services\TaskService;
use Bitrix24Mcp\Services\CrmService;
use Bitrix24Mcp\Services\ContractService;

Bootstrap::init();

class Line24AutoResponder
{
    private const LINE_ID = '24';
    private const OPERATOR_USER_ID = 1650; // Минаев Никита
    private readonly string $stateFile;
    private array $state = [];

    public function __construct(
        private readonly ChatService $chatService = new ChatService(),
        private readonly TaskService $taskService = new TaskService(),
        private readonly CrmService $crmService = new CrmService(),
        private readonly ContractService $contractService = new ContractService()
    ) {
        $this->stateFile = dirname(__DIR__) . '/openclaw bitrix/logs/cache/autoresponder_line24_state.json';
        $this->loadState();
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $raw = @file_get_contents($this->stateFile);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $this->state = $decoded;
            }
        }
    }

    private function saveState(): void
    {
        @file_put_contents(
            $this->stateFile,
            json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Выполнить один цикл проверки и ответов.
     */
    public function processOnce(): int
    {
        $processedCount = 0;
        $dialogsRes = $this->chatService->getRecentDialogs(true, 50);
        if (!$dialogsRes['success'] || empty($dialogsRes['items'])) {
            return 0;
        }

        // Фильтруем только диалоги Открытой линии 24
        $targetDialogs = [];
        foreach ($dialogsRes['items'] as $item) {
            $entityId = (string)($item['chat']['entity_id'] ?? ($item['entity_id'] ?? ''));
            $title = (string)($item['title'] ?? ($item['chat']['name'] ?? ''));

            $isLine24 = false;
            if ($entityId !== '') {
                $parts = explode('|', $entityId);
                if (isset($parts[1]) && (string)$parts[1] === self::LINE_ID) {
                    $isLine24 = true;
                }
            }
            if (!$isLine24 && stripos($title, 'ТЕСТ AI АГЕНТА ЧАТ С КЛИЕНТАМИ') !== false) {
                $isLine24 = true;
            }

            if ($isLine24) {
                $targetDialogs[] = $item;
            }
        }

        foreach ($targetDialogs as $item) {
            $dialogId = (string)($item['id'] ?? '');
            $chatId = (int)($item['chat_id'] ?? 0);
            $effectiveDialogId = $dialogId !== '' ? $dialogId : ('chat' . $chatId);
            $title = (string)($item['title'] ?? ($item['chat']['name'] ?? $effectiveDialogId));

            if ($this->processDialog($effectiveDialogId, $chatId, $title, $item)) {
                $processedCount++;
            }
        }

        return $processedCount;
    }

    /**
     * Обработка отдельного диалога.
     */
    private function processDialog(string $dialogId, int $chatId, string $title, array $dialogMeta): bool
    {
        if ($chatId <= 0 && str_starts_with($dialogId, 'chat')) {
            $chatId = (int)substr($dialogId, 4);
        }

        // 1. Убеждаемся, что оператор подключен к чату (для отправки сообщений), но НЕ передаем сессию
        if ($chatId > 0) {
            $usersRes = \CRest::call('im.chat.user.list', ['CHAT_ID' => $chatId]);
            $usersList = is_array($usersRes['result'] ?? null) ? $usersRes['result'] : [];
            if (!in_array(self::OPERATOR_USER_ID, $usersList, true)) {
                \CRest::call('imopenlines.operator.answer', ['CHAT_ID' => $chatId]);
            }
        }

        // 2. Получаем историю последних сообщений
        $historyRes = $this->chatService->getHistory($dialogId, 25);
        if (!$historyRes['success'] || empty($historyRes['messages'])) {
            return false;
        }

        $messages = $historyRes['messages'];
        usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)); // Сортируем хронологически ASC

        // Ищем последнее сообщение от клиента
        $lastClientMessage = null;
        $ourLastReplyTime = 0;
        $ourLastReplyId = 0;

        foreach ($messages as $msg) {
            $authorId = (int)($msg['author_id'] ?? 0);
            $msgText = trim((string)($msg['text'] ?? ''));

            // Исключаем системные сообщения
            if ($authorId === self::OPERATOR_USER_ID) {
                // Если это системное внутреннее сообщение, не считаем его ответом клиенту
                if (!str_starts_with($msgText, '🤖 [Внутренняя')) {
                    $ourLastReplyTime = strtotime((string)($msg['date'] ?? ''));
                    $ourLastReplyId = (int)($msg['id'] ?? 0);
                }
            } elseif ($authorId > 0) {
                // Это клиент
                $lastClientMessage = $msg;
            }
        }

        if ($lastClientMessage === null) {
            return false;
        }

        $clientMsgId = (int)($lastClientMessage['id'] ?? 0);
        $clientMsgTime = strtotime((string)($lastClientMessage['date'] ?? ''));
        $clientText = trim((string)($lastClientMessage['text'] ?? ''));
        $clientAuthorId = (int)($lastClientMessage['author_id'] ?? 0);

        // Проверяем, отвечали ли мы уже на это сообщение
        $savedLastMsgId = (int)($this->state[$dialogId]['last_replied_msg_id'] ?? 0);
        if ($clientMsgId <= $savedLastMsgId) {
            return false;
        }

        if ($ourLastReplyTime > $clientMsgTime && $ourLastReplyId > $clientMsgId) {
            // Уже был публичный ответ после этого сообщения
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->saveState();
            return false;
        }

        if ($clientText === '') {
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->saveState();
            return false;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Новое входящее сообщение в {$dialogId} ({$title}): '{$clientText}'\n";

        // 3. Собираем контекст CRM (строго Read-Only)
        $crmContext = $this->fetchCrmReadOnlyContext($dialogId, $dialogMeta);

        // 4. Генерируем публичный ответ клиенту и внутреннюю сводку для скрытого чата
        $result = $this->handleClientMessage($dialogId, $clientText, $clientAuthorId, $crmContext);

        $publicReply = $result['public_reply'];
        $internalNote = $result['internal_note'];

        // 5. Отправка скрытой заметки в чат с тегом ответственного (SYSTEM => 'Y')
        // Тег ответственного обязателен, чтобы сотруднику пришел пуш/колокольчик
        $hiddenRes = $this->chatService->sendReply($dialogId, $internalNote, [
            'SYSTEM' => 'Y',
        ]);
        if ($hiddenRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Отправлена скрытая сводка оператору в {$dialogId} с тегом [USER=" . self::OPERATOR_USER_ID . "]\n";
        }

        // 6. Отправка публичного ответа клиенту в открытую линию
        $sendRes = $this->chatService->sendReply($dialogId, $publicReply);
        if ($sendRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Успешно отправлен публичный ответ клиенту в {$dialogId} (msg_id: " . ($sendRes['message_id'] ?? '') . ")\n";
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->state[$dialogId]['last_reply_at'] = date('c');
            $this->saveState();
            return true;
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Ошибка отправки ответа клиенту в {$dialogId}: " . ($sendRes['message'] ?? 'Unknown error') . "\n";
            return false;
        }
    }

    /**
     * Сбор контекста CRM строго в режиме Read-Only.
     */
    private function fetchCrmReadOnlyContext(string $dialogId, array $dialogMeta): array
    {
        $dossierRes = $this->crmService->getClientDossier(['chat_id' => $dialogId]);
        $dossier = $dossierRes['dossier'] ?? [];

        $contact = $dossier['contact'] ?? null;
        $company = $dossier['company'] ?? null;
        $lead = $dossier['lead'] ?? null;
        $tasks = $dossier['tasks'] ?? [];
        $deals = $dossier['deals'] ?? [];
        $contracts = $dossier['contracts'] ?? [];

        $clientName = '';
        if (!empty($contact)) {
            $clientName = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
        } elseif (!empty($lead)) {
            $clientName = trim(($lead['NAME'] ?? '') . ' ' . ($lead['LAST_NAME'] ?? '')) ?: ($lead['TITLE'] ?? '');
        }

        $companyTitle = !empty($company) ? ($company['TITLE'] ?? '') : '';

        // Определяем ответственного менеджера
        $assignedById = (int)($contact['ASSIGNED_BY_ID'] ?? ($company['ASSIGNED_BY_ID'] ?? ($lead['ASSIGNED_BY_ID'] ?? self::OPERATOR_USER_ID)));
        if ($assignedById <= 0) {
            $assignedById = self::OPERATOR_USER_ID;
        }

        return [
            'client_name'    => $clientName ?: 'Клиент',
            'company_title'  => $companyTitle,
            'assigned_by_id' => $assignedById,
            'contact_id'     => (int)($contact['ID'] ?? 0),
            'company_id'     => (int)($company['ID'] ?? 0),
            'lead_id'        => (int)($lead['ID'] ?? 0),
            'tasks'          => $tasks,
            'deals'          => $deals,
            'contracts'      => $contracts,
        ];
    }

    /**
     * Анализ сообщения, поиск по CRM, формирование публичного ответа и скрытой сводки.
     */
    private function handleClientMessage(string $dialogId, string $text, int $authorId, array $crmContext): array
    {
        $lower = mb_strtolower($text);
        $clientName = $crmContext['client_name'];
        $companyTitle = $crmContext['company_title'];
        $assignedById = $crmContext['assigned_by_id'];

        // Формируем тег ответственного для скрытого чата
        $responsibleTag = "[USER=" . self::OPERATOR_USER_ID . "]Минаев Никита[/USER]";
        if ($assignedById > 0 && $assignedById !== self::OPERATOR_USER_ID) {
            $responsibleTag .= " и [USER={$assignedById}]Ответственный менеджер[/USER]";
        }

        // 1. Проверяем вопросы о статусе уже существующих задач или проектов
        $isStatusQuestion = (
            str_contains($lower, 'статус') ||
            str_contains($lower, 'что с задач') ||
            str_contains($lower, 'как дела') ||
            str_contains($lower, 'когда будет') ||
            str_contains($lower, 'проверили') ||
            str_contains($lower, 'сделали') ||
            str_contains($lower, 'какой результат')
        );

        if ($isStatusQuestion) {
            // Читаем задачи клиента из CRM
            $recentTasks = $crmContext['tasks'];
            if (empty($recentTasks)) {
                $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 10);
                $recentTasks = $tasksRes['tasks'] ?? [];
            }

            if (!empty($recentTasks)) {
                $matchedTask = null;
                foreach ($recentTasks as $t) {
                    $tTitle = mb_strtolower((string)($t['TITLE'] ?? ($t['title'] ?? '')));
                    if (str_contains($lower, 'слайдер') && str_contains($tTitle, 'слайдер')) {
                        $matchedTask = $t;
                        break;
                    }
                    if (str_contains($lower, 'телефон') && str_contains($tTitle, 'телефон')) {
                        $matchedTask = $t;
                        break;
                    }
                }
                if ($matchedTask === null) {
                    $matchedTask = $recentTasks[0]; // Берем последнюю активную задачу
                }

                $tId = $matchedTask['ID'] ?? ($matchedTask['id'] ?? '');
                $tTitle = $matchedTask['TITLE'] ?? ($matchedTask['title'] ?? '');
                $tStatus = $matchedTask['statusText'] ?? 'В работе';
                $tDeadline = !empty($matchedTask['DEADLINE']) ? date('d.m.Y H:i', strtotime($matchedTask['DEADLINE'])) : 'Уточняется';

                $publicReply = "Здравствуйте, {$clientName}!\n\n"
                    . "По вашей задаче №{$tId} («{$tTitle}»):\n"
                    . "• Текущий статус: {$tStatus}\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Крайний срок: {$tDeadline}\n\n"
                    . "Задача находится на контроле специалистов. Как только появятся результаты или обновления, я сразу напишу вам сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . " интересуется статусом задачи.\n"
                    . "• Вопрос клиента: '{$text}'\n"
                    . "• Ответ ИИ: предоставил актуальный статус задачи №{$tId} ({$tStatus}).\n"
                    . "(Агент самостоятельно продолжает сопровождение в чате, перевод на менеджера не требуется).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // 2. Определение проекта и проверка запроса на задачу / баг / доработку
        $project = $this->detectProject($lower, $companyTitle);

        $isTaskRequest = (
            str_contains($lower, 'исправ') ||
            str_contains($lower, 'не работа') ||
            str_contains($lower, 'почини') ||
            str_contains($lower, 'сделай') ||
            str_contains($lower, 'провер') ||
            str_contains($lower, 'настро') ||
            str_contains($lower, 'ошибк') ||
            str_contains($lower, 'слайдер') ||
            str_contains($lower, 'телефон') ||
            str_contains($lower, 'проблем') ||
            str_contains($lower, 'добав') ||
            str_contains($lower, 'нужно') ||
            str_contains($lower, 'просьба')
        );

        if ($isTaskRequest) {
            // Проверяем полноту информации для постановки задачи
            $hasEnoughDetails = (mb_strlen($text) > 30) || ($project !== null && (str_contains($lower, 'слайдер') || str_contains($lower, 'телефон') || str_contains($lower, 'страниц')));

            if (!$hasEnoughDetails) {
                // Если деталей мало, вежливо уточняем у клиента подробности
                $publicReply = "Здравствуйте, {$clientName}! Подскажите, пожалуйста, подробнее:\n"
                    . "1. На каком именно сайте/странице возникла сложность?\n"
                    . "2. Что конкретно работает некорректно и какой результат ожидается?\n"
                    . "3. Если есть возможность, пришлите ссылку или скриншот ошибки.\n\n"
                    . "Я соберу все технические детали и сразу сформирую задачу ответственным специалистам.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . " обратился с запросом: '{$text}'.\n"
                    . "• Действие ИИ: запрос деталей у клиента для корректной постановки задачи по регламенту.\n"
                    . "(Агент собирает требования в чате, перевод на менеджера пока не требуется).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Проверяем, нет ли уже созданной активной задачи в CRM
            $existingTask = $this->findRecentTask($project['group_name'] ?? 'WAYDEV.RU', $text);
            if ($existingTask !== null) {
                $eId = $existingTask['ID'] ?? ($existingTask['id'] ?? '');
                $eTitle = $existingTask['TITLE'] ?? ($existingTask['title'] ?? '');
                $eStatus = $existingTask['statusText'] ?? 'В работе';
                $eDeadline = !empty($existingTask['DEADLINE']) ? date('d.m.Y H:i', strtotime($existingTask['DEADLINE'])) : 'Уточняется';

                $publicReply = "Добрый день, {$clientName}! Задача по этому обращению уже зафиксирована и находится в работе:\n\n"
                    . "• Задача №{$eId}: «{$eTitle}»\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Статус: {$eStatus}\n"
                    . "• Крайний срок: {$eDeadline}\n\n"
                    . "Специалисты уже занимаются вопросом. Если появились новые данные, файлы или примеры — присылайте прямо сюда, я прикреплю их к задаче.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Повторный запрос клиента {$clientName}: '{$text}'.\n"
                    . "• Задача уже существует: №{$eId} («{$eTitle}», статус: {$eStatus}).\n"
                    . "• Клиент проинформирован о ходе работ.\n"
                    . "(Агент продолжает вести диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Ставим новую задачу по 6 пунктам корпоративного регламента компании
            $taskDate = date('d.m.Y');
            $deadline = date('Y-m-d 18:00:00', strtotime('+1 weekday'));
            $taskSummary = $this->summarizeTask($text, $project['name'] ?? 'WAYDEV');

            $taskData = [
                'project_group_name'  => $project['group_name'] ?? 'WAYDEV.RU',
                'task_summary'        => $taskSummary,
                'task_date'           => $taskDate,
                'tags'                => ['ИИ-Агент'],
                'plan_steps'          => [
                    "Провести первичную диагностику обращения клиента {$clientName}",
                    "Локализовать причину сбоя на проекте {$project['name']}",
                    "Выполнить исправления и провести проверку",
                    "Уведомить клиента в чате о готовности",
                ],
                'links'               => [
                    "https://{$project['domain']}",
                ],
                'checklist'           => [
                    "Анализ логов и воспроизведение обращения",
                    "Внесение правок по регламенту",
                    "Финальное тестирование и сдача",
                ],
                'responsible_id'      => self::OPERATOR_USER_ID,
                'deadline'            => $deadline,
                'time_estimate_hours' => 2.0,
            ];

            if ($crmContext['contact_id'] > 0) {
                $taskData['contact_id'] = $crmContext['contact_id'];
            }
            if ($crmContext['company_id'] > 0) {
                $taskData['company_id'] = $crmContext['company_id'];
            }
            if ($crmContext['lead_id'] > 0) {
                $taskData['lead_id'] = $crmContext['lead_id'];
            }

            $createRes = $this->taskService->createTask($taskData);
            if ($createRes['success'] && !empty($createRes['taskId'])) {
                $taskId = $createRes['taskId'];

                $publicReply = "Добрый день, {$clientName}! Ваше обращение зафиксировано и поставлено в работу специалистам.\n\n"
                    . "• Задача №{$taskId}: «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Проект: {$project['group_name']} ({$project['domain']})\n"
                    . "• Крайний срок: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n"
                    . "• Чек-лист диагностических работ сформирован\n\n"
                    . "Мы проведем диагностику и выполним необходимые работы. Если появятся дополнительные файлы или уточнения — направляйте в этот чат, я добавлю в задачу.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Поступило обращение от {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . ": '{$text}'\n"
                    . "• Сформирована задача №{$taskId}: «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• Ответственный: Минаев Никита, дедлайн: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n"
                    . "• Клиенту предоставлен подтверждающий ответ со сроками.\n"
                    . "(Агент продолжает самостоятельно сопровождать диалог в чате).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // 3. Общие вопросы, приветствия или консультации
        $isGreeting = str_contains($lower, 'привет') || str_contains($lower, 'здравствуй') || str_contains($lower, 'добрый день') || str_contains($lower, 'добрый вечер');
        if ($isGreeting) {
            $publicReply = "Здравствуйте, {$clientName}! Я ИИ-ассистент компании WAYDEV.\n"
                . "Чем могу помочь по вашему проекту? Опишите, пожалуйста, вопрос или задачу — я найду информацию в системе либо поставлю задачу специалистам.";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Новое обращение/приветствие в открытой линии от {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . ".\n"
                . "• Текст клиента: '{$text}'\n"
                . "• ИИ поприветствовал клиента и запросил суть вопроса.\n"
                . "(Агент ведет диалог).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // 4. Прочее сообщение
        $publicReply = "Спасибо за информацию, {$clientName}! Ваше сообщение принято в обработку. Я зафиксировал обращение и передал информацию ответственным специалистам. Если потребуется что-то уточнить — напишу вам здесь.";

        $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
            . "Внимание, {$responsibleTag}!\n"
            . "• Сообщение от клиента {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . ": '{$text}'\n"
            . "• Клиент уведомлен о приеме сообщения в обработку.\n"
            . "(Агент на связи в диалоге).";

        return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
    }

    private function detectProject(string $lower, string $companyTitle = ''): ?array
    {
        $projects = [
            'kuzbank' => [
                'name'       => 'Банк Кузнецкий',
                'group_name' => 'KUZBANK.RU',
                'domain'     => 'kuzbank.ru',
            ],
            'мастер воды' => [
                'name'       => 'Мастер Воды',
                'group_name' => 'MASTERVODI.RU',
                'domain'     => 'mastervodi.ru',
            ],
            'mastervodi' => [
                'name'       => 'Мастер Воды',
                'group_name' => 'MASTERVODI.RU',
                'domain'     => 'mastervodi.ru',
            ],
            'geotek' => [
                'name'       => 'НПП Геотек',
                'group_name' => 'NPP-GEOTEK.COM',
                'domain'     => 'npp-geotek.ru',
            ],
            'penzaoblgaz' => [
                'name'       => 'Пензаоблгаз',
                'group_name' => 'WAYDEV.RU',
                'domain'     => 'penzaoblgaz.ru',
            ],
            'fillini' => [
                'name'       => 'Филлини',
                'group_name' => 'FILLINI.RU',
                'domain'     => 'fillini.ru',
            ],
            'lubidom' => [
                'name'       => 'Любимый Дом',
                'group_name' => 'LUBIDOM.RU',
                'domain'     => 'lubidom.ru',
            ],
            'yasenevo' => [
                'name'       => 'Ясенево',
                'group_name' => 'YASENEVO.HOMES',
                'domain'     => 'yasenevo.homes',
            ],
        ];

        $combined = $lower . ' ' . mb_strtolower($companyTitle);
        foreach ($projects as $key => $proj) {
            if (str_contains($combined, $key)) {
                return $proj;
            }
        }

        return [
            'name'       => 'WAYDEV',
            'group_name' => 'WAYDEV.RU',
            'domain'     => 'waydev.ru',
        ];
    }

    private function summarizeTask(string $text, string $projectName): string
    {
        $clean = preg_replace('/^(добрый\s+(день|вечер|утро)|здравствуйте|привет)[,\.\s]*/iu', '', $text);
        $clean = trim($clean);
        if ($clean === '') {
            return "Обращение клиента по проекту {$projectName}";
        }
        $words = mb_substr($clean, 0, 70);
        return ucfirst($words);
    }

    private function findRecentTask(string $groupName, string $text): ?array
    {
        $res = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 15);
        if (!$res['success'] || empty($res['tasks'])) {
            return null;
        }

        $lowerText = mb_strtolower($text);
        foreach ($res['tasks'] as $task) {
            $title = (string)($task['title'] ?? ($task['TITLE'] ?? ''));
            $status = (int)($task['status'] ?? ($task['STATUS'] ?? 0));
            // Если задача не закрыта (не статус 5)
            if ($status !== 5) {
                if (str_contains(mb_strtolower($title), mb_strtolower($groupName))) {
                    if (str_contains($lowerText, 'слайдер') && str_contains(mb_strtolower($title), 'слайдер')) {
                        return $task;
                    }
                    if (str_contains($lowerText, 'телефон') && str_contains(mb_strtolower($title), 'телефон')) {
                        return $task;
                    }
                }
            }
        }

        return null;
    }
}

// Запуск в зависимости от аргументов
$isDaemon = in_array('--daemon', $argv ?? [], true);
$responder = new Line24AutoResponder();

echo "=== Запуск автоответчика для Открытой линии 24 (ТЕСТ AI АГЕНТА ЧАТ С КЛИЕНТАМИ) ===\n";
if ($isDaemon) {
    echo "Режим: ДЕМОН (интервал опроса: 20 сек). Нажмите Ctrl+C для остановки.\n";
    while (true) {
        try {
            $count = $responder->processOnce();
            if ($count > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Обработано диалогов: {$count}\n";
            }
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Исключение: " . $e->getMessage() . "\n";
        }
        sleep(20);
    }
} else {
    echo "Режим: ОДИНОЧНЫЙ ПРОХОД\n";
    $count = $responder->processOnce();
    echo "[" . date('Y-m-d H:i:s') . "] Завершено. Обработано диалогов: {$count}\n";
}
