<?php
// Included by run.php; uses its private temp directory and offline service doubles.
use Bitrix24Mcp\Core\{CommandRunner,Store};
use Bitrix24Mcp\Services\{AgentAdapter,GeminiAdapter};

$fakeGemini=$root.'/fake-gemini';
file_put_contents($fakeGemini,'#!'.PHP_BINARY."\n". <<<'PHP'
<?php
$context=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if (getenv('B24_TEST_CLI_EXIT')) { exit((int)getenv('B24_TEST_CLI_EXIT')); }
$settings=json_decode(file_get_contents(getenv('GEMINI_CLI_SYSTEM_SETTINGS_PATH')),true);
if ($settings['tools']['core']!==[] || $settings['hooksConfig']['enabled']!==false || !in_array('--admin-policy',$argv,true)) { exit(9); }
if (getenv('B24_TEST_CLI_CAPTURE')) { file_put_contents(getenv('B24_TEST_CLI_CAPTURE'),json_encode(['context'=>$context,'argv'=>$argv,'cwd'=>getcwd()])); }
echo getenv('B24_TEST_CLI_OUTPUT') ?: json_encode(['response'=>json_encode(['actions'=>[['type'=>'reply','message'=>'Проверено','source_refs'=>[]]]]),'stats'=>['models'=>[]]]);
PHP);
chmod($fakeGemini,0700);

