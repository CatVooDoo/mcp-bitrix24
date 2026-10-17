<?php
//define('C_REST_CLIENT_ID','local.5c8bb1b0891cf2.87252039');//Application ID
//define('C_REST_CLIENT_SECRET','SakeVG5mbRdcQet45UUrt6q72AMTo7fkwXSO7Y5LYFYNCRsA6f');//Application key
// or
//define('C_REST_WEB_HOOK_URL','https://rest-api.bitrix24.com/rest/1/doutwqkjxgc3mgc1/');//url on creat Webhook

//define('C_REST_CURRENT_ENCODING','windows-1251');
//define('C_REST_IGNORE_SSL',true);//turn off validate ssl by curl
//define('C_REST_LOG_TYPE_DUMP',true); //logs save var_export for viewing convenience
//define('C_REST_BLOCK_LOG',true);//turn off default logs
//define('C_REST_LOGS_DIR', __DIR__ .'/logs/'); //directory path to save the log

// --- CRest Standard Constants Configuration ---
if (!defined('C_REST_WEB_HOOK_URL')) {
    define('C_REST_WEB_HOOK_URL', getenv('C_REST_WEB_HOOK_URL') ?: '');
}
if (!defined('C_REST_BLOCK_LOG')) {
    define('C_REST_BLOCK_LOG', true); // Turn off default CRest stdout/file dump to avoid MCP pollution
}
if (!defined('C_REST_LOGS_DIR')) {
    define('C_REST_LOGS_DIR', __DIR__ . '/logs/crest/');
}
if (!defined('C_REST_IGNORE_SSL')) {
    define('C_REST_IGNORE_SSL', false);
}

// --- Bitrix24 MCP Gateway & Webhook Constants ---
if (!defined('B24_OUTGOING_WEBHOOK_TOKEN')) {
    define('B24_OUTGOING_WEBHOOK_TOKEN', getenv('B24_OUTGOING_WEBHOOK_TOKEN') ?: '');
}

if (!defined('B24_BOT_ID')) {
    define('B24_BOT_ID', (int)(getenv('B24_BOT_ID') ?: 0));
}

if (!defined('B24_BOT_CLIENT_ID')) {
    define('B24_BOT_CLIENT_ID', getenv('B24_BOT_CLIENT_ID') ?: '');
}

if (!defined('B24_CONTRACTS_ENTITY_TYPE_ID')) {
    define('B24_CONTRACTS_ENTITY_TYPE_ID', (int)(getenv('B24_CONTRACTS_ENTITY_TYPE_ID') ?: 0));
}

if (!defined('B24_PUBLIC_COMMENT_PREFIX')) {
    $envPrefix = getenv('B24_PUBLIC_COMMENT_PREFIX');
    define('B24_PUBLIC_COMMENT_PREFIX', $envPrefix !== false ? $envPrefix : '');
}

if (!defined('OPENCLAW_GATEWAY_URL')) {
    define('OPENCLAW_GATEWAY_URL', getenv('OPENCLAW_GATEWAY_URL') ?: 'http://127.0.0.1:19001/hooks/agent');
}

if (!defined('OPENCLAW_HOOK_TOKEN')) {
    define('OPENCLAW_HOOK_TOKEN', getenv('OPENCLAW_HOOK_TOKEN') ?: '');
}

if (!defined('MCP_HTTP_AUTH_TOKEN')) {
    define('MCP_HTTP_AUTH_TOKEN', getenv('MCP_HTTP_AUTH_TOKEN') ?: '');
}

if (!defined('B24_DEBOUNCE_SECONDS')) {
    define('B24_DEBOUNCE_SECONDS', (int)(getenv('B24_DEBOUNCE_SECONDS') ?: 0));
}

if (!defined('B24_ENABLE_PM_AGENT_TOOLS')) {
    $enablePm = getenv('B24_ENABLE_PM_AGENT_TOOLS');
    define('B24_ENABLE_PM_AGENT_TOOLS', $enablePm !== false ? filter_var($enablePm, FILTER_VALIDATE_BOOLEAN) : false);
}