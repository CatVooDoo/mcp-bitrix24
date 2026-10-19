<?php

declare(strict_types=1);

/**
 * Контекстно-зависимый фоновый автоответчик для Открытой линии 18 ("Открытая Линия Telegram (Wazzup)").
 *
 * Ключевые возможности:
 * 1. Анализирует ВСЮ историю диалога (что говорил клиент, что уже отвечал бот).
 * 2. Не повторяет шаблонные вопросы и приветствия, если они уже были заданы в диалоге.
 * 3. Отвечает строго по существу вопроса (услуги компании, цены, сроки, статус задач, баги).
 * 4. Проверяет привязку к CRM (Контакт, Компания, Лид, Сделки, Задачи).
 * 5. В скрытом чате (SYSTEM => 'Y') отправляет сводку с обязательным тегом [USER=1650]Минаев Никита[/USER].
 * 6. Фильтр: диалог ТОЛЬКО с пользователем KelvinMarty.
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

            // 1. Проверяем, Линия 18 ли это
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

            // 2. Фильтр: ТОЛЬКО пользователь KelvinMarty
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

            if ($this->processDialog($effectiveDialogId, $chatId, $title, $item)) {
                $processedCount++;
            }
        }

        return $processedCount;
    }

    /**
     * Обработка диалога пользователя KelvinMarty с полным учетом истории.
     */
    public function processDialog(string $dialogId, int $chatId, string $title, array $dialogMeta): bool
    {
        if ($chatId <= 0 && str_starts_with($dialogId, 'chat')) {
            $chatId = (int)substr($dialogId, 4);
        }

        // Подключаем оператора при необходимости
        if ($chatId > 0) {
            $usersRes = \CRest::call('im.chat.user.list', ['CHAT_ID' => $chatId]);
            $usersList = is_array($usersRes['result'] ?? null) ? $usersRes['result'] : [];
            if (!in_array(self::OPERATOR_USER_ID, $usersList, true)) {
                \CRest::call('imopenlines.operator.answer', ['CHAT_ID' => $chatId]);
            }
        }

        // Загружаем историю последних сообщений (до 30 сообщений для полного контекста)
        $historyRes = $this->chatService->getHistory($dialogId, 30);
        if (!$historyRes['success'] || empty($historyRes['messages'])) {
            return false;
        }

        $messages = $historyRes['messages'];
        usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));

        // Выделяем историю диалога: сообщения клиента и ответы нашего бота
        $clientMessages = [];
        $ourReplies = [];
        $lastClientMessage = null;
        $ourLastReplyTime = 0;
        $ourLastReplyId = 0;

        foreach ($messages as $msg) {
            $authorId = (int)($msg['author_id'] ?? 0);
            $msgText = trim((string)($msg['text'] ?? ''));
            if ($msgText === '') {
                continue;
            }

            if ($authorId === self::OPERATOR_USER_ID) {
                // Если это системная внутренняя сводка, игнорируем как публичный ответ
                if (!str_starts_with($msgText, '🤖 [Внутренняя')) {
                    $ourReplies[] = $msg;
                    $ourLastReplyTime = strtotime((string)($msg['date'] ?? ''));
                    $ourLastReplyId = (int)($msg['id'] ?? 0);
                }
            } elseif ($authorId === self::TARGET_USER_ID || $authorId > 0) {
                $clientMessages[] = $msg;
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

        echo "[" . date('Y-m-d H:i:s') . "] Новое сообщение от KelvinMarty в {$dialogId}: '{$clientText}'\n";

        // Проверка в CRM
        $crmContext = $this->checkClientInCrm($dialogId, $clientAuthorId, $clientMessages);

        // Анализ контекста диалога (истории) и генерация умного ответа
        $result = $this->generateContextualResponse(
            $dialogId,
            $clientText,
            $clientMessages,
            $ourReplies,
            $crmContext
        );

        $publicReply = $result['public_reply'];
        $internalNote = $result['internal_note'];

        // Отправка скрытой заметки в чат с тегом [USER=1650]
        $hiddenRes = $this->chatService->sendReply($dialogId, $internalNote, [
            'SYSTEM' => 'Y',
        ]);
        if ($hiddenRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Отправлена скрытая сводка оператору с тегом [USER=" . self::OPERATOR_USER_ID . "]\n";
        }

        // Отправка публичного ответа пользователю KelvinMarty
        $sendRes = $this->chatService->sendReply($dialogId, $publicReply);
        if ($sendRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Успешно отправлен контекстный ответ клиенту в {$dialogId}\n";
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
     * Проверка клиента в CRM с учетом истории сообщений.
     */
    private function checkClientInCrm(string $dialogId, int $authorId, array $clientMessages): array
    {
        $dossierRes = $this->crmService->getClientDossier(['chat_id' => $dialogId]);
        $dossier = $dossierRes['dossier'] ?? [];

        $contact = $dossier['contact'] ?? null;
        $company = $dossier['company'] ?? null;
        $lead = $dossier['lead'] ?? null;
        $tasks = $dossier['tasks'] ?? [];
        $deals = $dossier['deals'] ?? [];
        $contracts = $dossier['contracts'] ?? [];

        // Проверяем ранее сохраненные данные в состоянии диалога
        $savedCompany = $this->state[$dialogId]['identified_company'] ?? null;
        $savedName = $this->state[$dialogId]['identified_name'] ?? null;

        $isClient = (!empty($contact) || !empty($company) || !empty($lead) || !empty($savedCompany));

        $clientName = $savedName ?: 'KelvinMarty';
        if (!empty($contact)) {
            $cName = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
            if ($cName !== '') {
                $clientName = $cName;
            }
        }

        $companyTitle = !empty($company) ? ($company['TITLE'] ?? '') : ($savedCompany ?: '');
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
     * Контекстная генерация ответа на основе полной истории диалога.
     */
    private function generateContextualResponse(
        string $dialogId,
        string $currentText,
        array $clientMessages,
        array $ourReplies,
        array $crmContext
    ): array {
        $lower = mb_strtolower($currentText);
        $isClient = $crmContext['is_client'];
        $clientName = $crmContext['client_name'];
        $companyTitle = $crmContext['company_title'];
        $assignedById = $crmContext['assigned_by_id'];

        $responsibleTag = "[USER=" . self::OPERATOR_USER_ID . "]Минаев Никита[/USER]";
        if ($assignedById > 0 && $assignedById !== self::OPERATOR_USER_ID) {
            $responsibleTag .= " и [USER={$assignedById}]Ответственный менеджер[/USER]";
        }

        // Анализ истории: что бот уже говорил ранее?
        $alreadyIntroduced = false;
        $alreadyAskedIdentity = !empty($this->state[$dialogId]['asked_identity']);

        foreach ($ourReplies as $prevReply) {
            $prevText = (string)($prevReply['text'] ?? '');
            if (str_contains($prevText, 'Я ИИ-ассистент службы технической поддержки') || str_contains($prevText, 'Какую компанию или проект вы представляете')) {
                $alreadyIntroduced = true;
                $alreadyAskedIdentity = true;
            }
        }

        // Обновляем флаг в состоянии
        if ($alreadyAskedIdentity) {
            $this->state[$dialogId]['asked_identity'] = true;
        }

        // =========================================================================
        // ИНТЕНТ 1: ВОПРОС ОБ УСЛУГАХ КОМПАНИИ WAYDEV
        // =========================================================================
        $isServicesInquiry = (
            str_contains($lower, 'услуг') ||
            str_contains($lower, 'что вы делаете') ||
            str_contains($lower, 'что делаете') ||
            str_contains($lower, 'чем занимаетесь') ||
            str_contains($lower, 'чем вы занимаетесь') ||
            str_contains($lower, 'что предлагаете') ||
            str_contains($lower, 'чем можете помочь') ||
            str_contains($lower, 'какой стек') ||
            str_contains($lower, 'направления')
        );

        if ($isServicesInquiry) {
            $publicReply = "Компания WAYDEV специализируется на веб-разработке, внедрении цифровых решений и технической поддержке бизнеса.\n\n"
                . "Наши ключевые направления услуг:\n"
                . "1. **Битрикс24:** внедрение CRM, настройка бизнес-процессов и смарт-процессов, интеграция с 1С, телефонией, сайтами и мессенджерами (Telegram, WhatsApp).\n"
                . "2. **Веб-разработка:** создание сайтов, интернет-магазинов, личных кабинетов и B2B-порталов на 1С-Битрикс, Laravel, React/Vue.\n"
                . "3. **Техническая поддержка и SLA:** круглосуточный мониторинг, L1/L2 поддержка, доработки по ТЗ, оптимизация скорости и устранение сбоев.\n"
                . "4. **Мобильная разработка:** приложения для iOS и Android.\n"
                . "5. **ИИ-интеграции и автоматизация:** разработка умных AI-ассистентов, ботов техподдержки и интеграция нейросетей в бизнес-процессы.\n\n"
                . "Подскажите, какая задача или проект актуальны для вас сейчас? Я с радостью сориентирую подробнее!";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Пользователь KelvinMarty интересуется услугами компании.\n"
                . "• Текст вопроса: '{$currentText}'\n"
                . "• Действие ИИ: предоставлен структурированный список направлений услуг WAYDEV.\n"
                . "(Агент продолжает сопровождать диалог).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ИНТЕНТ 2: ВОПРОС О СТОИМОСТИ / ТАРИФАХ
        // =========================================================================
        $isPricingInquiry = (
            str_contains($lower, 'стоимост') ||
            str_contains($lower, 'сколько стоит') ||
            str_contains($lower, 'цена') ||
            str_contains($lower, 'цены') ||
            str_contains($lower, 'тариф') ||
            str_contains($lower, 'прайс') ||
            str_contains($lower, 'расценк')
        );

        if ($isPricingInquiry) {
            $publicReply = "Стоимость услуг зависит от формата и объема работ:\n\n"
                . "• **Техническая поддержка:** работаем по абонентским пакетам часов (с SLA и гарантированным временем реакции) либо по фактическим трудозатратам (Time & Material).\n"
                . "• **Проектная разработка и доработки:** оцениваем задачу в часах по техническому заданию с фиксированной стоимостью этапов.\n\n"
                . "Если у вас есть конкретная задача, ТЗ или описание доработки — пришлите сюда, и мы подготовим предварительную оценку по срокам и бюджету.";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Пользователь KelvinMarty запросил информацию о стоимости/тарифах: '{$currentText}'.\n"
                . "• Действие ИИ: предоставлена информация по моделям ценообразования, запрошено ТЗ для оценки.\n"
                . "(Агент на связи).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ИНТЕНТ 3: ПОЛЬЗОВАТЕЛЬ ПРЕДСТАВЛЯЕТСЯ / НАЗЫВАЕТ КОМПАНИЮ ИЛИ ПРОЕКТ
        // =========================================================================
        $introMatch = $this->extractIdentityInfo($currentText);
        if ($introMatch !== null) {
            if (!empty($introMatch['company'])) {
                $this->state[$dialogId]['identified_company'] = $introMatch['company'];
                $companyTitle = $introMatch['company'];
            }
            if (!empty($introMatch['name'])) {
                $this->state[$dialogId]['identified_name'] = $introMatch['name'];
                $clientName = $introMatch['name'];
            }
            $this->saveState();

            $publicReply = "Очень приятно познакомиться, {$clientName}!" . ($companyTitle ? " (компания {$companyTitle})" : "") . "\n\n"
                . "Я зафиксировал ваши данные в системе. Подскажите, какой вопрос или задачу требуется решить?";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Пользователь идентифицирован в диалоге: {$clientName}" . ($companyTitle ? ", Компания: {$companyTitle}" : "") . ".\n"
                . "• Текст: '{$currentText}'\n"
                . "• Данные сохранены в профиле сессии.\n"
                . "(Агент продолжает ведение диалога).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ИНТЕНТ 4: ВОПРОСЫ О СТАТУСЕ ЗАДАЧИ
        // =========================================================================
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
                $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 5);
                $recentTasks = $tasksRes['tasks'] ?? [];
            }

            if (!empty($recentTasks)) {
                $matchedTask = $recentTasks[0];
                $tId = $matchedTask['ID'] ?? ($matchedTask['id'] ?? '');
                $tTitle = $matchedTask['TITLE'] ?? ($matchedTask['title'] ?? '');
                $tStatus = $matchedTask['statusText'] ?? 'В работе';
                $tDeadline = !empty($matchedTask['DEADLINE']) ? date('d.m.Y H:i', strtotime($matchedTask['DEADLINE'])) : 'Уточняется';

                $publicReply = "По вашей задаче №{$tId} («{$tTitle}»):\n\n"
                    . "• Текущий статус: {$tStatus}\n"
                    . "• Ответственный специалист: Минаев Никита\n"
                    . "• Крайний срок: {$tDeadline}\n\n"
                    . "Задача находится на контроле. Как только появятся обновления, я сразу напишу сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Запрос статуса задачи от KelvinMarty: '{$currentText}'.\n"
                    . "• Предоставлен статус задачи №{$tId} ({$tStatus}).\n"
                    . "(Агент сопровождает диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            } else {
                $publicReply = "На текущий момент по вашему обращению нет открытых задач в системе. Если у вас возникла техническая сложность или требуется доработка — опишите детали, и я поставлю задачу специалистам.";
                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Запрос статуса от KelvinMarty, активных задач не найдено.\n"
                    . "(Агент на связи).";
                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ИНТЕНТ 5: ТЕХНИЧЕСКИЙ СБОЙ / БАГ / ЗАПРОС НА ДОРАБОТКУ
        // =========================================================================
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
            $hasEnoughDetails = mb_strlen($currentText) > 30;
            if (!$hasEnoughDetails) {
                $publicReply = "Подскажите, пожалуйста, подробнее:\n"
                    . "1. На каком сайте или странице возникла сложность?\n"
                    . "2. Что именно работает некорректно и какой результат ожидается?\n"
                    . "3. Пришлите, пожалуйста, ссылку или скриншот ошибки.\n\n"
                    . "Я соберу информацию и передам задачу в работу техническим специалистам.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Обращение с проблемой от KelvinMarty: '{$currentText}'.\n"
                    . "• Действие ИИ: запрос недостающих деталей для заведения задачи по регламенту.\n"
                    . "(Агент в диалоге).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Постановка задачи по регламенту
            $project = $this->detectProject($lower, $companyTitle);
            $taskDate = date('d.m.Y');
            $deadline = date('Y-m-d 18:00:00', strtotime('+1 weekday'));
            $taskSummary = $this->summarizeTask($currentText, $project['name']);

            $taskData = [
                'project_group_name'  => $project['group_name'],
                'task_summary'        => $taskSummary,
                'task_date'           => $taskDate,
                'plan_steps'          => [
                    "Диагностика обращения клиента {$clientName}",
                    "Локализация сбоя на проекте {$project['name']}",
                    "Внесение исправлений и проверка",
                    "Уведомление клиента о результатах",
                ],
                'links'               => ["https://{$project['domain']}"],
                'checklist'           => ["Диагностика", "Исправление", "Тестирование и сдача"],
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
                $publicReply = "Ваше обращение зафиксировано и поставлено в работу специалистам:\n\n"
                    . "• Задача №{$taskId}: «{$project['group_name']} | {$taskSummary} {$taskDate}»\n"
                    . "• Ответственный: Минаев Никита\n"
                    . "• Крайний срок: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n\n"
                    . "Мы уже занимаемся вопросом. Если появятся дополнительные примеры или файлы — присылайте в этот чат.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Сформирована задача №{$taskId}: «{$project['group_name']} | {$taskSummary} {$taskDate}»\n"
                    . "• Обращение от KelvinMarty: '{$currentText}'\n"
                    . "• Дедлайн: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n"
                    . "(Агент ведет диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ИНТЕНТ 6: ПРИВЕТСТВИЕ
        // =========================================================================
        $isGreeting = str_contains($lower, 'привет') || str_contains($lower, 'здравствуй') || str_contains($lower, 'добрый день') || str_contains($lower, 'добрый вечер');
        if ($isGreeting) {
            // Если бот УЖЕ здоровался ранее в диалоге — не повторяем шаблонное приветствие!
            if ($alreadyIntroduced) {
                $publicReply = "Да, я на связи! Чем могу помочь вам сейчас?";
            } else {
                $publicReply = "Здравствуйте, {$clientName}! Я ИИ-ассистент службы технической поддержки компании WAYDEV.\n\n"
                    . "Чем могу помочь? Опишите, пожалуйста, ваш вопрос или задачу — я найду информацию либо подключу профильных специалистов.";
                $this->state[$dialogId]['asked_identity'] = true;
                $this->saveState();
            }

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Приветствие от KelvinMarty: '{$currentText}'.\n"
                . "(Агент ответил на приветствие).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ИНТЕНТ 7: БЛАГОДАРНОСТЬ / ПОДТВЕРЖДЕНИЕ
        // =========================================================================
        $isThanks = str_contains($lower, 'спасибо') || str_contains($lower, 'благодар') || str_contains($lower, 'понял') || str_contains($lower, 'отлично') || str_contains($lower, 'хорошо');
        if ($isThanks) {
            $publicReply = "Пожалуйста! Если возникнут вопросы или потребуется помощь по сайту или Битрикс24 — обращайтесь, я всегда на связи.";
            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Клиент поблагодарил: '{$currentText}'.\n"
                . "(Диалог продолжается).";
            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ДЕФОЛТНЫЙ ОТВЕТ (УНИВЕРСАЛЬНЫЙ, БЕЗ СПАМА ШАБЛОНАМИ)
        // =========================================================================
        if ($alreadyAskedIdentity) {
            $publicReply = "Принял ваше сообщение: «{$currentText}». Уточните, пожалуйста, детали задачи или проекта, чтобы я мог сразу передать вопрос ответственным специалистам.";
        } else {
            $publicReply = "Здравствуйте, {$clientName}! Ваше сообщение принято в обработку.\n\n"
                . "Подскажите, какую компанию или проект вы представляете и какая задача перед нами стоит? Я передам информацию профильным специалистам.";
            $this->state[$dialogId]['asked_identity'] = true;
            $this->saveState();
        }

        $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
            . "Внимание, {$responsibleTag}!\n"
            . "• Сообщение от KelvinMarty: '{$currentText}'.\n"
            . "• Статус в CRM: " . ($isClient ? "Клиент ({$companyTitle})" : "Не привязан к CRM") . "\n"
            . "(Агент в диалоге).";

        return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
    }

    /**
     * Попытка извлечь имя и компанию из естественного текста пользователя.
     */
    private function extractIdentityInfo(string $text): ?array
    {
        $res = [];
        // Проверка компании
        if (preg_match('/(?:компани[яи]|проект|из|представляю)\s+([«"][^»"]+[»"]|[a-zA-Zа-яА-Я0-9\.\-]+)/iu', $text, $m)) {
            $comp = trim($m[1], " \t\n\r\0\x0B«»\"'");
            if (mb_strlen($comp) > 2) {
                $res['company'] = $comp;
            }
        }
        // Проверка имени
        if (preg_match('/(?:меня зовут|мое имя|я|зовут)\s+([а-яА-Яa-zA-Z]+)/iu', $text, $m)) {
            $name = trim($m[1]);
            if (mb_strlen($name) > 2 && !in_array(mb_strtolower($name), ['компания', 'проект', 'хотел', 'хочу', 'просто', 'тут'])) {
                $res['name'] = $name;
            }
        }

        return !empty($res) ? $res : null;
    }

    private function detectProject(string $lower, string $companyTitle = ''): array
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