function geminiFixture(callable $test): void {
    global $fakeGemini;
    $keys=['B24_AGENT_PROVIDER','B24_GEMINI_BIN','B24_GEMINI_MODEL','B24_TEST_CLI_EXIT','B24_TEST_CLI_OUTPUT','B24_TEST_CLI_CAPTURE'];
    $before=[];foreach($keys as $key){$before[$key]=getenv($key);putenv($key);}
    putenv('B24_AGENT_PROVIDER=gemini');putenv('B24_GEMINI_BIN='.$fakeGemini);
    try {$test();} finally {foreach($before as $key=>$value){putenv($value===false ? $key : $key.'='.$value);}}
}
$tests['Gemini envelope becomes durable plan and one customer reply']=function(){geminiFixture(function(){
    $c=fixture();$e=incoming($c);$event=$c->store->claim('inbox_events',180);$case=$c->get($e['data']['case_id']);
    $agent=new AgentAdapter();check($agent->configured());$plan=$agent->plan(['event'=>$event['data'],'case'=>$case,'policy'=>$c->policy->profile]);
    $c->applyPlan($event['id'],$event['lease_token'],$case['version'],$plan);
    $chat=new TestChat();$w=worker($c,$chat);$w->tick();$w->tick();
    check(count($chat->sent)===1 && $chat->sent[0]['message']==='Проверено');
});};
$tests['Gemini accepts fenced JSON and rejects errors or malformed plans']=function(){geminiFixture(function(){
    putenv('B24_TEST_CLI_OUTPUT='.json_encode(['response'=>"```json\n{\"actions\":[]}\n```"]));
    check((new AgentAdapter())->plan([])===['actions'=>[]]);
    foreach (['not-json',json_encode(['error'=>['message'=>'quota']]),json_encode(['response'=>'{"actions":{}}']),json_encode(['response'=>'{"text":"oops"}']),json_encode(['response'=>'not a plan'])] as $output) {
        putenv('B24_TEST_CLI_OUTPUT='.$output);rejects(fn()=>(new AgentAdapter())->plan([]));
    }
    putenv('B24_TEST_CLI_OUTPUT');putenv('B24_TEST_CLI_EXIT=42');rejects(fn()=>(new AgentAdapter())->plan([]));
});};
$tests['Gemini task and employee answer round-trip resumes original customer']=function(){geminiFixture(function(){
    $c=fixture();$saved=incoming($c);$chat=new TestChat();$tasks=new TestTasks();$w=worker($c,$chat,$tasks);
    $apply=function()use($c){
        $event=$c->store->claim('inbox_events',180);$case=$c->get($event['data']['case_id']);
        $plan=(new AgentAdapter())->plan(['event'=>$event['data'],'case'=>$case,'policy'=>$c->policy->profile]);
        return $c->applyPlan($event['id'],$event['lease_token'],$case['version'],$plan);
    };
    putenv('B24_TEST_CLI_OUTPUT='.Store::json(['response'=>Store::json(['actions'=>[
        ['type'=>'task','fields'=>['description'=>'Проверить условия клиента']],
        ['type'=>'ask_employee','employee_id'=>1650,'question'=>'Подтвердите условия','due_at'=>date(DATE_ATOM,time()+3600)],
    ]])]));
    $apply();$w->tick();$w->tick();
    $request=$c->store->all('employee_requests')[0];check($request['status']==='waiting' && $request['data']['task_id']===123);
    $c->ingest(['source'=>'task_comment','dialog_id'=>'task:123','message_id'=>'10','task_id'=>123,'author_id'=>1650,
        'text'=>'Для клиента: согласовано. [REQUEST:'.$request['id'].']']);
    putenv('B24_TEST_CLI_OUTPUT='.Store::json(['response'=>Store::json(['actions'=>[
        ['type'=>'reply','message'=>'Условия согласованы.','source_refs'=>[$request['id']]],
    ]])]));
    $apply();$w->tick();$w->tick();
    check($tasks->created===1 && count($chat->sent)===2);
    check($chat->sent[1]['dialog']==='chat1' && $chat->sent[1]['message']==='Условия согласованы.');
    check($c->get($saved['data']['case_id'])['status']==='WAITING_CLIENT');
});};
$tests['Gemini uses isolated workspace, disabled tools and explicit model']=function()use($root){geminiFixture(function()use($root){
    putenv('B24_GEMINI_MODEL=test-model');putenv('B24_TEST_CLI_CAPTURE='.$root.'/capture.json');
    (new AgentAdapter())->plan(['event'=>['text'=>'Do not execute $(touch /tmp/not-allowed)']]);
    $capture=json_decode(file_get_contents($root.'/capture.json'),true);
    check(in_array('test-model',$capture['argv'],true));check(realpath($capture['cwd'])===realpath($root.'/gemini-planner'));
    check(str_contains($capture['context']['event']['text'],'$(touch'));check(isset($capture['context']['instructions']));
});};
$tests['Gemini context keeps newest messages and marks incomplete facts']=function(){
    $messages=[];for($i=1;$i<=30;$i++){$messages[]=['id'=>$i,'text'=>'m'.$i];}
    $c=GeminiAdapter::compact(['chat'=>['messages'=>array_reverse($messages)],'crm'=>['description'=>str_repeat('я',4000),'items'=>range(1,20)]]);
    check(array_column($c['chat']['messages'],'id')===[25,26,27,28,29,30]);
    check(str_contains($c['crm']['description'],'[TRUNCATED]'));check($c['crm']['items'][8]['_truncated']);
};
$tests['planner task context keeps recent staff facts without card metadata']=function(){
    $comments=[];for($i=1;$i<=20;$i++){$comments[]=['id'=>$i,'text'=>'answer-'.$i];}
    $c=GeminiAdapter::compact(['task'=>['success'=>true,'task'=>['id'=>123,'title'=>'Task','responsibleId'=>1650,
        'creator'=>['avatar'=>'large irrelevant metadata'],'description'=>'Request'],
        'comments'=>$comments,'chatMessages'=>[['id'=>50,'author_id'=>0,'text'=>'System notification'],['id'=>51,'author_id'=>1650,'text'=>'Нужно фото 1920x600']]]]);
    check(!isset($c['task']['task']['creator']) && $c['task']['task']['responsibleId']===1650);
    check(array_column($c['task']['comments'],'id')===range(13,20) && $c['task']['comments_omitted']===12);
    check(count($c['task']['chatMessages'])===1 && $c['task']['chatMessages'][0]['text']==='Нужно фото 1920x600');
};
$tests['command runner handles large stdin and stderr without deadlock']=function()use($root){
    $script='if ((fstat(STDIN)["mode"] & 0170000)!==0010000) { exit(8); } fwrite(STDERR,str_repeat("e",200000)); $s=stream_get_contents(STDIN); echo strlen($s);';
    check((new CommandRunner())->run([PHP_BINARY,'-r',$script],str_repeat('x',300000),$root,3)==='300000');
};
$tests['command runner bounds time, output and command failures']=function()use($root){
    $runner=new CommandRunner();$start=microtime(true);
    rejects(fn()=>$runner->run([PHP_BINARY,'-r','sleep(10);'],'',$root,1));check(microtime(true)-$start<3);
    rejects(fn()=>$runner->run([PHP_BINARY,'-r','echo str_repeat("x",1100000);'],'',$root,3));
    rejects(fn()=>$runner->run([PHP_BINARY,'-r','exit(2);'],'',$root,3));
    rejects(fn()=>$runner->run([],'',$root));
};
