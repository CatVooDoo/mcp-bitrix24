# Bitrix24 CRest MCP Server & Webhook Gateway для OpenClaw

Легковесный, высокопроизводительный шлюз и MCP-сервер (Model Context Protocol) на **PHP 8.1+** с официальной библиотекой **[CRest](https://github.com/bitrix-tools/crest)** для интеграции ИИ-агента **OpenClaw (DeepSeek V4.1 Flash)** с порталом **Битрикс24**.

Архитектура проекта спроектирована с четким разделением на этапы развития:
* **Этап 1 (Текущий — Агент Чата / L1 Support):** Полное управление диалогами в чатах и Открытых линиях со **строгим Read-Only доступом** к CRM (Лиды, Сделки, Компании, Контакты, Смарт-процесс «ДОГОВОРЫ») и Задачам (включая чтение комментариев, результатов и встроенного чата задачи).
* **Этап 2 (Заложен в архитектуру — ПМ-Агент):** Создание и маршрутизация задач, контроль дедлайнов, пинг исполнителей и управление воронками (`src/Mcp/PmAgentTools.php`, отключен по умолчанию флагом `B24_ENABLE_PM_AGENT_TOOLS = false`).

---

## 1. Архитектурные правила и принципы безопасности

1. **Неизменность ядра `CRest`:** Файл `CRest.php` (`crest.php`) используется «как есть» из официального репозитория `bitrix-tools/crest` и **не модифицируется**. Сохранена регистронезависимая совместимость для Linux/Ubuntu.
2. **Правило `settings.php`:** Исходные строки `settings.php` остаются нетронутыми. Все параметры конфигурируются через добавление констант `define(...)` с проверками `if (!defined(...))` и поддержкой переменных окружения `getenv()`.
3. **Точка входа вебхуков чата:** Приемник исходящих событий от Битрикс24 располагается строго в файле `chatWeebhook.php` (с двумя буквами `e`).
4. **Безопасность данных (Guardrails):** На уровне MCP-сервера в Фазе 1 полностью отсутствуют методы на запись/изменение CRM и задач. Агент физически не способен случайно удалить сделку, закрыть задачу или изменить договор.
5. **Чистота STDOUT для MCP:** Отключен любой вывод PHP ошибок и предупреждений в стандартный поток (`display_errors = 0`), логи ведутся в формате JSON в директорию `logs/`.

---

## 2. Структура проекта

```text
/
├── CRest.php                          # Совместимый загрузчик официальной библиотеки crest.php
├── crest.php                          # Официальная библиотека bitrix-tools/crest (НЕ МОДИФИЦИРУЕТСЯ)
├── settings.php                       # Константы CRest и конфигурация шлюза OpenClaw
├── chatWeebhook.php                   # Приемник исходящих вебхуков Битрикс24 -> OpenClaw Gateway
├── mcp_server.php                     # Двухрежимный MCP-сервер (CLI STDIO и HTTP POST JSON-RPC 2.0)
├── src/
│   ├── Core/
│   │   ├── Bootstrap.php              # Безопасный загрузчик, подавление ошибок, PSR-4 автолоадер
│   │   ├── Logger.php                 # Структурированный JSON-логгер (без вывода в STDOUT)
│   │   └── BitrixClient.php           # Обертка над CRest::call и CRest::callBatch с нормализацией ошибок
│   ├── Services/
│   │   ├── CrmService.php             # Read-only Лиды, Сделки, Контакты, Компании + Единое Досье
│   │   ├── ContractService.php        # Read-only Смарт-процесс «ДОГОВОРЫ» (автопоиск entityTypeId)
│   │   ├── TaskService.php            # Read-only Задачи, комментарии, результаты и чаты задач
│   │   └── ChatService.php            # История чатов, отправка ответов, тайпинг, перевод на оператора
│   └── Mcp/
│       ├── McpRouter.php              # Обработчик JSON-RPC 2.0 протокола MCP
│       ├── ChatAgentTools.php         # 11 активных инструментов Фазы 1 (Агент поддержки)
│       └── PmAgentTools.php           # Задел для Фазы 2 (ПМ-Агент, отключен по флагу)
├── logs/                              # Логи приложения (mcp.log, webhook.log, mcp_error.log)
│   ├── cache/                         # Кеш (автоопределенный entityTypeId договоров, debounce)
│   └── crest/                         # Логи вызовов CRest
└── README.md                          # Документация проекта
```

---

## 3. Необходимые права (Scopes) Входящего вебхука в Битрикс24

В Битрикс24 перейдите в раздел **Разработчикам → Другое → Входящий вебхук**, создайте вебхук и отметьте следующие разрешения:
* `crm` — чтение Лидов, Сделок, Контактов, Компаний и Смарт-процесса «ДОГОВОРЫ».
* `task` и `tasks_extended` — чтение карточек задач, чеклистов, результатов и комментариев.
* `im` — чтение сообщений чатов, чатов задач и отправка сообщений.
* `imbot` — ответы в чат от имени бота, отправка индикатора набора текста (typing).
* `imopenlines` — управление сессиями Открытых линий и перевод на живого оператора.
* `user` — чтение ФИО и контактов ответственных сотрудников.
* `sonet_group` — получение названий рабочих групп и проектов.
* `disk` — доступ к метаданным вложений и файлов.

Скопируйте URL вебхука (вида `https://your-domain.bitrix24.ru/rest/1/webhook_token/`).

---

## 4. Конфигурация `settings.php`

Все настройки шлюза и MCP могут быть заданы как константами в `settings.php`, так и переменными окружения:

```php
// --- Стандартные константы CRest ---
define('C_REST_WEB_HOOK_URL', 'https://your-domain.bitrix24.ru/rest/1/your_secret_webhook/');
define('C_REST_BLOCK_LOG', true); // Отключение дампа в stdout для чистоты MCP
define('C_REST_LOGS_DIR', __DIR__ . '/logs/crest/');

// --- Дополнительные константы шлюза OpenClaw и MCP ---
define('B24_OUTGOING_WEBHOOK_TOKEN', 'токен_исходящего_вебхука'); // Проверка auth.application_token
define('B24_BOT_ID', 0); // ID чат-бота (0 = отправка от имени пользователя вебхука через im.message.add)
define('B24_BOT_CLIENT_ID', '');
define('B24_CONTRACTS_ENTITY_TYPE_ID', 0); // 0 = автоматическое определение смарт-процесса "ДОГОВОРЫ"
define('B24_PUBLIC_COMMENT_PREFIX', ''); // Префикс публичных комментариев (например, '[Публично]')

// Настройки связи с OpenClaw (DeepSeek V4.1 Flash)
define('OPENCLAW_GATEWAY_URL', 'http://127.0.0.1:19001/hooks/agent');
define('OPENCLAW_HOOK_TOKEN', 'ваш_токен_openclaw_шлюза');
define('MCP_HTTP_AUTH_TOKEN', 'секретный_токен_для_http_mcp');

// Антидребезг (секунды, 0 = выключен)
define('B24_DEBOUNCE_SECONDS', 0);

// Архитектурный флаг Этапа 2 (ПМ-агент)
define('B24_ENABLE_PM_AGENT_TOOLS', false);
```

---

## 5. Доступные MCP Инструменты (Фаза 1)

MCP-сервер предоставляет **11 строго типизированных инструментов** со спецификацией `inputSchema`:

1. **`b24_get_client_dossier`** — Молниеносный сбор досье клиента за один пакетный запрос `CRest::callBatch`: Контакт, Компания, активные Сделки, Договоры и последние 10 Задач. Аргументы: `contact_id`, `company_id`, `lead_id`, `deal_id`, `chat_id`.
2. **`b24_search_crm_by_comm`** — Поиск сущностей CRM по номеру телефона или адресу email (`crm.duplicate.findbycomm`).
3. **`b24_get_leads`** — Поиск и получение Лидов (`lead_id` или фильтры `company_id`, `contact_id`, `status_id`, `search_query`).
4. **`b24_get_deals`** — Поиск и получение Сделок (`deal_id` или фильтры `company_id`, `contact_id`, `stage_id`, `category_id`, `search_query`).
5. **`b24_get_companies`** — Получение Компаний со всеми пользовательскими полями `UF_CRM_*`.
6. **`b24_get_contacts`** — Получение Контактов (`contact_id`, `company_id` или поиск по ФИО).
7. **`b24_get_contracts`** — Чтение Смарт-процесса «ДОГОВОРЫ» (`crm.item.list` / `crm.item.get` с `camelCase` полями).
8. **`b24_get_tasks`** — Список задач с фильтрацией по CRM (`UF_CRM_TASK` префиксы `C_`, `CO_`, `D_`, `L_`), ответственному, группе или статусу.
9. **`b24_get_task_and_chat_details`** — Исчерпывающие данные по задаче: карточка, результаты (`tasks.task.result.list`), комментарии (`task.commentitem.getlist`) и сообщения встроенного чата задачи (`im.dialog.messages.get`).
10. **`b24_get_chat_history`** — Чтение истории сообщений чата или Открытой линии (`dialog_id`, `limit`).
11. **`b24_chat_action`** — Выполнение действий в диалоге: `reply` (отправка ответа), `typing` (индикатор набора), `transfer_to_operator` (перевод на человека с передачей внутренней системной сводки).

---

## 6. Настройка Nginx и PHP-FPM на Ubuntu Server

В конфигурацию сайта Nginx добавьте:

```nginx
server {
    listen 80;
    server_name b24-mcp.your-domain.com;
    root /var/www/html;
    index index.php;

    client_max_body_size 64M;

    # Точка входа исходящих вебхуков Битрикс24
    location = /chatWeebhook.php {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock; # или php:9000 в docker
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
        include fastcgi_params;
    }

    # HTTP-режим MCP-сервера
    location = /mcp_server.php {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        include fastcgi_params;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

---

## 7. Подключение к OpenClaw (DeepSeek V4.1 Flash)

### Вариант А: Локальный запуск через CLI STDIO

Если агент OpenClaw и сервер Битрикс24 расположены на одном сервере, настройте вызов команды в конфигурации OpenClaw:

```json
{
  "mcpServers": {
    "bitrix24": {
      "command": "php",
      "args": ["/var/www/html/mcp_server.php"],
      "env": {
        "C_REST_WEB_HOOK_URL": "https://your-domain.bitrix24.ru/rest/1/webhook_token/"
      }
    }
  }
}
```

### Вариант Б: Удаленное подключение через HTTP JSON-RPC 2.0

Если агент подключается по сети:

```json
{
  "mcpServers": {
    "bitrix24": {
      "url": "https://b24-mcp.your-domain.com/mcp_server.php",
      "headers": {
        "Authorization": "Bearer секретный_токен_для_http_mcp"
      }
    }
  }
}
```

### Подключение исходящих вебхуков Битрикс24

В Битрикс24 перейдите в **Разработчикам → Другое → Исходящий вебхук**:
* **URL обработчика:** `https://b24-mcp.your-domain.com/chatWeebhook.php`
* **События:** `ONIMBOTMESSAGEADD`, `ONIMMESSAGEADD`, `ONIMCOMMANDADD`.
* Скопируйте `Код авторизации (токен)` и пропишите его в `B24_OUTGOING_WEBHOOK_TOKEN` в `settings.php`.

Благодаря использованию `fastcgi_finish_request()`, вебхук мгновенно отвечает кодом `200 OK`, собирает досье клиента и асинхронно передает задачу в OpenClaw Gateway.
