<?php
use Bitrix24Mcp\Services\SupportKnowledge;

function taskFixture(): \Bitrix24Mcp\Services\CaseService {
    global $profile;$p=$profile;$p['escalation_channel']='task';
    $p['capabilities']=array_values(array_diff($p['capabilities'],['chat.send_internal']));
    return fixture($p);
}
function taskIncoming($c): array {
    return $c->ingest(['source'=>'chat','dialog_id'=>'chat1','message_id'=>'1','author_id'=>10,'text'=>'По проекту Кузбанк нужно разместить слайдер']);
}
$tests['task-only policy rejects direct employee messages']=function(){
    $c=taskFixture();taskIncoming($c);
    rejects(fn()=>plan($c,[['type'=>'ask_employee','employee_id'=>882,'question'=>'q','due_at'=>date(DATE_ATOM,time()+3600)]]));
    check(!$c->store->all('outbox_actions') && !$c->store->all('employee_requests'));
};
$tests['new issue after resolution creates a separate task without old project context']=function(){
    $c=taskFixture();$event=taskIncoming($c);$t=new TestTasks();$w=worker($c,new TestChat(),$t);
    plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    $case=$c->get($event['data']['case_id']);$c->store->update('cases',$case,$case['data'],'RESOLVED');
    incoming($c,'2');$fresh=$c->get($case['id']);
    check($fresh['data']['task_id']===null && count($fresh['data']['messages'])===1);
    plan($c,[['type'=>'task','fields'=>[]]]);$action=$c->store->all('outbox_actions','ready')[0];
    check($action['data']['task_id']===null && $action['data']['fields']['group_id']===99);
    $w->tick();check($t->created===2 && $t->comments===0);
};
$tests['escalation assignee follows owner policy despite model and old task owner']=function(){
    $c=taskFixture();$event=taskIncoming($c);$case=$c->get($event['data']['case_id']);$data=$case['data'];$data['task_responsible_id']=1650;
    $c->store->update('cases',$case,$data,$case['status']);
    plan($c,[['type'=>'task','fields'=>['responsible_id'=>1650]]]);$a=$c->store->all('outbox_actions')[0];
    check($a['data']['fields']['responsible_id']===882);
    check(str_contains($a['data']['fields']['description'],'[USER=882]'));
};
$tests['task escalation uses owner catalog and confirms actual task after success']=function(){
    $c=taskFixture();$e=taskIncoming($c);
    $t=new class extends TestTasks {public array $fields=[];public function createTask(array $data): array {$this->fields=$data;return parent::createTask($data);}};
    plan($c,[['type'=>'task','fields'=>['task_summary'=>'Уточнить размещение слайдера']],['type'=>'reply','message'=>'Задача 999 готова']]);
    check(count($c->store->all('outbox_actions'))===1);
    $chat=new TestChat();$w=worker($c,$chat,$t);$w->tick();check($t->created===1 && !$chat->sent);
    check($t->fields['group_id']===31 && $t->fields['responsible_id']===882);
    check(str_contains($t->fields['title'],'KUZBANK.RU |') && $t->fields['time_estimate_minutes']===15);
    check(str_contains($t->fields['description'],'[REQUEST:') && str_contains($t->fields['description'],'Кузбанк'));
    $w->tick();$w->tick();check(count($chat->sent)===1 && str_contains($chat->sent[0]['message'],'№123'));
    check(!str_contains($chat->sent[0]['message'],'999') && $chat->sent[0]['dialog']==='chat1');
    $r=$c->store->all('employee_requests')[0];check($r['status']==='waiting' && $r['data']['task_id']===123 && $r['data']['channel']==='task');
    check($c->get($e['data']['case_id'])['status']==='WAITING_EMPLOYEE');
};
$tests['task comment wakes correct case and cited answer reaches customer']=function(){
    $c=taskFixture();$saved=taskIncoming($c);plan($c,[['type'=>'task','fields'=>['task_summary'=>'Уточнить']]]);
    $chat=new TestChat();$w=worker($c,$chat);$w->tick();$w->tick();$r=$c->store->all('employee_requests')[0];
    $e=$c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'10','task_id'=>123,'author_id'=>882,
        'occurred_at'=>time()+1,'text'=>'Для клиента: пришлите баннеры, разместим после согласования.']);
    check($e['data']['kind']==='employee_answer' && $e['data']['case_id']===$saved['data']['case_id']);
    plan($c,[['type'=>'reply','message'=>'Пришлите баннеры для согласования.','source_refs'=>[$r['id']]]]);$w->tick();
    check(count($chat->sent)===2 && $chat->sent[1]['dialog']==='chat1');
    check($c->store->get('employee_requests',$r['id'])['status']==='answered');
};
$tests['task follow-up and deadline reminder stay in task, never personal chat']=function(){
    $c=taskFixture();taskIncoming($c);$t=new TestTasks();$chat=new TestChat();$w=worker($c,$chat,$t);
    plan($c,[['type'=>'task','fields'=>['task_summary'=>'Уточнить']]]);$w->tick();$w->tick();
    $r=$c->store->all('employee_requests')[0];
    $c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'10','task_id'=>123,'author_id'=>882,'occurred_at'=>time()+1,'text'=>'Нужно уточнить']);
    plan($c,[['type'=>'task','message'=>'Уточните, какие материалы нужны.','fields'=>[]]]);$w->tick();$w->tick();
    check($t->created===1 && $t->comments===1);
    $waiting=$c->store->all('employee_requests','waiting');check(count($waiting)===1);
    $r=$waiting[0];$d=$r['data'];$d['due_at']=time()-1;$c->store->update('employee_requests',$r,$d,'waiting');
    check($w->timers()===1);$w->tick();check($t->comments===2);
    foreach($chat->sent as $sent){check($sent['dialog']==='chat1','Unexpected personal message');}
    check($c->store->all('outbox_actions','ready')===[]);
};
$tests['webhook actor can answer in task and agent comments do not echo']=function()use($profile){
    $p=$profile;$p['escalation_channel']='task';$p['employee_ids'][]=99;$p['default_responsible_id']=99;
    $c=fixture($p);taskIncoming($c);$chat=new TestChat();$t=new TestTasks();$w=worker($c,$chat,$t);
    plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    $e=$c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'200','task_id'=>123,'author_id'=>99,'occurred_at'=>time()+1,'text'=>'Ответ человека']);
    check($e['data']['kind']==='employee_answer');
    plan($c,[['type'=>'task','message'=>'Нужно ещё уточнение','fields'=>[]]]);$w->tick();
    $e=$c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'124','task_id'=>123,'author_id'=>99,'occurred_at'=>time()+1,'text'=>'Собственный комментарий агента']);
    check($e['status']==='ignored');
};
$tests['owner rules and relevant project catalog are available']=function(){
    $c=taskFixture();$e=taskIncoming($c);$k=SupportKnowledge::context($c->get($e['data']['case_id']));
    check($k['projects']['kuzbank']['group_id']===31 && count($k['projects'])===1);
    check(str_contains(SupportKnowledge::rules(),'612') && str_contains(SupportKnowledge::rules(),'Внутреннюю эскалацию выполнять задачей'));
};
$tests['REST transport accepts workgroup method names and rejects URL injection']=function(){
    $before=getenv('C_REST_WEB_HOOK_URL');putenv('C_REST_WEB_HOOK_URL=');
    try {
        $api=new \Bitrix24Mcp\Core\BitrixClient();
        check($api->call('sonet_group.get')['error']==='webhook_not_configured');
        check($api->call('batch')['error']==='webhook_not_configured');
        rejects(fn()=>$api->call('../sonet_group.get'));rejects(fn()=>$api->call('sonet_group.get?token=x'));
    } finally {putenv($before===false ? 'C_REST_WEB_HOOK_URL' : 'C_REST_WEB_HOOK_URL='.$before);}
};
$tests['task-only agy receives owner rules, project and schema without DM action']=function()use($root){agyFixture(function()use($root){
    $c=taskFixture();$saved=taskIncoming($c);$case=$c->get($saved['data']['case_id']);
    putenv('B24_TEST_AGY_CAPTURE='.$root.'/task-policy-capture.json');
    (new \Bitrix24Mcp\Services\AgentAdapter())->plan(['case'=>$case,'event'=>$saved['data'],'policy'=>$c->policy->profile,'knowledge'=>SupportKnowledge::context($case)]);
    $capture=json_decode(file_get_contents($root.'/task-policy-capture.json'),true);
    check(str_contains($capture['context']['instructions'],'Внутреннюю эскалацию выполнять задачей'));
    check($capture['context']['knowledge']['projects']['kuzbank']['group_id']===31);
    $path=$capture['argv'][array_search('--json-schema',$capture['argv'],true)+1];
    $schema=json_decode(file_get_contents($path),true);
    check(!in_array('ask_employee',$schema['properties']['actions']['items']['properties']['type']['enum'],true));
});};
$tests['real task service enforces creator auditors tags and time tracking']=function(){
    $c=taskFixture();$saved=taskIncoming($c);$fields=SupportKnowledge::taskFields($c->get($saved['data']['case_id']),[],$c->policy);
    $api=new class extends TestApi {
        public array $fields=[];
        public function call(string $method,array $params=[]): array {
            if ($method==='tasks.task.add') {$this->fields=$params['fields'];return ['success'=>true,'result'=>['task'=>['id'=>123]]];}
            return parent::call($method,$params);
        }
    };
    $r=(new \Bitrix24Mcp\Services\TaskService($api))->createTask($fields+['created_by'=>10,'auditors'=>[]]);
    check($r['success'] && $api->fields['CREATED_BY']===612);
    check(in_array(1650,$api->fields['AUDITORS'],true) && in_array(1,$api->fields['AUDITORS'],true));
    check($api->fields['ALLOW_TIME_TRACKING']==='Y' && $api->fields['TIME_ESTIMATE']===900);
    check(in_array('ИИ-Агент',$api->fields['TAGS'],true));
};
$tests['task reassignment recovers prior staff reply and queues one event']=function(){
    $c=taskFixture();taskIncoming($c);plan($c,[['type'=>'task','fields'=>[]]]);$w=worker($c);$w->tick();$w->tick();
    $e=$c->ingest(['source'=>'chat','dialog_id'=>'chat999','message_id'=>'200','task_id'=>123,'author_id'=>1650,'occurred_at'=>time()+1,'text'=>'Нужно изображение']);
    check($e['status']==='unmatched');
    $api=new class extends TestApi {
        public function call(string $method,array $params=[]): array {
            if($method==='tasks.task.get'){return ['success'=>true,'result'=>['task'=>['id'=>123,'responsibleId'=>1650,'status'=>2,'changedDate'=>'new']]];}
            if($method==='task.commentitem.getlist'){return ['success'=>true,'result'=>[]];}
            return parent::call($method,$params);
        }
    };
    $c->store->insert('source_cursors','task-state:'.$c->store->all('cases')[0]['id'],['fingerprint'=>'old'],'done');
    (new \Bitrix24Mcp\Services\Monitor($c,$api))->task(123);
    $request=$c->store->all('employee_requests')[0];check($request['data']['employee_id']===1650 && $request['status']==='answered');
    $event=$c->store->get('inbox_events',$e['id']);check($event['status']==='ready' && $event['data']['kind']==='employee_answer');
    check(count($c->store->all('inbox_events','ready'))===1);
};
$tests['reassignment does not repeat staff answer already cited to customer']=function(){
    $c=taskFixture();taskIncoming($c);plan($c,[['type'=>'task','fields'=>[]]]);$w=worker($c);$w->tick();$w->tick();
    $request=$c->store->all('employee_requests')[0];$case=$c->store->all('cases')[0];
    $e=$c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'200','task_id'=>123,'author_id'=>1650,'occurred_at'=>$request['data']['asked_at'],'text'=>'Нужно изображение']);
    $c->store->insert('outbox_actions','already-cited',['type'=>'reply','case_id'=>$case['id'],'dialog_id'=>'chat1','source_refs'=>[$request['id']],'sent_at'=>time()],'done');
    $rd=$request['data'];$rd['employee_id']=1650;$c->store->update('employee_requests',$request,$rd,'waiting');
    $again=$c->ingest($e['data']);check($again['status']==='done');
    check(!$c->store->all('inbox_events','ready'));
};
