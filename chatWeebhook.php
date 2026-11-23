<?php
declare(strict_types=1);
require_once __DIR__.'/src/Core/Bootstrap.php';
use Bitrix24Mcp\Core\{Bootstrap,HttpGuard,Store};
use Bitrix24Mcp\Services\Runtime;
Bootstrap::init();
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { HttpGuard::fail(405,'POST required'); }
$raw=HttpGuard::body(); $input=$_POST ?: json_decode($raw,true);
if (!is_array($input)) { HttpGuard::fail(400,'Invalid body'); }
$expected=(string)B24_OUTGOING_WEBHOOK_TOKEN;
if ($expected==='') { HttpGuard::fail(503,'Webhook authentication is not configured'); }
if (!hash_equals($expected,(string)($input['auth']['application_token'] ?? $input['application_token'] ?? ''))) { HttpGuard::fail(403,'Invalid token'); }
$host=parse_url((string)C_REST_WEB_HOOK_URL,PHP_URL_HOST);
if (!$host || strtolower((string)($input['auth']['domain'] ?? ''))!==strtolower($host)) { HttpGuard::fail(403,'Portal domain mismatch'); }
$event=strtoupper((string)($input['event'] ?? ''));
$chatEvents=['ONIMBOTMESSAGEADD','ONIMMESSAGEADD','ONIMCONNECTORMESSAGEADD'];
$taskEvents=['ONTASKCOMMENTADD','ONTASKUPDATE','ONTASKADD'];
if (!in_array($event,array_merge($chatEvents,$taskEvents),true)) { HttpGuard::fail(400,'Unsupported event'); }
try {
    $cases=Runtime::cases(); $data=$input['data'] ?? [];
    if (in_array($event,$taskEvents,true)) {
        $f=$data['FIELDS_AFTER'] ?? []; if (!is_array($f)) { $f=[]; }
        $taskId=(int)($f['TASK_ID'] ?? $f['ID'] ?? 0);
        if ($taskId<=0) { HttpGuard::fail(400,'Task ID required'); }
        $payload=['kind'=>'task_notification','task_id'=>$taskId];
        $id=hash('sha256',Store::json([$event,$f,$input['ts'] ?? null]));
        $cases->store->insert('inbox_events',$id,$payload,'notification');
    } else {
        $p=$data['PARAMS'] ?? []; $u=$data['USER'] ?? [];
        $dialog=(string)($p['DIALOG_ID'] ?? '');
        if ($dialog==='') { $chatId=(int)($p['TO_CHAT_ID'] ?? $p['CHAT_ID'] ?? 0); $dialog=$chatId>0 ? 'chat'.$chatId : (string)($p['FROM_USER_ID'] ?? ''); }
        $msgId=(string)($p['MESSAGE_ID'] ?? $p['ID'] ?? '');
        if ($msgId==='' || $dialog==='') { HttpGuard::fail(400,'Message and dialog IDs required'); }
        $truth=fn($v)=>in_array($v,[true,1,'1','Y'],true);
        $cases->ingest(['source'=>'chat','dialog_id'=>$dialog,'message_id'=>$msgId,
            'author_id'=>(int)($p['FROM_USER_ID'] ?? $p['AUTHOR_ID'] ?? $u['ID'] ?? 0),
            'text'=>(string)($p['MESSAGE'] ?? ''),'files'=>$p['FILES'] ?? [],'reply_to'=>$p['REPLY_ID'] ?? null,
            'system'=>$truth($p['SYSTEM'] ?? false),'bot'=>$truth($u['IS_BOT'] ?? false)]);
    }
    // Acknowledge only after the durable transaction has committed.
    header('Content-Type: application/json'); echo json_encode(['result'=>true,'status'=>'persisted']);
} catch (Throwable $e) { HttpGuard::fail(503,'Could not persist event; retry delivery'); }
