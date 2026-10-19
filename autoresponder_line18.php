<?php

declare(strict_types=1);

/**
 * Высокоточный контекстный автоответчик для Открытой линии 18 ("Открытая Линия Telegram (Wazzup)").
 *
 * Архитектура приоритетов:
 * ПРИОРИТЕТ 1: Критические инциденты, аварии, сбои, баги и технические доработки.
 *              Автоматически парсит портал/сайт, определяет проект и ставит задачу по регламенту из 6 пунктов.
 * ПРИОРИТЕТ 2: Вопросы по статусам существующих задач.
 * ПРИОРИТЕТ 3: Вопросы по услугам, стоимости, срокам и компетенциям WAYDEV.
 * ПРИОРИТЕТ 4: Корректное знакомство (строгий парсер имени без ложных срабатываний на предлоги/окончания).
 * ПРИОРИТЕТ 5: Вежливое приветствие / удержание диалога.
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

    private const STOP_WORDS_NAME = [
        'помощь', 'проблема', 'ошибка', 'задача', 'вопрос', 'сайт', 'портал',
        'битрикс', 'телефония', 'срочно', 'пожалуйста', 'клиент', 'пользователь',
        'разработчик', 'сервер', 'проект', 'компания', 'синхронизация', 'хотел',
        'хочу', 'нужно', 'сделать', 'переподключить', 'тест', 'демо', 'провайдер'
    ];

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

            // Проверка Линии 18
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

            // Фильтр: ТОЛЬКО KelvinMarty
            $isTargetUser = false;
            if (stripos($title, self::TARGET_USER_SUBSTRING) !== false) {
                $isTargetUser = true;
            }
            $chatUser = $item['user'] ?? [];
            $userName = trim(($chatUser['name'] ?? '') . ' ' . ($chatUser['last_name'] ?? ''));
            if (stripos($userName, self::TARGET_USER_SUBSTRING) !== false || (int)($chatUser['id'] ?? 0) === self::TARGET_USER_ID) {
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

    public function processDialog(string $dialogId, int $chatId, string $title, array $dialogMeta): bool
    {
        if ($chatId <= 0 && str_starts_with($dialogId, 'chat')) {
            $chatId = (int)substr($dialogId, 4);
        }

        // Подключаем оператора
        if ($chatId > 0) {
            $usersRes = \CRest::call('im.chat.user.list', ['CHAT_ID' => $chatId]);
            $usersList = is_array($usersRes['result'] ?? null) ? $usersRes['result'] : [];
            if (!in_array(self::OPERATOR_USER_ID, $usersList, true)) {
                \CRest::call('imopenlines.operator.answer', ['CHAT_ID' => $chatId]);
            }
        }

        // Читаем последние сообщения
        $historyRes = $this->chatService->getHistory($dialogId, 30);
        if (!$historyRes['success'] || empty($historyRes['messages'])) {
            return false;
        }

        $messages = $historyRes['messages'];
        usort($messages, fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));

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

        // Проверяем, отвечали ли уже
        $savedLastMsgId = (int)($this->state[$dialogId]['last_replied_msg_id'] ?? 0);
        if ($clientMsgId <= $savedLastMsgId) {
            return false;
        }

        if ($ourLastReplyTime > $clientMsgTime && $ourLastReplyId > $clientMsgId) {
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->saveState();
            return false;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Новое входящее от KelvinMarty в {$dialogId}: '{$clientText}'\n";

        // Проверка в CRM
        $crmContext = $this->checkClientInCrm($dialogId, $clientAuthorId);

        // Контекстная обработка
        $result = $this->generateResponse($dialogId, $clientText, $clientMessages, $ourReplies, $crmContext);

        $publicReply = $result['public_reply'];
        $internalNote = $result['internal_note'];

        // Отправка скрытой сводки с тегом оператора
        $this->chatService->sendReply($dialogId, $internalNote, ['SYSTEM' => 'Y']);

        // Отправка публичного ответа клиенту
        $sendRes = $this->chatService->sendReply($dialogId, $publicReply);
        if ($sendRes['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Успешно отправлен ответ в {$dialogId}\n";
            $this->state[$dialogId]['last_replied_msg_id'] = $clientMsgId;
            $this->state[$dialogId]['last_reply_at'] = date('c');
            $this->saveState();
            return true;
        }

        return false;
    }

    private function checkClientInCrm(string $dialogId, int $authorId): array
    {
        $dossierRes = $this->crmService->getClientDossier(['chat_id' => $dialogId]);
        $dossier = $dossierRes['dossier'] ?? [];

        $contact = $dossier['contact'] ?? null;
        $company = $dossier['company'] ?? null;
        $lead = $dossier['lead'] ?? null;
        $tasks = $dossier['tasks'] ?? [];
        $deals = $dossier['deals'] ?? [];
        $contracts = $dossier['contracts'] ?? [];

        $savedCompany = $this->state[$dialogId]['identified_company'] ?? null;
        $savedName = $this->state[$dialogId]['identified_name'] ?? null;

        // Санитизация имени: отсекаем любые стоп-слова
        if ($savedName !== null) {
            $savedLower = mb_strtolower(trim($savedName));
            if (in_array($savedLower, self::STOP_WORDS_NAME, true) || mb_strlen($savedName) < 2) {
                $savedName = null;
                unset($this->state[$dialogId]['identified_name']);
                $this->saveState();
            }
        }

        $isClient = (!empty($contact) || !empty($company) || !empty($lead) || !empty($savedCompany));

        $clientName = $savedName ?: 'Никита';
        if (!empty($contact)) {
            $cName = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
            if ($cName !== '' && !in_array(mb_strtolower($cName), self::STOP_WORDS_NAME, true)) {
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

    private function generateResponse(
        string $dialogId,
        string $currentText,
        array $clientMessages,
        array $ourReplies,
        array $crmContext
    ): array {
        $lower = mb_strtolower($currentText);
        $clientName = $crmContext['client_name'];
        $companyTitle = $crmContext['company_title'];
        $assignedById = $crmContext['assigned_by_id'];

        $responsibleTag = "[USER=" . self::OPERATOR_USER_ID . "]Минаев Никита[/USER]";
        if ($assignedById > 0 && $assignedById !== self::OPERATOR_USER_ID) {
            $responsibleTag .= " и [USER={$assignedById}]Ответственный менеджер[/USER]";
        }

        // =========================================================================
        // ПРИОРИТЕТ 1: ТЕХНИЧЕСКИЙ СБОЙ, БАГ, АВАРИЯ, ЗАПРОС НА ДОРАБОТКУ
        // (Высший приоритет — обрабатывается первым!)
        // =========================================================================
        $isIncident = (
            str_contains($lower, 'не работа') ||
            str_contains($lower, 'отвалил') ||
            str_contains($lower, 'сломал') ||
            str_contains($lower, 'ошибк') ||
            str_contains($lower, 'сбой') ||
            str_contains($lower, 'переподключ') ||
            str_contains($lower, 'телефони') ||
            str_contains($lower, 'sip') ||
            str_contains($lower, 'почини') ||
            str_contains($lower, 'исправ') ||
            str_contains($lower, 'проблем') ||
            str_contains($lower, 'баг') ||
            str_contains($lower, 'пада') ||
            str_contains($lower, 'лежит') ||
            str_contains($lower, 'доработ') ||
            str_contains($lower, 'сделай')
        );

        if ($isIncident) {
            // Определяем домен / портал / проект
            $project = $this->detectProject($lower, $currentText);

            $taskSummary = $this->buildIncidentSummary($currentText, $project['name']);
            $taskDate = date('d.m.Y');
            $deadline = date('Y-m-d 19:00:00'); // Срочные инциденты - сегодня до 19:00

            $taskData = [
                'project_group_name'  => $project['group_name'],
                'task_summary'        => $taskSummary,
                'task_date'           => $taskDate,
                'tags'                => ['ИИ-Агент'],
                'description'         => "Критический инцидент / задача от клиента в открытой линии:\n{$currentText}\n\nПлан действий:\n1. Провести первичную диагностику и локализацию сбоя на {$project['domain']}\n2. Выполнить восстановительные работы и исправления\n3. Провести тестирование и убедиться в работоспособности\n4. Уведомить клиента в чате открытой линии",
                'plan_steps'          => [
                    "Диагностика сбоя на проекте {$project['name']}",
                    "Локализация и устранение проблемы",
                    "Тестирование сервиса",
                    "Уведомление клиента о результатах",
                ],
                'links'               => ["https://{$project['domain']}"],
                'checklist'           => [
                    "Диагностика инцидента",
                    "Восстановление и исправление",
                    "Тестирование функционала и сдача",
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

                $publicReply = "Принято в работу! Зафиксировал задачу по обращению:\n\n"
                    . "• **Задача №{$taskId}:** «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• **Проект:** {$project['domain']}\n"
                    . "• **Ответственный специалист:** Минаев Никита\n"
                    . "• **Приоритет:** Срочно (инцидент)\n"
                    . "• **Крайний срок:** сегодня, " . date('d.m.Y, 19:00') . "\n\n"
                    . "Специалисты уже подключаются к диагностике. Как только появятся результаты или восстановим работу — сразу сообщу вам сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• [b][КРИТИЧЕСКИЙ ИНЦИДЕНТ / ЗАДАЧА][/b]\n"
                    . "• Сформирована задача №{$taskId}: «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• Текст клиента: '{$currentText}'\n"
                    . "• Ответственный: Минаев Никита, дедлайн: сегодня до 19:00\n"
                    . "(Агент продолжает сопровождать диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 2: ВОПРОСЫ О СТАТУСЕ ЗАДАЧ
        // =========================================================================
        $isStatusQuestion = (
            str_contains($lower, 'статус') ||
            str_contains($lower, 'что с задач') ||
            str_contains($lower, 'как дела с') ||
            str_contains($lower, 'когда будет готов') ||
            str_contains($lower, 'какой результат')
        );

        if ($isStatusQuestion) {
            $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 5);
            $recentTasks = $tasksRes['tasks'] ?? [];

            if (!empty($recentTasks)) {
                $matchedTask = $recentTasks[0];
                $tId = $matchedTask['ID'] ?? ($matchedTask['id'] ?? '');
                $tTitle = $matchedTask['TITLE'] ?? ($matchedTask['title'] ?? '');
                $tStatus = $matchedTask['statusText'] ?? 'В работе';
                $tDeadline = !empty($matchedTask['DEADLINE']) ? date('d.m.Y H:i', strtotime($matchedTask['DEADLINE'])) : 'Уточняется';

                $publicReply = "По вашей задаче №{$tId} («{$tTitle}»):\n\n"
                    . "• **Статус:** {$tStatus}\n"
                    . "• **Ответственный:** Минаев Никита\n"
                    . "• **Крайний срок:** {$tDeadline}\n\n"
                    . "Задача в работе у специалистов. О любых обновлениях я сразу сообщу вам сюда.";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Запрос статуса задачи: '{$currentText}'.\n"
                    . "• Предоставлен статус задачи №{$tId} ({$tStatus}).\n"
                    . "(Агент на связи).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 3: ВОПРОС ОБ УСЛУГАХ КОМПАНИИ WAYDEV
        // =========================================================================
        $isServicesInquiry = (
            str_contains($lower, 'услуг') ||
            str_contains($lower, 'что вы делаете') ||
            str_contains($lower, 'чем занимаетесь') ||
            str_contains($lower, 'что предлагаете') ||
            str_contains($lower, 'какой стек')
        );

        if ($isServicesInquiry) {
            $publicReply = "Компания WAYDEV специализируется на веб-разработке, внедрении цифровых решений и технической поддержке бизнеса:\n\n"
                . "1. **Битрикс24:** внедрение CRM, смарт-процессы, интеграция с 1С, телефонией, мессенджерами (Telegram, WhatsApp).\n"
                . "2. **Веб-разработка:** разработка корпоративных сайтов, порталов и интернет-магазинов (1С-Битрикс, Laravel, React/Vue).\n"
                . "3. **Техническая поддержка и SLA:** круглосуточный мониторинг, L1/L2 поддержка, доработки по ТЗ и устранение сбоев.\n"
                . "4. **Мобильная разработка:** iOS и Android.\n"
                . "5. **ИИ-интеграции:** разработка AI-ассистентов, ботов техподдержки и интеграция нейросетей в бизнес.\n\n"
                . "Подскажите, какая задача или проект актуальны для вас сейчас?";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Пользователь KelvinMarty запросил услуги компании.\n"
                . "(Предоставлен список услуг).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 4: КОРРЕКТНОЕ ЗНАКОМСТВО / ПРЕДСТАВЛЕНИЕ
        // (Строгий парсер имени с черным списком стоп-слов)
        // =========================================================================
        $identity = $this->parseStrictIdentity($currentText);
        if ($identity !== null) {
            if (!empty($identity['name'])) {
                $this->state[$dialogId]['identified_name'] = $identity['name'];
                $clientName = $identity['name'];
            }
            if (!empty($identity['company'])) {
                $this->state[$dialogId]['identified_company'] = $identity['company'];
                $companyTitle = $identity['company'];
            }
            $this->saveState();

            $publicReply = "Очень приятно познакомиться, {$clientName}!" . ($companyTitle ? " (компания {$companyTitle})" : "") . "\n\n"
                . "Я зафиксировал ваши контакты. Чем могу помочь вам сегодня?";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Пользователь представился: {$clientName}" . ($companyTitle ? " ({$companyTitle})" : "") . "\n"
                . "(Данные сохранены в сессии).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 5: ПРИВЕТСТВИЕ И ОБЩИЕ ВОПРОСЫ
        // =========================================================================
        $isGreeting = str_contains($lower, 'привет') || str_contains($lower, 'здравствуй') || str_contains($lower, 'добрый день') || str_contains($lower, 'добрый вечер');
        if ($isGreeting) {
            // Проверяем наличие активных задач в работе
            $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 1);
            $activeTask = !empty($tasksRes['tasks'][0]) ? $tasksRes['tasks'][0] : null;

            if ($activeTask !== null && (int)($activeTask['status'] ?? 0) !== 5) {
                $tId = $activeTask['id'] ?? ($activeTask['ID'] ?? '');
                $tTitle = $activeTask['title'] ?? ($activeTask['TITLE'] ?? '');
                $publicReply = "Добрый день, {$clientName}! Да, я на связи. По вашей задаче №{$tId} («{$tTitle}») специалисты уже занимаются вопросом. Подскажите, появились новые подробности или у вас другой вопрос?";
            } else {
                $publicReply = "Добрый день, {$clientName}! Я на связи, слушаю вас. Чем могу помочь сегодня?";
            }

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\nВнимание, {$responsibleTag}!\n• Приветствие от {$clientName}.";
            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // Дефолтный ответ (естественный и дружелюбный)
        $publicReply = "Понял вас, {$clientName}. Уточните, пожалуйста, подробнее суть задачи или проекта, и я сразу передам информацию специалистам.";
        $internalNote = "🤖 [Внутренняя сводка ИИ]:\nВнимание, {$responsibleTag}!\n• Сообщение: '{$currentText}'.";

        return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
    }

    /**
     * Строгое извлечение ФИО и компании без ложных срабатываний.
     */
    private function parseStrictIdentity(string $text): ?array
    {
        $clean = trim($text);
        $res = [];

        // 1. Прямой ввод ФИО из 2 слов (например: "Минаев Никита" или "Иван Иванов")
        if (preg_match('/^([А-ЯЁ][а-яё]+)\s+([А-ЯЁ][а-яё]+)$/u', $clean, $m)) {
            $word1 = mb_strtolower($m[1]);
            $word2 = mb_strtolower($m[2]);
            if (!in_array($word1, self::STOP_WORDS_NAME, true) && !in_array($word2, self::STOP_WORDS_NAME, true)) {
                $res['name'] = "{$m[1]} {$m[2]}";
                return $res;
            }
        }

        // 2. Фразы: "меня зовут Имя", "мое имя Имя"
        if (preg_match('/(?:меня зовут|мое имя)\s+([А-ЯЁа-яёA-Za-z]+(?:\s+[А-ЯЁа-яёA-Za-z]+)?)/iu', $clean, $m)) {
            $candidate = trim($m[1]);
            $candLower = mb_strtolower($candidate);
            if (!in_array($candLower, self::STOP_WORDS_NAME, true) && mb_strlen($candidate) > 2) {
                $res['name'] = $candidate;
            }
        }

        // 3. Фразы компании: "компания X", "проект Y"
        if (preg_match('/(?:компани[яи]|проект|из компании)\s+([«"][^»"]+[»"]|[a-zA-Zа-яА-Я0-9\.\-]+)/iu', $clean, $m)) {
            $comp = trim($m[1], " \t\n\r\0\x0B«»\"'");
            $compLower = mb_strtolower($comp);
            if (!in_array($compLower, self::STOP_WORDS_NAME, true) && mb_strlen($comp) > 2) {
                $res['company'] = $comp;
            }
        }

        return !empty($res) ? $res : null;
    }

    /**
     * Автоопределение проекта по домену, порталу или тексту.
     */
    private function detectProject(string $lower, string $rawText): array
    {
        // Ищем портал/домен в тексте (например, mastyervodi.bitrix24.ru)
        if (preg_match('/([a-zA-Z0-9\-\.]+\.(?:bitrix24\.ru|ru|com|homes|рф))/i', $rawText, $dm)) {
            $domain = mb_strtolower($dm[1]);
            if (str_contains($domain, 'mastervodi') || str_contains($domain, 'mastyervodi')) {
                return ['name' => 'Мастер Воды', 'group_name' => 'MASTERVODI.RU', 'domain' => $domain];
            }
            if (str_contains($domain, 'kuzbank')) {
                return ['name' => 'Банк Кузнецкий', 'group_name' => 'KUZBANK.RU', 'domain' => $domain];
            }
            if (str_contains($domain, 'geotek')) {
                return ['name' => 'НПП Геотек', 'group_name' => 'NPP-GEOTEK.COM', 'domain' => $domain];
            }
            if (str_contains($domain, 'penzaoblgaz')) {
                return ['name' => 'Пензаоблгаз', 'group_name' => 'WAYDEV.RU', 'domain' => $domain];
            }
            if (str_contains($domain, 'fillini')) {
                return ['name' => 'Филлини', 'group_name' => 'FILLINI.RU', 'domain' => $domain];
            }
            if (str_contains($domain, 'lubidom')) {
                return ['name' => 'Любимый Дом', 'group_name' => 'LUBIDOM.RU', 'domain' => $domain];
            }
            if (str_contains($domain, 'yasenevo')) {
                return ['name' => 'Ясенево', 'group_name' => 'YASENEVO.HOMES', 'domain' => $domain];
            }
            return ['name' => strtoupper(explode('.', $domain)[0]), 'group_name' => strtoupper($domain), 'domain' => $domain];
        }

        // По ключевым словам
        if (str_contains($lower, 'мастер воды') || str_contains($lower, 'mastervodi') || str_contains($lower, 'mastyervodi')) {
            return ['name' => 'Мастер Воды', 'group_name' => 'MASTERVODI.RU', 'domain' => 'mastervodi.ru'];
        }
        if (str_contains($lower, 'кузнецк') || str_contains($lower, 'kuzbank')) {
            return ['name' => 'Банк Кузнецкий', 'group_name' => 'KUZBANK.RU', 'domain' => 'kuzbank.ru'];
        }
        if (str_contains($lower, 'геотек') || str_contains($lower, 'geotek')) {
            return ['name' => 'НПП Геотек', 'group_name' => 'NPP-GEOTEK.COM', 'domain' => 'npp-geotek.ru'];
        }

        return ['name' => 'WAYDEV', 'group_name' => 'WAYDEV.RU', 'domain' => 'waydev.ru'];
    }

    private function buildIncidentSummary(string $text, string $projectName): string
    {
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'телефони') || str_contains($lower, 'sip')) {
            return "Восстановление синхронизации SIP-телефонии на портале";
        }
        if (str_contains($lower, 'сайт') && (str_contains($lower, 'не работа') || str_contains($lower, 'лежит'))) {
            return "Восстановление доступности сайта";
        }
        if (str_contains($lower, 'слайдер')) {
            return "Исправление отображения слайдера";
        }

        $clean = preg_replace('/^(добрый\s+(день|вечер|утро)|здравствуйте|привет)[,\.\s]*/iu', '', $text);
        $clean = trim((string)$clean);
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
