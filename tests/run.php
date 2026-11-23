<?php
declare(strict_types=1);
// Offline suite: no project settings or real credentials are loaded.
spl_autoload_register(function(string $class): void {
    $prefix='Bitrix24Mcp\\';
    if (str_starts_with($class,$prefix)) { require dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; }
});
use Bitrix24Mcp\Core\{Database,Store,Policy,Env,Logger,BitrixClient};
use Bitrix24Mcp\Services\{CaseService,ChatService,TaskService,Worker,AgentAdapter,Monitor,DialogArchive,MailService,CrmService,ContractService};
use Bitrix24Mcp\Mcp\McpRouter;

$root=sys_get_temp_dir().'/b24-tests-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
putenv('B24_DATA_DIR='.$root);putenv('B24_SEND_ENABLED=true');putenv('B24_AGENT_URL');putenv('B24_AGENT_COMMAND');
putenv('C_REST_WEB_HOOK_URL=https://unit.invalid/rest/99/unit/');
Logger::init($root);
$profile=['portal_id'=>'unit','external_dialogs'=>['chat1','chat2'],'external_authors'=>['chat1'=>[10],'chat2'=>[20]],
    'employee_ids'=>[1650,882,612,1],'internal_dialogs'=>['chat500'],'actor_id'=>99,'mailbox_ids'=>[1],
    'task_ids'=>[],'task_creator_id'=>612,'task_auditor_ids'=>[1650,1],'default_responsible_id'=>882,
    'capabilities'=>['crm.read','chat.read','chat.send_external','chat.send_internal','tasks.read','tasks.create','tasks.comment','mail.read','mail.reply'],
    'initial_history'=>'replay','employee_timeout_seconds'=>60,'max_reminders'=>2];
