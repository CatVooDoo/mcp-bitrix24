<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Core/Bootstrap.php';
use Bitrix24Mcp\Core\{Bootstrap,Store};
use Bitrix24Mcp\Services\{Runtime,Worker,AgentAdapter};
Bootstrap::init();
function expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function http(string $path,array $body=[],bool $auth=true): array {
    $ch=curl_init('http://nginx'.$path);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'], $auth ? ['Authorization: Bearer container-test-token'] : [])]);
    $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);return ['code'=>$code,'json'=>json_decode((string)$raw,true)];
}
function rpc(string $name,array $args=[]): array {
    $r=http('/mcp',['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args ?: new stdClass()]]);
    expect($r['code']===200,'MCP HTTP failure');$v=$r['json']['result'] ?? [];
    expect(empty($v['isError']),'Tool failure: '.json_encode($v));return json_decode($v['content'][0]['text'],true);
}
function event(int $id,int $author,string $dialog,string $text): void {
    $r=http('/webhook',['event'=>'ONIMMESSAGEADD','auth'=>['domain'=>'mock','application_token'=>'container-outgoing-token'],
        'data'=>['PARAMS'=>['DIALOG_ID'=>$dialog,'MESSAGE_ID'=>$id,'FROM_USER_ID'=>$author,'MESSAGE'=>$text]]],false);
    expect($r['code']===200 && $r['json']['status']==='persisted','Webhook persistence failed');
}
function apply(array $actions): array {
    $e=rpc('b24_claim_event')['event'];expect($e!==null,'Missing event');$c=rpc('b24_get_case',['case_id'=>$e['data']['case_id']])['case'];
    return rpc('b24_apply_plan',['event_id'=>$e['id'],'lease_token'=>$e['lease_token'],'expected_version'=>$c['version'],'actions'=>$actions]);
}
try {
    $c=Runtime::cases();expect($c->store->db->driver()==='mysql','Not using MariaDB');
    expect(http('/mcp',['jsonrpc'=>'2.0','id'=>1,'method'=>'ping'],false)['code']===401,'Anonymous request accepted');
    expect(http('/config/secrets.local.json')['code']===404,'Nginx exposed private file');
    expect(http('/autoresponder_line18.php')['code']===404,'Nginx exposed old responder');
    event(1,10,'chat1','Need confirmation');event(1,10,'chat1','Need confirmation');
    expect(count($c->store->all('inbox_events'))===1,'Duplicate webhook changed state');
    $case=apply([['type'=>'ask_employee','employee_id'=>1650,'question'=>'Please confirm','due_at'=>date(DATE_ATOM,time()+3600)]]);
    $manual=new class extends AgentAdapter { public function configured(): bool { return false; } };
    $worker=new Worker($c,$manual);$worker->tick();$request=$c->store->all('employee_requests')[0];expect($request['status']==='waiting','Staff question not delivered');
    event(2,1650,'1650','Confirmed [REQUEST:'.$request['id'].']');
    apply([['type'=>'reply','message'=>'Подтверждено сотрудником','source_refs'=>[$request['id']]]]);
    $worker=new Worker(Runtime::cases(),$manual);$worker->tick();
    $case=$c->get($case['case']['id']);expect($case['status']==='WAITING_CLIENT','Case did not resume after employee reply');
    expect(count($c->store->all('outbox_actions','done'))===2,'Unexpected number of deliveries');
    expect(is_file((new Bitrix24Mcp\Services\DialogArchive($c->policy))->directory('chat1').'/case.json'),'Dialog folder absent');
    // Real HTTP adapter with the mock agent, through durable inbox/outbox.
    event(3,20,'chat2','Question for automatic agent');(new Worker($c))->tick();
    expect(count($c->store->all('outbox_actions','done'))===3,'Automatic adapter did not deliver');
    echo "PASS Docker PHP 8.2 + Nginx + MariaDB: authorization, private routes, deduplication, employee round-trip, archive and automatic HTTP adapter\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
