<?php

declare(strict_types=1);

/**
 * Фоновый автоответчик для Открытой линии 18 ("Открытая Линия Telegram (Wazzup)").
 *
 * Регламент и правила:
 * 1. Опрос каждые 30 секунд.
 * 2. Ответ ТОЛЬКО пользователю KelvinMarty (автор 1712 или ник KelvinMarty).
 * 3. Перед ответом ОБЯЗАТЕЛЬНО проверяет: клиент это или нет (CRM Contact / Company / Lead).
 * 4. Если НЕ клиент (не привязан к CRM): вежливо уточняет у него компанию, проект, имя и суть задачи.
 * 5. В скрытом чате (SYSTEM => 'Y') отправляет сводку с обязательным тегом:
 *    [USER=1650]Минаев Никита[/USER] (для колокольчика/пуша).
 * 6. Автономная поддержка: удерживает диалог, при запросе задачи проверяет существующие или ставит новую по регламенту из 6 пунктов.
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\ChatService;
use Bitrix24Mcp\Services\TaskService;
use Bitrix24Mcp\Services\CrmService;
use Bitrix24Mcp\Services\ContractService;

Bootstrap::init();

class Line18AutoResponder
{
    private const LINE_ID = '18';
    private const TARGET_USER_SUBSTRING = 'kelvinmarty';
    private const TARGET_USER_ID = 1712;
    private const OPERATOR_USER_ID = 1650; // Минаев Никита

    private readonly string $stateFile;
    private array $state = [];

    public function __construct(
        private readonly ChatService $chatService = new ChatService(),
        private readonly TaskService $taskService = new TaskService(),
        private readonly CrmService $crmService = new CrmService(),
        private readonly ContractService $contractService = new ContractService()
    ) {
        $this->stateFile = __DIR__ . '/logs/cache/autoresponder_line18_state.json';
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

        // Если для chat17478 еще нет записи, инициализируем последним старым сообщением (2194442), чтобы не спамить в историю
        if (!isset($this->state['chat17478'])) {
            $this->state['chat17478'] = [
                'last_replied_msg_id' => 2194442,
                'last_seen_at'        => date('c'),
            ];
            $this->saveState();
        }
    }

    private function saveState(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(
            $this->stateFile,
            json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Один цикл проверки Открытой линии 18.
     */
    public function processOnce(): int
    {
        $processedCount = 0;
        $dialogsRes = $this->chatService->getRecentDialogs(true, 50);
        if (!$dialogsRes['success'] || empty($dialogsRes['items'])) {
            return 0;
        }

        foreach ($dialogsRes['items'] as $item) {
            $entityId = (string)($item['chat']['entity_id'] ?? ($item['entity_id'] ?? ''));
            $title = (string)($item['title'] ?? ($item['chat']['name'] ?? ''));
            $dialogId = (string)($item['id'] ?? '');
            $chatId = (int)($item['chat_id'] ?? 0);
            $effectiveDialogId = $dialogId !== '' ? $dialogId : ('chat' . $chatId);

            // 1. Проверяем, относится ли диалог к Линии 18
            $isLine18 = false;
            if ($entityId !== '') {
                $parts = explode('|', $entityId);
                if (isset($parts[1]) && (string)$parts[1] === self::LINE_ID) {
                    $isLine18 = true;
                }
            }
            if (!$isLine18 && stripos($title, 'Открытая Линия Telegram (Wazzup)') !== false) {
                $isLine18 = true;
            }

            if (!$isLine18) {
                continue;
            }

            // 2. Фильтр: диалог ТОЛЬКО с пользователем KelvinMarty
            $isTargetUser = false;
            if (stripos($title, self::TARGET_USER_SUBSTRING) !== false) {
                $isTargetUser = true;
            }
            $chatUser = $item['user'] ?? [];
            $userName = trim(($chatUser['name'] ?? '') . ' ' . ($chatUser['last_name'] ?? ''));
            if (stripos($userName, self::TARGET_USER_SUBSTRING) !== false) {
                $isTargetUser = true;
            }
            if ((int)($chatUser['id'] ?? 0) === self::TARGET_USER_ID) {
                $isTargetUser = true;
            }

            if (!$isTargetUser) {
                continue;
            }

            // Обрабатываем диалог
            if ($this->processDialog($effectiveDialogId, $chatId, $title, $item)) {
                $processedCount++;
            }
        }

        return $processedCount;
    }

    /**
     * Обработка диалога пользователя KelvinMarty.
     */
    private function processDialog(string $dialogId, int $chatId, string $title, array $dialogMeta): bool
    {
        if ($chatId <= 0 && str_starts_with($dialogId, 'chat')) {
            $chatId = (int)substr($dialogId, 4);
        }

        // Подключаем оператора к чату при необходимости
        if ($chatId > 0) {
            $usersRes = \CRest::call('im.chat.user.list', ['CHAT_ID' => $chatId]);
            $usersList = is_array($usersRes['result'] ?? null) ? $usersRes['result'] : [];
            if (!in_array(self::OPERATOR_USER_ID, $usersList, true)) {
                \CRest::call('imopenlines.operator.answer', ['CHAT_ID' => $chatId]);
            }
        }

        // Получаем историю сообщений
        $historyRes = $this->chatService->getHistory($dialogId, 20);
        if (!$historyRes['success'] || empty($historyRes['messages'])) {
            return false;
        }

        $messages = $historyRes['messages'];
        usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));

        // Ищем последнее входящее сообщение от клиента (KelvinMarty)
        $lastClientMessage = null;
        $ourLastReplyTime = 0;
        $ourLastReplyId = 0;

        foreach ($messages as $msg) {
            $authorId = (int)($msg['author_id'] ?? 0);
            $msgText = trim((string)($msg['text'] ?? ''));

            if ($authorId === self::OPERATOR_USER_ID) {
                if (!str_starts_with($msgText, '🤖 [Внутренняя')) {
                    $ourLastReplyTime = strtotime((string)($msg['date'] ?? ''));
                    $ourLastReplyId = (int)($msg['id'] ?? 0);
                }
            } elseif ($authorId === self::TARGET_USER_ID || $authorId > 0) {
                // Сообщение от клиента KelvinMarty
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
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->saveState();
            return false;
        }

        if ($clientText === '') {
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->saveState();
            return false;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Новое входящее от KelvinMarty в {$dialogId}: '{$clientText}'\n";

        // 3. ПРОВЕРКА КЛИЕНТА В CRM ПЕРЕД ОТВЕТОМ (Правило пользователя)
        $crmContext = $this->checkClientInCrm($dialogId, $clientAuthorId, $clientText);

        // 4. Формируем публичный ответ и скрытую сводку оператору
        $result = $this->generateResponse($dialogId, $clientText, $clientAuthorId, $crmContext);

        $publicReply = $result['public_reply'];
        $internalNote = $result['internal_note'];

        // 5. Отправка скрытой заметки оператору с тегом [USER=1650]
        $hiddenRes = $this->chatService->sendReply($dialogId, $internalNote, [
            'SYSTEM' => 'Y',
        ]);
        if ($hiddenRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Отправлена скрытая сводка с тегом [USER=" . self::OPERATOR_USER_ID . "]\n";
        }

        // 6. Отправка публичного ответа KelvinMarty
        $sendRes = $this->chatService->sendReply($dialogId, $publicReply);
        if ($sendRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Успешно отправлен ответ KelvinMarty в {$dialogId}\n";
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->state[$dialogId]['last_reply_at'] = date('c');
            $this->saveState();
            return true;
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Ошибка отправки ответа: " . ($sendRes['message'] ?? 'Unknown') . "\n";
            return false;
        }
    }

    /**
     * Обязательная проверка: клиент это или нет в CRM.
     */
    private function checkClientInCrm(string $dialogId, int $authorId, string $messageText): array
    {
        // 1. Поиск через досье диалога (crm bindings)
        $dossierRes = $this->crmService->getClientDossier(['chat_id' => $dialogId]);
        $dossier = $dossierRes['dossier'] ?? [];

        $contact = $dossier['contact'] ?? null;
        $company = $dossier['company'] ?? null;
        $lead = $dossier['lead'] ?? null;
        $tasks = $dossier['tasks'] ?? [];
        $deals = $dossier['deals'] ?? [];
        $contracts = $dossier['contracts'] ?? [];

        // 2. Если по chat_id не привязан, пробуем найти по имени 'KelvinMarty'
        if (empty($contact) && empty($company) && empty($lead)) {
            $contactsRes = $this->crmService->getContacts(['search_query' => 'KelvinMarty'], 1);
            if (!empty($contactsRes['contacts'][0])) {
                $contact = $contactsRes['contacts'][0];
            }
        }

        $isClient = (!empty($contact) || !empty($company) || !empty($lead));

        $clientName = 'KelvinMarty';
        if (!empty($contact)) {
            $cName = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
            if ($cName !== '') {
                $clientName = $cName;
            }
        }

        $companyTitle = !empty($company) ? ($company['TITLE'] ?? '') : '';
        $assignedById = (int)($contact['ASSIGNED_BY_ID'] ?? ($company['ASSIGNED_BY_ID'] ?? ($lead['ASSIGNED_BY_ID'] ?? self::OPERATOR_USER_ID)));
        if ($assignedById <= 0) {
            $assignedById = self::OPERATOR_USER_ID;
        }

        return [
            'is_client'      => $isClient,
            'client_name'    => $clientName,
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
     * Генерация ответа с учетом статуса клиента (Клиент / Не клиент).
     */
    private function generateResponse(string $dialogId, string $text, int $authorId, array $crmContext): array
    {
        $lower = mb_strtolower($text);
        $isClient = $crmContext['is_client'];
        $clientName = $crmContext['client_name'];
        $companyTitle = $crmContext['company_title'];
        $assignedById = $crmContext['assigned_by_id'];

        $responsibleTag = "[USER=" . self::OPERATOR_USER_ID . "]Минаев Никита[/USER]";
        if ($assignedById > 0 && $assignedById !== self::OPERATOR_USER_ID) {
            $responsibleTag .= " и [USER={$assignedById}]Ответственный менеджер[/USER]";
        }

        // ----------------------------------------------------
        // СЦЕНАРИЙ 1: Пользователь НЕ ПРИВЯЗАН к CRM (не клиент)
        // Регламент: вежливо уточняем компанию, проект, имя и детали
        // ----------------------------------------------------
        if (!$isClient) {
            // Проверяем, упоминается ли известный проект в тексте
            $project = $this->detectProject($lower, '');

            if ($project !== null && $project['name'] !== 'WAYDEV') {
                // Проект назван в сообщении
                $publicReply = "Здравствуйте, {$clientName}! Я ИИ-ассистент компании WAYDEV.\n\n"
                    . "Вижу ваше обращение по проекту «{$project['name']}». При этом в нашей системе ваш профиль Telegram пока не привязан к карточке клиента.\n\n"
                    . "Подскажите, пожалуйста:\n"
                    . "1. Как ваши полные имя и фамилия (и должность)?\n"
                    . "2. Какой конкретно вопрос или доработку требуется решить по проекту?\n\n"
                    . "Я свяжу ваш контакт с карточкой проекта и сразу передам информацию ответственным специалистам.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Обращение от KelvinMarty (Telegram, Линия 18)\n"
                    . "• Статус в CRM: [b][НЕ_КЛИЕНТ / НОВЫЙ_КОНТАКТ][/b]\n"
                    . "• Упомянутый проект: {$project['name']} ({$project['domain']})\n"
                    . "• Текст сообщения: '{$text}'\n"
                    . "• Действие ИИ: запрос ФИО и должности для привязки контакта к проекту.\n"
                    . "(Агент продолжает самостоятельно сопровождать диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Общее сообщение / вопрос от неопознанного пользователя
            $publicReply = "Здравствуйте, {$clientName}! Я ИИ-ассистент службы технической поддержки WAYDEV.\n\n"
                . "В нашей CRM-системе ваш контакт в Telegram пока не привязан к действующему договору или карточке клиента. Чтобы мы могли оперативно помочь, уточните, пожалуйста:\n\n"
                . "1. Какую компанию или проект вы представляете?\n"
                . "2. Как к вам обращаться (ваше имя)?\n"
                . "3. Опишите ваш вопрос или задачу (какой сайт/сервис, что требуется сделать).\n\n"
                . "Я зафиксирую обращение и подключу проектную команду.";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Обращение от KelvinMarty (Telegram, Линия 18)\n"
                . "• Статус в CRM: [b][НЕ_КЛИЕНТ / НОВЫЙ_КОНТАКТ][/b]\n"
                . "• Текст сообщения: '{$text}'\n"
                . "• Действие ИИ: вежливый опрос пользователя для идентификации клиента (компания, проект, имя, суть задачи).\n"
                . "(Агент ведет диалог в открытой линии).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // ----------------------------------------------------
        // СЦЕНАРИЙ 2: Пользователь НАЙДЕН в CRM (действующий клиент)
        // ----------------------------------------------------

        // 2.1 Вопрос о статусе задачи
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
            $recentTasks = $crmContext['tasks'];
            if (empty($recentTasks)) {
                $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 10);
                $recentTasks = $tasksRes['tasks'] ?? [];
            }

            if (!empty($recentTasks)) {
                $matchedTask = $recentTasks[0];
                $tId = $matchedTask['ID'] ?? ($matchedTask['id'] ?? '');
                $tTitle = $matchedTask['TITLE'] ?? ($matchedTask['title'] ?? '');
                $tStatus = $matchedTask['statusText'] ?? 'В работе';
                $tDeadline = !empty($matchedTask['DEADLINE']) ? date('d.m.Y H:i', strtotime($matchedTask['DEADLINE'])) : 'Уточняется';

                $publicReply = "Здравствуйте, {$clientName}!\n\n"
                    . "По вашей задаче №{$tId} («{$tTitle}»):\n"
                    . "• Текущий статус: {$tStatus}\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Крайний срок: {$tDeadline}\n\n"
                    . "Задача в работе у специалистов. Как только будут новости или релиз, я сразу напишу вам сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент {$clientName} ({$companyTitle}) запросил статус задачи.\n"
                    . "• Вопрос: '{$text}'\n"
                    . "• Ответ ИИ: предоставлен статус задачи №{$tId} ({$tStatus}).\n"
                    . "(Агент сопровождает диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // 2.2 Запрос на задачу / исправление бага
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
            str_contains($lower, 'добав') ||
            str_contains($lower, 'нужно') ||
            str_contains($lower, 'просьба')
        );

        $project = $this->detectProject($lower, $companyTitle);

        if ($isTaskRequest) {
            $hasEnoughDetails = mb_strlen($text) > 30;
            if (!$hasEnoughDetails) {
                $publicReply = "Здравствуйте, {$clientName}! Подскажите подробнее для постановки задачи специалистам:\n"
                    . "1. На какой странице/сайте возникла проблема?\n"
                    . "2. Что именно работает некорректно и какой результат ожидается?\n"
                    . "3. Пришлите, пожалуйста, скриншот или ссылку на ошибку.\n\n"
                    . "Я соберу данные и сразу поставлю задачу в технический отдел.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент {$clientName} ({$companyTitle}) сообщил о проблеме: '{$text}'.\n"
                    . "• Действие ИИ: уточнение деталей для постановки задачи по регламенту.\n"
                    . "(Агент в диалоге).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Постановка новой задачи по 6 пунктам корпоративного регламента
            $taskDate = date('d.m.Y');
            $deadline = date('Y-m-d 18:00:00', strtotime('+1 weekday'));
            $taskSummary = $this->summarizeTask($text, $project['name']);

            $taskData = [
                'project_group_name'  => $project['group_name'],
                'task_summary'        => $taskSummary,
                'task_date'           => $taskDate,
                'plan_steps'          => [
                    "Провести диагностику по обращению клиента {$clientName}",
                    "Локализовать проблему на проекте {$project['name']}",
                    "Выполнить доработки и провести тестирование",
                    "Уведомить клиента в чате о завершении",
                ],
                'links'               => [
                    "https://{$project['domain']}",
                ],
                'checklist'           => [
                    "Проверка логов и воспроизведение",
                    "Исправление и код-ревью",
                    "Тестирование на проде и сдача клиенту",
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

            $createRes = $this->taskService->createTask($taskData);
            if ($createRes['success'] && !empty($createRes['taskId'])) {
                $taskId = $createRes['taskId'];
                $publicReply = "Добрый день, {$clientName}! Ваше обращение зафиксировано и поставлено в работу.\n\n"
                    . "• Задача №{$taskId}: «{$project['group_name']} | {$taskSummary} {$taskDate}»\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Крайний срок: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n\n"
                    . "Специалисты приступают к выполнению. Если появятся дополнительные файлы или пояснения — направляйте прямо сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Создана задача №{$taskId}: «{$project['group_name']} | {$taskSummary} {$taskDate}»\n"
                    . "• Клиент: {$clientName} ({$companyTitle})\n"
                    . "• Ответственный: Минаев Никита, дедлайн: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n"
                    . "(Агент продолжает сопровождать диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // 2.3 Приветствие от действующего клиента
        $isGreeting = str_contains($lower, 'привет') || str_contains($lower, 'здравствуй') || str_contains($lower, 'добрый день') || str_contains($lower, 'добрый вечер');
        if ($isGreeting) {
            $publicReply = "Здравствуйте, {$clientName}! Рад приветствовать вас. Чем могу помочь по проектам компании «{$companyTitle}» сегодня?";
            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Клиент {$clientName} ({$companyTitle}) поприветствовал в чате: '{$text}'.\n"
                . "• Агент ответил приветствием и запросил суть вопроса.";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // 2.4 Прочее сообщение
        $publicReply = "Спасибо за информацию, {$clientName}! Сообщение принято в обработку. Я передал информацию ответственным специалистам. Если потребуется что-то уточнить — я сразу напишу вам здесь.";
        $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
            . "Внимание, {$responsibleTag}!\n"
            . "• Сообщение от клиента {$clientName} ({$companyTitle}): '{$text}'.\n"
            . "• Принято в обработку ИИ-агентом.";

        return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
    }

    private function detectProject(string $lower, string $companyTitle = ''): ?array
    {
        $projects = [
            'kuzbank'     => ['name' => 'Банк Кузнецкий', 'group_name' => 'KUZBANK.RU', 'domain' => 'kuzbank.ru'],
            'кузнецк'     => ['name' => 'Банк Кузнецкий', 'group_name' => 'KUZBANK.RU', 'domain' => 'kuzbank.ru'],
            'мастер воды' => ['name' => 'Мастер Воды', 'group_name' => 'MASTERVODI.RU', 'domain' => 'mastervodi.ru'],
            'mastervodi'  => ['name' => 'Мастер Воды', 'group_name' => 'MASTERVODI.RU', 'domain' => 'mastervodi.ru'],
            'geotek'      => ['name' => 'НПП Геотек', 'group_name' => 'NPP-GEOTEK.COM', 'domain' => 'npp-geotek.ru'],
            'геотек'      => ['name' => 'НПП Геотек', 'group_name' => 'NPP-GEOTEK.COM', 'domain' => 'npp-geotek.ru'],
            'penzaoblgaz' => ['name' => 'Пензаоблгаз', 'group_name' => 'WAYDEV.RU', 'domain' => 'penzaoblgaz.ru'],
            'облгаз'      => ['name' => 'Пензаоблгаз', 'group_name' => 'WAYDEV.RU', 'domain' => 'penzaoblgaz.ru'],
            'fillini'     => ['name' => 'Филлини', 'group_name' => 'FILLINI.RU', 'domain' => 'fillini.ru'],
            'филлини'     => ['name' => 'Филлини', 'group_name' => 'FILLINI.RU', 'domain' => 'fillini.ru'],
            'lubidom'     => ['name' => 'Любимый Дом', 'group_name' => 'LUBIDOM.RU', 'domain' => 'lubidom.ru'],
            'yasenevo'    => ['name' => 'Ясенево', 'group_name' => 'YASENEVO.HOMES', 'domain' => 'yasenevo.homes'],
            'ясенево'     => ['name' => 'Ясенево', 'group_name' => 'YASENEVO.HOMES', 'domain' => 'yasenevo.homes'],
        ];

        $combined = $lower . ' ' . mb_strtolower($companyTitle);
        foreach ($projects as $key => $proj) {
            if (str_contains($combined, $key)) {
                return $proj;
            }
        }

        return ['name' => 'WAYDEV', 'group_name' => 'WAYDEV.RU', 'domain' => 'waydev.ru'];
    }

    private function summarizeTask(string $text, string $projectName): string
    {
        $clean = preg_replace('/^(добрый\s+(день|вечер|утро)|здравствуйте|привет)[,\.\s]*/iu', '', $text);
        $clean = trim((string)$clean);
        if ($clean === '') {
            return "Обращение клиента по проекту {$projectName}";
        }
        return ucfirst(mb_substr($clean, 0, 70));
    }
}

// Запуск в CLI
$isDaemon = in_array('--daemon', $argv ?? [], true);
$responder = new Line18AutoResponder();

echo "=== Запуск автоответчика Линии 18 (Открытая Линия Telegram Wazzup) ===\n";
echo "Фильтр: ТОЛЬКО пользователь KelvinMarty\n";

if ($isDaemon) {
    echo "Режим: ДЕМОН (опрос каждые 30 секунд)...\n";
    while (true) {
        try {
            $count = $responder->processOnce();
            if ($count > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Обработано новых сообщений: {$count}\n";
            }
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage() . "\n";
        }
        sleep(30);
    }
} else {
    echo "Режим: ОДИНОЧНЫЙ ПРОХОД\n";
    $count = $responder->processOnce();
    echo "[" . date('Y-m-d H:i:s') . "] Завершено. Обработано: {$count}\n";
}