file_put_contents($root.'/profile.json',json_encode($profile));putenv('B24_PROFILE_FILE='.$root.'/profile.json');
function check(bool $ok,string $message='Assertion failed'): void { if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $f,string $message='Expected rejection'): void { try { $f(); } catch (Throwable) { return; } throw new RuntimeException($message); }
function fixture(?array $p=null,?string $file=null): CaseService {
    global $profile; $db=new Database(new PDO('sqlite:'.($file ?? ':memory:')));$db->migrate();return new CaseService(new Store($db),new Policy($p ?? $profile));
}
function incoming(CaseService $c,string $id='1',string $dialog='chat1',int $author=10): array {
    return $c->ingest(['source'=>'chat','dialog_id'=>$dialog,'message_id'=>$id,'author_id'=>$author,'text'=>'Question '.$id]);
}
function plan(CaseService $c,array $actions): array {
    $event=$c->store->claim('inbox_events',180);check($event!==null,'No event to plan');$case=$c->get($event['data']['case_id']);
    return $c->applyPlan($event['id'],$event['lease_token'],$case['version'],['actions'=>$actions]);
}
class TestChat extends ChatService {
    public array $sent=[];public array $messages=[];public array $result=['success'=>true];
    public function getHistory(string|int $dialogId,int $limit=30): array { return ['success'=>true,'messages'=>$this->messages]; }
    public function sendReply(string|int $dialogId,string $message,array $options=[]): array {
        $this->sent[]=['dialog'=>(string)$dialogId,'message'=>$message,'options'=>$options];return $this->result+['message_id'=>1000+count($this->sent)];
    }
}
class TestTasks extends TaskService {
    public int $created=0;public int $comments=0;
    public function getTask(int $taskId): array { return ['success'=>true,'task'=>['id'=>$taskId,'status'=>2]]; }
    public function createTask(array $data): array { $this->created++;return ['success'=>true,'task_id'=>123]; }
    public function addComment(int $taskId,string $message): array { $this->comments++;return ['success'=>true,'comment_id'=>124]; }
}
class OfflineAgent extends AgentAdapter { public function configured(): bool { return false; } }
class TestApi extends BitrixClient {
    public array $messages=[];public int $calls=0;
    public function call(string $method,array $params=[]): array {
        $this->calls++;
        if ($method==='im.dialog.messages.get') {
            $m=array_values(array_filter($this->messages,fn($m)=>!isset($params['LAST_ID']) || $m['id']<$params['LAST_ID']));
            usort($m,fn($a,$b)=>$b['id']<=>$a['id']);return ['success'=>true,'result'=>['messages'=>array_slice($m,0,50)]];
        }
        return ['success'=>false,'error'=>'offline','result'=>null];
    }
    public function callBatch(array $batch,int $halt=0): array { return ['success'=>false,'error'=>'offline','result'=>null]; }
}
function worker(CaseService $c,?TestChat $chat=null,?TestTasks $tasks=null): Worker { return new Worker($c,new OfflineAgent(),$chat ?? new TestChat(),$tasks ?? new TestTasks(),new MailService(),new TestApi()); }
$tests=[];
$tests['durable deduplication across restarts']=function()use($root){
    $a=fixture(null,$root.'/durable.db');$event=incoming($a);$case=$a->get($event['data']['case_id']);
    $b=fixture(null,$root.'/durable.db');$same=incoming($b);check($event['id']===$same['id']);check(count($b->store->list('inbox_events'))===1);check($b->get($case['id'])['version']===$case['version']);
};
$tests['exclusive event leases and token validation']=function()use($root){
    $a=fixture(null,$root.'/leases.db');incoming($a);$b=fixture(null,$root.'/leases.db');$first=$a->store->claim('inbox_events');check($first!==null);check($b->store->claim('inbox_events')===null);
    rejects(fn()=>$b->store->requireLease('inbox_events',$first['id'],'wrong'));
};
$tests['invalid plans roll back queued writes']=function(){
    $c=fixture();incoming($c);$e=$c->store->claim('inbox_events');$case=$c->get($e['data']['case_id']);
    rejects(fn()=>$c->applyPlan($e['id'],$e['lease_token'],$case['version'],['actions'=>[['type'=>'reply','message'=>'hello'],['type'=>'unknown']]]));
    check(!$c->store->list('outbox_actions'));check($c->store->get('inbox_events',$e['id'])['status']==='processing');
};
$tests['new customer message invalidates stale plan']=function(){
    $c=fixture();$e=incoming($c);$lease=$c->store->claim('inbox_events');$case=$c->get($e['data']['case_id']);incoming($c,'2');
    rejects(fn()=>$c->applyPlan($e['id'],$lease['lease_token'],$case['version'],['actions'=>[['type'=>'reply','message'=>'old']]]));
};
$tests['private channels and allowed employees enforced']=function(){
    $c=fixture();incoming($c);
    rejects(fn()=>plan($c,[['type'=>'ask_employee','employee_id'=>1650,'dialog_id'=>'chat1','question'=>'secret','due_at'=>date(DATE_ATOM,time()+3600)]]));
    check(!$c->store->list('employee_requests'));
};
$tests['employee wait survives restart and resumes correct case']=function()use($root){
    $c=fixture(null,$root.'/wait.db');$event=incoming($c);$chat=new TestChat();
    plan($c,[['type'=>'ask_employee','employee_id'=>1650,'question'=>'When ready?','due_at'=>date(DATE_ATOM,time()+3600)]]);
    worker($c,$chat)->tick();check(count($chat->sent)===1);check($chat->sent[0]['dialog']==='1650');check(!$chat->sent[0]['options']);
    $request=$c->store->list('employee_requests')[0];check($request['status']==='waiting');check($c->get($event['data']['case_id'])['status']==='WAITING_EMPLOYEE');
    $c=fixture(null,$root.'/wait.db');
    $wrong=$c->ingest(['source'=>'chat','dialog_id'=>'1650','message_id'=>'200','author_id'=>882,'text'=>'wrong','reply_to'=>1001]);check($wrong['status']==='unmatched');
    $answer=$c->ingest(['source'=>'chat','dialog_id'=>'1650','message_id'=>'201','author_id'=>1650,'text'=>'Ready Friday','reply_to'=>1001]);check($answer['status']==='ready');
    $case=$c->get($event['data']['case_id']);check($case['status']==='READY_TO_REPLY');check($case['data']['employee_answer']['text']==='Ready Friday');
    plan($c,[['type'=>'reply','message'=>'Готово в пятницу','source_refs'=>[$request['id']]]]);worker($c,$chat)->tick();
    check(count($chat->sent)===2);check($chat->sent[1]['dialog']==='chat1');check($c->get($case['id'])['status']==='WAITING_CLIENT');
};
$tests['staff response cannot be attributed to another customer']=function(){
    $c=fixture();incoming($c);incoming($c,'2','chat2',20);$chat=new TestChat();
    plan($c,[['type'=>'ask_employee','employee_id'=>1650,'question'=>'one','due_at'=>date(DATE_ATOM,time()+3600)]]);worker($c,$chat)->tick();
    plan($c,[['type'=>'ask_employee','employee_id'=>1650,'question'=>'two','due_at'=>date(DATE_ATOM,time()+3600)]]);worker($c,$chat)->tick();
    $r=$c->store->list('employee_requests');$target=$r[0];
    $e=$c->ingest(['source'=>'chat','dialog_id'=>'1650','message_id'=>'333','author_id'=>1650,'text'=>'Answer [REQUEST:'.$target['id'].']']);
    check($e['data']['case_id']===$target['data']['case_id']);check(count($c->store->list('employee_requests','waiting'))===1);
};
$tests['staff answers require source reference']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'ask_employee','employee_id'=>1650,'question'=>'q','due_at'=>date(DATE_ATOM,time()+3600)]]);worker($c)->tick();
    $r=$c->store->list('employee_requests')[0];$c->ingest(['source'=>'chat','dialog_id'=>'1650','message_id'=>'4','author_id'=>1650,'text'=>'[REQUEST:'.$r['id'].'] answer']);
    rejects(fn()=>plan($c,[['type'=>'reply','message'=>'answer without reference']]));
};
$tests['task is created once and subsequent issue updates linked task']=function(){
    $c=fixture();incoming($c);$t=new TestTasks();$chat=new TestChat();
    plan($c,[['type'=>'task','fields'=>['description'=>'Do work']]]);worker($c,$chat,$t)->tick();incoming($c,'2');
    plan($c,[['type'=>'task','message'=>'More information']]);worker($c,$chat,$t)->tick();check($t->created===1);check($t->comments===1);
};
$tests['task precedes confirmation even if plan order is reversed']=function(){
    $c=fixture();incoming($c);$t=new TestTasks();$chat=new TestChat();
    plan($c,[['type'=>'reply','message'=>'Registered'],['type'=>'task','fields'=>['description'=>'Do work']]]);
    worker($c,$chat,$t)->tick();check($t->created===1 && !$chat->sent);worker($c,$chat,$t)->tick();check(count($chat->sent)===1);
};
$tests['unknown delivery is never automatically replayed']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Hello']]);$chat=new TestChat();$chat->result=['success'=>false,'error'=>'transport_error','uncertain'=>true];
    $id=$c->store->list('outbox_actions')[0]['id'];$w=worker($c,$chat);$w->tick();$w->tick();
    check(count(array_filter($chat->sent,fn($m)=>$m['message']==='Hello'))===1);check($c->store->get('outbox_actions',$id)['status']==='delivery_unknown');
};
$tests['failed connection remains retryable and later delivers']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Hello']]);$chat=new TestChat();
    $chat->result=['success'=>false,'error'=>'transport_error','uncertain'=>false,'request_not_sent'=>true];
    $w=worker($c,$chat);$w->tick();$row=$c->store->list('outbox_actions')[0];check($row['status']==='ready');
    $c->store->db->pdo->exec('UPDATE outbox_actions SET available_at=0');$chat->result=['success'=>true];$w->tick();
    check($c->store->get('outbox_actions',$row['id'])['status']==='done');
};
$tests['chat preserves delivery uncertainty and permanent API failures']=function(){
    $api=new class extends BitrixClient {
        public array $response=[];
        public function call(string $method,array $params=[]): array { return $this->response; }
    };
    $chat=new ChatService($api);
    foreach ([['error'=>'http_503','uncertain'=>true],['error'=>'transport_error','request_not_sent'=>true,'uncertain'=>false],['error'=>'access_denied','permanent'=>true]] as $flags) {
        $api->response=['success'=>false]+$flags;$r=$chat->sendReply('chat1','Hello');
        foreach ($flags as $key=>$value) { check($r[$key]===$value); }
    }
};
$tests['expired action lease becomes unknown rather than retried']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Hello']]);$a=$c->store->list('outbox_actions')[0];
    $c->store->db->pdo->exec("UPDATE outbox_actions SET status='processing',lease_until=1");$chat=new TestChat();worker($c,$chat)->tick();check(!$chat->sent);check($c->store->get('outbox_actions',$a['id'])['status']==='delivery_unknown');
};
$tests['operator answer cancels automatic reply']=function(){
    $c=fixture();$e=incoming($c);plan($c,[['type'=>'reply','message'=>'AI reply']]);$chat=new TestChat();$chat->messages=[['id'=>2,'author_id'=>1650,'text'=>'Operator reply']];
    worker($c,$chat)->tick();check(!$chat->sent);check($c->get($e['data']['case_id'])['status']==='HUMAN_OWNED');
};
$tests['new client message cancels pending response']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'old']]);incoming($c,'2');$chat=new TestChat();worker($c,$chat)->tick();check(!$chat->sent);check($c->store->list('outbox_actions')[0]['status']==='cancelled');
};
$tests['preview mode creates drafts without network writes']=function(){
    putenv('B24_SEND_ENABLED=false');try {$c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'draft']]);$chat=new TestChat();worker($c,$chat)->tick();check(!$chat->sent);check($c->store->list('outbox_actions')[0]['status']==='draft');}finally{putenv('B24_SEND_ENABLED=true');}
};
$tests['deadline reminders are persistent and idempotent']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'ask_employee','employee_id'=>1650,'question'=>'q','due_at'=>date(DATE_ATOM,time()+3600)]]);$w=worker($c);$w->tick();
    $r=$c->store->list('employee_requests')[0];$d=$r['data'];$d['due_at']=time()-1;$c->store->update('employee_requests',$r,$d,'waiting');
    check($w->timers()===1);check($w->timers()===0);check(count($c->store->list('inbox_events','ready'))===1);
};
$tests['history reconciliation recovers over 100 messages']=function(){
    $c=fixture();$api=new TestApi();for($i=1;$i<=135;$i++){$api->messages[]=['id'=>$i,'author_id'=>10,'text'=>'m'.$i];}
    $m=new Monitor($c,$api);check($m->dialog('chat1')===135);check(count($c->store->list('inbox_events',null,1000))===135);check($m->dialog('chat1')===0);
};
$tests['first-run new mode skips old external history']=function()use($profile){
    $p=$profile;$p['initial_history']='new';$c=fixture($p);$api=new TestApi();$api->messages=[['id'=>1,'author_id'=>10,'text'=>'old']];
    $m=new Monitor($c,$api);check($m->dialog('chat1')===0);$api->messages[]=['id'=>2,'author_id'=>10,'text'=>'new'];check($m->dialog('chat1')===1);
};
$tests['dialog folder and CRM TTL avoid repeated portal reads']=function()use($profile){
    $a=new DialogArchive(new Policy($profile));$calls=0;$fetch=function()use(&$calls){$calls++;return ['success'=>true,'dossier'=>['company'=>['ID'=>5]]];};
    $x=$a->dossier('chat-cache',$fetch);$y=$a->dossier('chat-cache',$fetch);check($calls===1);check($y['cached'] && !$y['stale']);check(is_file($a->directory('chat-cache').'/crm.json'));
    $a->invalidate('chat-cache');$a->dossier('chat-cache',$fetch);check($calls===2);
    $bad=$a->dossier('chat-cache',fn()=>['success'=>false,'error'=>'offline'],true);check(!$bad['success'] && $bad['stale']);
};
$tests['archive case snapshot does not regress versions']=function()use($profile){
    $a=new DialogArchive(new Policy($profile));$a->snapshot(['version'=>5,'data'=>['dialog_id'=>'chat-snapshot']]);$a->snapshot(['version'=>2,'data'=>['dialog_id'=>'chat-snapshot']]);
    check(json_decode(file_get_contents($a->directory('chat-snapshot').'/case.json'),true)['version']===5);
};
$tests['mail envelope preserves recipient and suppresses outgoing/automatic mail']=function(){
    $m=new MailService();$raw=['id'=>1,'mailboxId'=>1,'mailboxEmail'=>'support@example.org','from'=>'Client <client@example.org>','subject'=>'Re: Order','body'=>'question'];
    $e=$m->event($raw);check($e['mail_reply']['to']===['client@example.org']);check($e['mail_reply']['from']==='support@example.org');
    check($m->event(array_merge($raw,['from'=>'support@example.org']))===null);check($m->event(array_merge($raw,['headers'=>['Auto-Submitted'=>'auto-replied']]))===null);
};
$tests['MCP negotiation, envelope validation and notification semantics']=function(){
    $r=new McpRouter();$x=json_decode($r->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'),true);
    check($x['result']['protocolVersion']==='2025-11-25');check(json_decode($r->handle('{"jsonrpc":"1.0","id":1,"method":"ping"}'),true)['error']['code']===-32600);
    check($r->handle('{"jsonrpc":"2.0","method":"unknown"}')===null);check(json_decode($r->handle('[]'),true)['error']['code']===-32600);
    $x=json_decode($r->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"b24_get_case","arguments":{"case_id":123}}}'),true);check($x['error']['code']===-32602);
    $x=json_decode($r->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'),true);$names=array_column($x['result']['tools'],'name');check(in_array('b24_apply_plan',$names,true));check(!in_array('b24_chat_action',$names,true));check(!in_array('b24_update_deal_stage',$names,true));
};
$tests['failed dossier is not reported as success']=function(){
    $contracts=new class extends ContractService {public function getEntityTypeId(): int {return 5;}};
    $r=(new CrmService(new TestApi(),$contracts))->getClientDossier(['contact_id'=>1]);check(!$r['success']);check($r['status']==='unavailable');
};
$tests['internal comments are not public without explicit prefix']=function(){
    $api=new class extends BitrixClient { public function call(string $method,array $params=[]): array {return ['success'=>true,'result'=>[['POST_MESSAGE'=>'Internal secret']]];} };
    $r=(new TaskService($api))->getTaskComments(1,true);check(!$r['comments']);check((new ChatService())->normalizeDialogId('1650')==='1650');
};
$tests['openline prefix allows matching dialogs and rejects other lines']=function(){
    global $profile; $p=$profile; $p['external_dialogs']=[]; $p['external_authors']=[]; $p['external_openline_prefixes']=['telegrambot|2|'];
    $cases=fixture($p);
    $rejected=$cases->ingest(['source'=>'chat','dialog_id'=>'chat17478','message_id'=>'1','author_id'=>1712,'text'=>'Old line 18','openline_entity_id'=>'wz_telegram_123|18|abc|1712']);
    check($rejected['status']==='unmatched');
    $accepted=$cases->ingest(['source'=>'chat','dialog_id'=>'chat1994','message_id'=>'2','author_id'=>380,'text'=>'Hello waydev_bot','openline_entity_id'=>'telegrambot|2|352465657|380']);
    check($accepted['status']==='ready');
    $cases->store->insert('source_cursors','openline_dialog:chat1994',['dialog_id'=>'chat1994','entity_id'=>'telegrambot|2|352465657|380'],'done');
    $cases->policy->external('chat1994',$cases->store);
    $denied=false; try{$cases->policy->external('chat17478',$cases->store);}catch(DomainException){$denied=true;} check($denied);
    $p['initial_history']='new'; $c2=fixture($p); $api=new TestApi();
    $api->messages=[['id'=>10,'author_id'=>380,'text'=>'very old','date'=>date(DATE_ATOM,time()-86400)],['id'=>11,'author_id'=>380,'text'=>'just arrived','date'=>date(DATE_ATOM,time()-5)]];
    $m=new Monitor($c2,$api); check($m->dialog('chat1994',null,'telegrambot|2|352465657|380')===1);
    $joinApi=new class extends BitrixClient {
        public array $calls=[]; public int $adds=0;
        public function call(string $method,array $params=[]): array {
            $this->calls[]=$method;
            if ($method==='im.message.add' && ++$this->adds===1) { return ['success'=>false,'error'=>'CANCELED','permanent'=>true]; }
            return ['success'=>true,'result'=>$method==='im.message.add' ? 555 : true];
        }
    };
    $sent=(new ChatService($joinApi))->sendReply('chat15870','Hello');
    check($sent['success'] && $sent['message_id']===555 && $joinApi->calls===['im.message.add','imopenlines.operator.answer','im.message.add']);
};
require __DIR__.'/gemini_test.php';
require __DIR__.'/agy_test.php';
require __DIR__.'/task_escalation_test.php';
require __DIR__.'/reliability_test.php';
$failed=0;
foreach($tests as $name=>$test){try{$test();echo 'PASS '.$name."\n";}catch(Throwable $e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n";}}
// Remove only this run's private temporary directory.
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
echo count($tests).' tests, '.$failed." failed\n";exit($failed?1:0);
