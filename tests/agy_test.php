<?php
use Bitrix24Mcp\Services\AgentAdapter;

$fakeAgy=$root.'/fake-agy';
file_put_contents($fakeAgy,'#!'.PHP_BINARY."\n". <<<'PHP'
<?php
$event=json_decode(trim(stream_get_contents(STDIN)),true,512,JSON_THROW_ON_ERROR);
if (($event['event'] ?? '')!=='user' || !is_string($event['message']['content'] ?? null)) { exit(7); }
$context=json_decode($event['message']['content'],true,512,JSON_THROW_ON_ERROR);
if (!in_array('stream-json',$argv,true) || in_array('--resume',$argv,true) || in_array('--dangerously-skip-permissions',$argv,true)) { exit(8); }
$settings=json_decode(file_get_contents($argv[array_search('--settings',$argv,true)+1]),true);
if ($settings['toolPermission']!=='request-review' || !in_array('command(*)',$settings['permissions']['deny'],true)) { exit(9); }
$profile=file_get_contents(getcwd().'/.agents/agents/b24-planner.md');
if (!str_contains($profile,'tools: [finish]') || !str_contains($profile,'subagent: false')) { exit(10); }
if (getenv('B24_TEST_AGY_CAPTURE')) { file_put_contents(getenv('B24_TEST_AGY_CAPTURE'),json_encode(['context'=>$context,'argv'=>$argv])); }
if (getenv('B24_TEST_AGY_OUTPUT')) { echo getenv('B24_TEST_AGY_OUTPUT');exit; }
echo json_encode(['event'=>'init','init'=>['tools'=>[]]])."\n";
echo json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','structured_output'=>['actions'=>[['type'=>'reply','message'=>'Тест через agy','source_refs'=>[]]]],'usage'=>['input_tokens'=>100]]])."\n";
PHP);
chmod($fakeAgy,0700);
function agyFixture(callable $test): void {
    global $fakeAgy;
    $keys=['B24_AGENT_PROVIDER','B24_AGY_BIN','B24_AGY_MODEL','B24_TEST_AGY_OUTPUT','B24_TEST_AGY_CAPTURE'];
    $before=[];foreach($keys as $key){$before[$key]=getenv($key);putenv($key);}
    putenv('B24_AGENT_PROVIDER=agy');putenv('B24_AGY_BIN='.$fakeAgy);
    try {$test();} finally {foreach($before as $key=>$value){putenv($value===false ? $key : $key.'='.$value);}}
}
$tests['agy streaming plan commits and dispatches once']=function(){agyFixture(function(){
    $c=fixture();$saved=incoming($c);$event=$c->store->claim('inbox_events',180);$case=$c->get($saved['data']['case_id']);
    $agent=new AgentAdapter();check($agent->configured());$plan=$agent->plan(['event'=>$event['data'],'case'=>$case,'policy'=>$c->policy->profile]);
    $c->applyPlan($event['id'],$event['lease_token'],$case['version'],$plan);
    $chat=new TestChat();$w=worker($c,$chat);$w->tick();$w->tick();
    check(count($chat->sent)===1 && $chat->sent[0]['message']==='Тест через agy');
});};
$tests['agy rejects missing duplicate failed and invalid result events']=function(){agyFixture(function(){
    $good=json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','response'=>'{"actions":[]}']]);
    foreach (['not json',json_encode(['event'=>'init']),$good."\n".$good,
        json_encode(['event'=>'result','result'=>['status'=>'ERROR','response'=>'{"actions":[]}']]),
        json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','response'=>'{"actions":{}}']]),
        json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','structured_output'=>['actions'=>[]],'finished_reason'=>'print_timeout']])] as $output) {
        putenv('B24_TEST_AGY_OUTPUT='.$output);rejects(fn()=>(new AgentAdapter())->plan([]));
    }
});};
$tests['agy uses schema output even when free text contains multiple answers']=function(){agyFixture(function(){
    putenv('B24_TEST_AGY_OUTPUT='.json_encode(['event'=>'result','result'=>['status'=>'SUCCESS',
        'response'=>'First answer and then another answer','structured_output'=>['actions'=>[['type'=>'reply','message'=>'Из схемы']]]]]));
    check((new AgentAdapter())->plan([])['actions'][0]['message']==='Из схемы');
    putenv('B24_TEST_AGY_OUTPUT'.'={"event":"result","result":{"status":"SUCCESS","structured_output":{"actions":{}}}}');
    rejects(fn()=>(new AgentAdapter())->plan([]));
});};
$tests['agy keeps customer text off argv and preserves user settings']=function()use($root){agyFixture(function()use($root){
    putenv('B24_TEST_AGY_CAPTURE='.$root.'/agy-capture.json');putenv('B24_AGY_MODEL=unit-model');
    (new AgentAdapter())->plan(['event'=>['text'=>'private-customer-text']]);
    $capture=json_decode(file_get_contents($root.'/agy-capture.json'),true);
    check($capture['context']['event']['text']==='private-customer-text');
    check(!str_contains(json_encode($capture['argv']),'private-customer-text'));
    check(in_array('unit-model',$capture['argv'],true));
});};
$tests['stream result completes without waiting for CLI session teardown']=function()use($root){
    $start=microtime(true);
    $out=(new \Bitrix24Mcp\Core\CommandRunner())->run([PHP_BINARY,'-r','echo "done\n";flush();sleep(10);'],'',$root,3,null,
        fn($text)=>str_contains($text,"done\n"));
    check($out==="done\n" && microtime(true)-$start<2);
};
$tests['agy completed response JSON uses same schema and timeouts still fail']=function(){agyFixture(function(){
    putenv('B24_TEST_AGY_OUTPUT='.json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','response'=>'{"actions":[{"type":"reply","message":"Готово"}]}']]));
    check((new AgentAdapter())->plan([])['actions'][0]['type']==='reply');
    putenv('B24_TEST_AGY_OUTPUT='.json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','response'=>'{"actions":[{"type":"delete_everything"}]}']]));
    rejects(fn()=>(new AgentAdapter())->plan([]));
    putenv('B24_TEST_AGY_OUTPUT='.json_encode(['event'=>'result','result'=>['status'=>'SUCCESS','finished_reason'=>'print_timeout','response'=>'{"actions":[]}']]));
    rejects(fn()=>(new AgentAdapter())->plan([]));
});};
$tests['agy rejects model-effort conflict before invoking CLI']=function(){agyFixture(function(){
    $before=getenv('B24_AGY_EFFORT');putenv('B24_AGY_EFFORT=low');
    try {
        putenv('B24_AGY_MODEL=gemini-3.8-flash-high');rejects(fn()=>(new AgentAdapter())->plan([]));
        putenv('B24_AGY_MODEL=gemini-3.8-flash-low');check((new AgentAdapter())->plan([])['actions'][0]['type']==='reply');
    }finally{putenv($before===false?'B24_AGY_EFFORT':'B24_AGY_EFFORT='.$before);}
});};
