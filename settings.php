<?php
declare(strict_types=1);
// Environment always wins. Existing local credentials are retained in an ignored private file.
$secretFile = getenv('B24_SECRETS_FILE') ?: __DIR__ . '/config/secrets.local.json';
$localSecrets = is_file($secretFile) ? json_decode((string)file_get_contents($secretFile), true, 512, JSON_THROW_ON_ERROR) : [];
$defaults = [
    'C_REST_CLIENT_ID'=>'', 'C_REST_CLIENT_SECRET'=>'', 'C_REST_WEB_HOOK_URL'=>'',
    'C_REST_BLOCK_LOG'=>true, 'C_REST_LOG_TYPE_DUMP'=>false,
    'C_REST_LOGS_DIR'=>__DIR__.'/logs/crest/', 'C_REST_IGNORE_SSL'=>false,
    'B24_OUTGOING_WEBHOOK_TOKEN'=>'', 'MCP_HTTP_AUTH_TOKEN'=>'',
    'B24_BOT_ID'=>0, 'B24_BOT_CLIENT_ID'=>'', 'B24_CONTRACTS_ENTITY_TYPE_ID'=>0,
    'B24_PUBLIC_COMMENT_PREFIX'=>'[Публично]', 'B24_DEBOUNCE_SECONDS'=>0,
    'B24_ENABLE_PM_AGENT_TOOLS'=>false, 'OPENCLAW_GATEWAY_URL'=>'', 'OPENCLAW_HOOK_TOKEN'=>''
];
foreach ($defaults as $key=>$default) {
    if (defined($key)) { continue; }
    $env = getenv($key);
    $value = $env === false ? ($localSecrets[$key] ?? $default) : $env;
    if (is_bool($default)) { $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); }
    elseif (is_int($default)) { $value = (int)$value; }
    define($key, $value);
}
unset($localSecrets, $secretFile, $defaults, $key, $value, $default, $env);
