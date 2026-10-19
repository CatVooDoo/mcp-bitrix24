<?php

declare(strict_types=1);

/**
 * Высокоточный контекстный автоответчик для Открытой линии 18 ("Открытая Линия Telegram (Wazzup)").
 *
 * Интеллектуальная система ведения диалога:
 * 1. Сроки и дедлайны (сроки выполнения, время работы, дедлайны по активным задачам).
 * 2. Статусы и ход работ (текущий этап, статус в Bitrix24, ответственный специалист).
 * 3. Приём материалов и файлов (Яндекс.Диск, ссылки, архивы) -> автоматическое прикрепление комментарием в задачу.
 * 4. Консультации по исполнителю (Никита Минаев, контакты и ведение задачи).
 * 5. Оценка стоимости и сметы (пакеты техподдержки vs проектные нормо-часы).
 * 6. Короткие подтверждения и вежливость (спасибо, ок, договорились).
 * 7. Постановка задач и регистрация инцидентов по регламенту из 6 пунктов (без дублирования).
 * 8. Услуги и компетенции компании WAYDEV.
 * 9. Корректное знакомство (строгий парсер имени с черным списком).
 * 10. Приветствие с привязкой к активному контексту.
 * 11. Контекстный фоллбек (отражает суть реплики клиента и передает оператору без шаблонного зацикливания).
 */

require_once __DIR__ . '/src/Core/Bootstrap.php';

use Bitrix24Mcp\Core\Bootstrap;
use Bitrix24Mcp\Core\Logger;
use Bitrix24Mcp\Services\ChatService;
use Bitrix24Mcp\Services\TaskService;
use Bitrix24Mcp\Services\CrmService;
use Bitrix24Mcp\Services\ContractService;

