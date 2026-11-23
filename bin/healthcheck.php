<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/src/Core/Bootstrap.php';
Bitrix24Mcp\Core\Bootstrap::init();
try {
    $monitor=($argv[1] ?? '')==='monitor';
    $row=Bitrix24Mcp\Services\Runtime::cases()->store->get('source_cursors',$monitor ? 'monitor_worker_health' : 'worker_health');
    exit($row && $row['data']['at']>time()-($monitor ? 240 : 360) ? 0 : 1);
} catch (Throwable) { exit(1); }