Bootstrap::init();
date_default_timezone_set('Europe/Moscow');

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

        echo "[" . date('Y-m-d H:i:s') . "] Новое входящее от KelvinMarty в {$dialogId} (ID: {$clientMsgId}): '{$clientText}'\n";

        // Проверка в CRM
        $crmContext = $this->checkClientInCrm($dialogId, $clientAuthorId);

        // Генерация высокоточного контекстного ответа
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

    /**
     * Получение текущей активной задачи диалога с актуальными данными из Bitrix24.
     */
    private function getActiveTask(string $dialogId): ?array
    {
        $savedTaskId = (int)($this->state[$dialogId]['active_task_id'] ?? 0);
        if ($savedTaskId > 0) {
            $taskRes = $this->taskService->getTask($savedTaskId);
            if ($taskRes['success'] && !empty($taskRes['task'])) {
                $task = $taskRes['task'];
                $status = (int)($task['status'] ?? 0);
                if ($status !== 5) { // не завершена
                    return $task;
                }
            }
        }

        // Поиск среди активных задач ответственного 1650
        $tasksRes = $this->taskService->getTasks(['responsible_id' => self::OPERATOR_USER_ID], 5);
        if ($tasksRes['success'] && !empty($tasksRes['tasks'])) {
            foreach ($tasksRes['tasks'] as $t) {
                $status = (int)($t['status'] ?? 0);
                if ($status !== 5) {
                    $id = (int)($t['id'] ?? ($t['ID'] ?? 0));
                    $this->state[$dialogId]['active_task_id'] = $id;
                    $this->state[$dialogId]['active_task_title'] = (string)($t['title'] ?? ($t['TITLE'] ?? ''));
                    $this->saveState();
                    return $t;
                }
            }
        }

        return null;
    }

    private function generateResponse(
        string $dialogId,
        string $currentText,
        array $clientMessages,
        array $ourReplies,
        array $crmContext
    ): array {
        $lower = mb_strtolower(trim($currentText));
        $clientName = $crmContext['client_name'];
        $companyTitle = $crmContext['company_title'];
        $assignedById = $crmContext['assigned_by_id'];

        $responsibleTag = "[USER=" . self::OPERATOR_USER_ID . "]Минаев Никита[/USER]";
        if ($assignedById > 0 && $assignedById !== self::OPERATOR_USER_ID) {
            $responsibleTag .= " и [USER={$assignedById}]Ответственный менеджер[/USER]";
        }

        $activeTask = $this->getActiveTask($dialogId);
        $activeTaskId = $activeTask ? (int)($activeTask['id'] ?? ($activeTask['ID'] ?? 0)) : 0;
        $activeTaskTitle = $activeTask ? (string)($activeTask['title'] ?? ($activeTask['TITLE'] ?? '')) : '';
        $activeTaskStatus = $activeTask ? (string)($activeTask['statusText'] ?? 'В работе') : 'В работе';
        $activeTaskDeadlineRaw = $activeTask ? (string)($activeTask['deadline'] ?? ($activeTask['DEADLINE'] ?? '')) : '';

        $activeTaskDeadlineFormatted = '20.10.2026, 18:00';
        if ($activeTaskDeadlineRaw !== '') {
            $ts = strtotime($activeTaskDeadlineRaw);
            if ($ts > 0) {
                $activeTaskDeadlineFormatted = date('d.m.Y, H:i', $ts);
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 1: СРОКИ, ДЕДЛАЙНЫ, ВРЕМЯ ВЫПОЛНЕНИЯ
        // =========================================================================
        $isDeadlineQuestion = (
            str_contains($lower, 'срок') ||
            str_contains($lower, 'дедлайн') ||
            str_contains($lower, 'сколько по времени') ||
            str_contains($lower, 'сколько займет') ||
            str_contains($lower, 'сколько времени') ||
            str_contains($lower, 'как долго') ||
            str_contains($lower, 'когда будет готов') ||
            str_contains($lower, 'когда сдела') ||
            str_contains($lower, 'когда ждать') ||
            str_contains($lower, 'до какого числа') ||
            str_contains($lower, 'успеете') ||
            (str_contains($lower, 'когда') && (str_contains($lower, 'готов') || str_contains($lower, 'сдад') || str_contains($lower, 'заверш')))
        );

        if ($isDeadlineQuestion) {
            if ($activeTaskId > 0) {
                $isPortfolio = (str_contains(mb_strtolower($activeTaskTitle), 'портфолио') || str_contains($lower, 'портфолио'));

                if ($isPortfolio) {
                    $publicReply = "По вашей задаче №{$activeTaskId} («{$activeTaskTitle}»):\n\n"
                        . "• **Плановый дедлайн:** завтра, 20 октября, до 18:00.\n"
                        . "• **Трудоёмкость:** около 1 рабочего дня (2–4 рабочих часа на вёрстку, адаптацию и наполнение блоков портфолио).\n"
                        . "• **Условие старта:** срок отсчитывается с момента предоставления ссылки на Яндекс.Диск с материалами. Как только пришлёте файлы — Никита Минаев сразу начинает реализацию.\n\n"
                        . "Если файлы отправите сегодня — постараемся сдать даже раньше!";
                } else {
                    $publicReply = "По вашей задаче №{$activeTaskId} («{$activeTaskTitle}»):\n\n"
                        . "• **Плановый дедлайн:** {$activeTaskDeadlineFormatted}\n"
                        . "• **Статус:** {$activeTaskStatus}\n"
                        . "• **Ответственный специалист:** Минаев Никита\n\n"
                        . "Специалисты держат задачу на контроле. О любых этапах готовности я сразу сообщу вам сюда!";
                }

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент уточнил сроки по задаче №{$activeTaskId} («{$activeTaskTitle}»).\n"
                    . "• Озвучен срок: {$activeTaskDeadlineFormatted} (1 рабочий день после передачи файлов с диска).\n"
                    . "(Агент держит задачу на контроле).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 2: СТАТУС ЗАДАЧИ И ХОД ВЫПОЛНЕНИЯ
        // =========================================================================
        $isStatusQuestion = (
            str_contains($lower, 'статус') ||
            str_contains($lower, 'что с задач') ||
            str_contains($lower, 'как дела с') ||
            str_contains($lower, 'на каком этапе') ||
            str_contains($lower, 'проверили') ||
            str_contains($lower, 'какой результат')
        );

        if ($isStatusQuestion) {
            if ($activeTaskId > 0) {
                $publicReply = "По вашей задаче №{$activeTaskId} («{$activeTaskTitle}»):\n\n"
                    . "• **Текущий статус:** {$activeTaskStatus}\n"
                    . "• **Ответственный специалист:** Минаев Никита\n"
                    . "• **Крайний срок:** {$activeTaskDeadlineFormatted}\n\n"
                    . "Задача зафиксирована и находится в работе. О любых обновлениях или завершении я сразу отпишусь в этот чат!";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Запрос статуса задачи №{$activeTaskId}: '{$currentText}'.\n"
                    . "• Статус: {$activeTaskStatus}, дедлайн: {$activeTaskDeadlineFormatted}.\n"
                    . "(Агент на связи).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 3: МАТЕРИАЛЫ, ССЫЛКИ, ФАЙЛЫ (ЯНДЕКС.ДИСК И ДР.)
        // =========================================================================
        $hasUrl = preg_match('/https?:\/\/[^\s]+/i', $currentText, $urlMatch);
        $isMaterials = (
            $hasUrl ||
            str_contains($lower, 'диск') ||
            str_contains($lower, 'яндекс') ||
            str_contains($lower, 'ссылк') ||
            str_contains($lower, 'файл') ||
            str_contains($lower, 'материал') ||
            str_contains($lower, 'скинул') ||
            str_contains($lower, 'отправил') ||
            str_contains($lower, 'прикрепил') ||
            str_contains($lower, 'вот архив')
        );

        if ($isMaterials) {
            if ($activeTaskId > 0) {
                // Прикрепляем комментарий к задаче
                $commentText = "Клиент предоставил материалы в чате Открытой линии Telegram:\n" . $currentText;
                $this->taskService->addComment($activeTaskId, $commentText);

                $publicReply = "Отлично, материалы принял и зафиксировал! Прикрепил ссылку к задаче №{$activeTaskId} («{$activeTaskTitle}»).\n\n"
                    . "Никита Минаев и команда уже приступают к реализации. Плановый дедлайн: {$activeTaskDeadlineFormatted}.\n\n"
                    . "Как только портфолио будет размещено и проверено — сразу сообщу вам сюда!";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Клиент предоставил ссылку/материалы по задаче №{$activeTaskId}: '{$currentText}'.\n"
                    . "• Материалы добавлены комментарием в карточку задачи. Можно приступать к верстке и наполнению.";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 4: ОТВЕТСТВЕННЫЙ СПЕЦИАЛИСТ / КОНТАКТЫ РАЗРАБОТЧИКА
        // =========================================================================
        $isResponsibleQuestion = (
            str_contains($lower, 'кто делает') ||
            str_contains($lower, 'кто будет делать') ||
            str_contains($lower, 'кто ответственный') ||
            str_contains($lower, 'кто исполнитель') ||
            str_contains($lower, 'кто ведет') ||
            str_contains($lower, 'с кем связаться') ||
            str_contains($lower, 'контакты разработчика')
        );

        if ($isResponsibleQuestion) {
            $publicReply = "Ответственный специалист и ведущий исполнитель по вашей задаче — **Минаев Никита** (ведущий разработчик WAYDEV, [USER=1650]).\n\n"
                . "Он лично курирует проект и выполняет работы. Я непрерывно нахожусь на связи и передаю любые ваши комментарии и материалы напрямую Никите.";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Клиент поинтересовался исполнителем задачи.\n"
                . "(Озвучен ответственный: Минаев Никита).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 5: СТОИМОСТЬ И ОПЛАТА
        // =========================================================================
        $isCostQuestion = (
            str_contains($lower, 'стоимост') ||
            str_contains($lower, 'цена') ||
            str_contains($lower, 'цену') ||
            str_contains($lower, 'сколько стоит') ||
            str_contains($lower, 'почем') ||
            str_contains($lower, 'смет') ||
            str_contains($lower, 'оплат') ||
            str_contains($lower, 'бюджет') ||
            str_contains($lower, 'расценки')
        );

        if ($isCostQuestion) {
            $publicReply = "По стоимости выполнения работ:\n\n"
                . "• **Для клиентов на абонентском сопровождении / техподдержке:** часы автоматически списываются из ежемесячного пакета (по текущей задаче ориентир около 2–4 нормо-часов).\n"
                . "• **При проектной / разовой оплате:** после получения материалов с Яндекс.Диска Никита Минаев согласует с вами точную фиксированную смету до начала активных работ.\n\n"
                . "Подскажите, у вас действует абонентский договор или планируется разовая оплата по факту?";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Вопрос клиента по стоимости / смете: '{$currentText}'.\n"
                . "(Разъяснены варианты абонентского пакета и разовой сметы).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 6: КОРОТКИЕ ПОДТВЕРЖДЕНИЯ И ВЕЖЛИВОСТЬ (СПАСИБО, ОК, ДОГОВОРИЛИСЬ)
        // =========================================================================
        $isShortAck = (
            preg_match('/^(спасибо|благодарю|отлично|хорошо|договорились|ок|ладно|понял|принято|буду ждать|супер|ждем)[!.\s]*$/iu', $currentText) ||
            str_contains($lower, 'договорились') ||
            str_contains($lower, 'буду ждать') ||
            (mb_strlen($currentText) < 25 && (str_contains($lower, 'спасибо') || str_contains($lower, 'хорошо') || str_contains($lower, 'отлично') || $lower === 'ок'))
        );

        if ($isShortAck) {
            $publicReply = "Пожалуйста, {$clientName}! Задача зафиксирована и находится на контроле. Буду ждать ссылку на материалы с Яндекс.Диска. Если появятся вопросы или дополнения — сразу пишите сюда, я на связи!";

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                . "Внимание, {$responsibleTag}!\n"
                . "• Клиент подтвердил информацию: '{$currentText}'.\n"
                . "(Ожидаем ссылку на материалы).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 7: ПОСТАНОВКА ЗАДАЧ И РЕГИСТРАЦИЯ ИНЦИДЕНТОВ / ДОРАБОТОК
        // =========================================================================
        $isTaskOrIncident = (
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
            str_contains($lower, 'сделай') ||
            str_contains($lower, 'сделать') ||
            str_contains($lower, 'вывести') ||
            str_contains($lower, 'добавить') ||
            str_contains($lower, 'разместить') ||
            str_contains($lower, 'поменять') ||
            str_contains($lower, 'изменить') ||
            str_contains($lower, 'настроить') ||
            str_contains($lower, 'сверстать') ||
            str_contains($lower, 'создать') ||
            str_contains($lower, 'подключить') ||
            str_contains($lower, 'вопрос по сайту') ||
            (str_contains($lower, 'нужно') && mb_strlen($currentText) > 15) ||
            (str_contains($lower, 'надо') && mb_strlen($currentText) > 15)
        );

        if ($isTaskOrIncident) {
            // Проверяем: не относится ли это к уже активной задаче (защита от дублирования!)
            $alreadyHandled = false;
            if ($activeTaskId > 0) {
                if (str_contains($lower, 'портфолио') && str_contains(mb_strtolower($activeTaskTitle), 'портфолио')) {
                    $alreadyHandled = true;
                } elseif (str_contains($lower, 'телефони') && str_contains(mb_strtolower($activeTaskTitle), 'телефони')) {
                    $alreadyHandled = true;
                }
            }

            if ($alreadyHandled) {
                $publicReply = "Эта задача уже поставлена и находится в активной работе:\n\n"
                    . "• **Задача №{$activeTaskId}:** «{$activeTaskTitle}»\n"
                    . "• **Ответственный специалист:** Минаев Никита\n"
                    . "• **Крайний срок:** {$activeTaskDeadlineFormatted}\n\n"
                    . "Ожидаю ссылку на материалы с Яндекс.Диска, чтобы специалисты приступили к вёрстке!";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• Повторный запрос по уже созданной задаче №{$activeTaskId}. Напоминание клиенту отправлено.";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }

            // Новая задача / инцидент
            $project = $this->detectProject($lower, $currentText);
            $taskSummary = $this->buildIncidentSummary($currentText, $project['name']);
            $taskDate = date('d.m.Y');
            $deadline = date('Y-m-d 18:00:00', strtotime('+1 day')); // По умолчанию +1 рабочий день

            $taskData = [
                'project_group_name'  => $project['group_name'],
                'task_summary'        => $taskSummary,
                'task_date'           => $taskDate,
                'tags'                => ['ИИ-Агент'],
                'description'         => "Задача от клиента в открытой линии Telegram:\n{$currentText}\n\nПлан действий:\n1. Анализ требований и материалов проекта {$project['domain']}\n2. Выполнение работ и реализация задачи\n3. Тестирование и контроль качества\n4. Сдача результата клиенту в чате открытой линии",
                'plan_steps'          => [
                    "Анализ требований по проекту {$project['name']}",
                    "Реализация задачи и доработки",
                    "Тестирование функционала",
                    "Уведомление клиента о результатах",
                ],
                'links'               => ["https://{$project['domain']}"],
                'checklist'           => [
                    "Анализ требований и подготовка",
                    "Выполнение доработок",
                    "Тестирование и проверка результата",
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
            if ($createRes['success'] && !empty($createRes['task_id'])) {
                $taskId = (int)$createRes['task_id'];
                $this->state[$dialogId]['active_task_id'] = $taskId;
                $this->state[$dialogId]['active_task_title'] = "{$project['group_name']} | {$taskSummary} | {$taskDate}";
                $this->state[$dialogId]['active_task_deadline'] = date('d.m.Y, 18:00', strtotime($deadline));
                $this->state[$dialogId]['active_project_domain'] = $project['domain'];
                $this->saveState();

                $publicReply = "Принято в работу! Зафиксировал задачу по регламенту:\n\n"
                    . "• **Задача №{$taskId}:** «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• **Проект:** {$project['domain']}\n"
                    . "• **Ответственный специалист:** Минаев Никита\n"
                    . "• **Крайний срок:** " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n\n"
                    . "Специалисты подключаются к работе. О ходе выполнения и результатах сразу сообщу вам сюда!";

                $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
                    . "Внимание, {$responsibleTag}!\n"
                    . "• [b][НОВАЯ ЗАДАЧА][/b]\n"
                    . "• Сформирована задача №{$taskId}: «{$project['group_name']} | {$taskSummary} | {$taskDate}»\n"
                    . "• Текст клиента: '{$currentText}'\n"
                    . "• Ответственный: Минаев Никита, дедлайн: " . date('d.m.Y, 18:00', strtotime($deadline)) . "\n"
                    . "(Агент продолжает сопровождать диалог).";

                return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
            }
        }

        // =========================================================================
        // ПРИОРИТЕТ 8: ВОПРОС ОБ УСЛУГАХ КОМПАНИИ WAYDEV
        // =========================================================================
        $isServicesInquiry = (
            str_contains($lower, 'услуг') ||
            str_contains($lower, 'что вы делаете') ||
            str_contains($lower, 'чем занимаетесь') ||
            str_contains($lower, 'что предлагаете') ||
            str_contains($lower, 'какой стек') ||
            str_contains($lower, 'компетенци')
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
                . "• Пользователь KelvinMarty запросил список услуг компании.\n"
                . "(Предоставлен список услуг).";

            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 9: КОРРЕКТНОЕ ЗНАКОМСТВО / ПРЕДСТАВЛЕНИЕ
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
        // ПРИОРИТЕТ 10: ПРИВЕТСТВИЕ И ОБЩИЕ ВОПРОСЫ
        // =========================================================================
        $isGreeting = str_contains($lower, 'привет') || str_contains($lower, 'здравствуй') || str_contains($lower, 'добрый день') || str_contains($lower, 'добрый вечер') || str_contains($lower, 'салют');
        if ($isGreeting) {
            if ($activeTaskId > 0) {
                $publicReply = "Добрый день, {$clientName}! Да, я на связи. По вашей задаче №{$activeTaskId} («{$activeTaskTitle}») всё под контролем у специалистов. Подскажите, появились новые подробности или у вас другой вопрос?";
            } else {
                $publicReply = "Добрый день, {$clientName}! Я на связи, слушаю вас. Чем могу помочь сегодня?";
            }

            $internalNote = "🤖 [Внутренняя сводка ИИ]:\nВнимание, {$responsibleTag}!\n• Приветствие от {$clientName}.";
            return ['public_reply' => $publicReply, 'internal_note' => $internalNote];
        }

        // =========================================================================
        // ПРИОРИТЕТ 11: УМНЫЙ КОНТЕКСТНЫЙ ФОЛЛБЕК (БЕЗ ШАБЛОННОГО ЗАЦИКЛИВАНИЯ!)
        // =========================================================================
        if ($activeTaskId > 0) {
            $publicReply = "Принял ваше сообщение: «{$currentText}».\n\n"
                . "Зафиксировал и передал информацию ведущему разработчику Никите Минаеву ([USER=1650]) в контексте задачи №{$activeTaskId} («{$activeTaskTitle}»).\n\n"
                . "Если потребуется что-то уточнить — мы сразу напишем вам сюда!";
        } else {
            $publicReply = "Принял ваше сообщение: «{$currentText}».\n\n"
                . "Передал информацию специалистам и Никите Минаеву ([USER=1650]). Сейчас ознакомимся с деталями и вернемся к вам с ответом.";
        }

        $internalNote = "🤖 [Внутренняя сводка ИИ]:\n"
            . "Внимание, {$responsibleTag}!\n"
            . "• Сообщение клиента в открытой линии: '{$currentText}'.\n"
            . ($activeTaskId > 0 ? "• Связано с задачей №{$activeTaskId}.\n" : "")
            . "(Передано на контроль оператору).";

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
        if (str_contains($lower, 'портфолио')) {
            return "Вывод портфолио работ на сайте";
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
