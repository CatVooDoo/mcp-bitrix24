<?php
declare(strict_types=1);
// Synthetic model check. Never reads Bitrix24, claims real events or sends messages.
require dirname(__DIR__).'/src/Core/Bootstrap.php';
use Bitrix24Mcp\Core\{Bootstrap,Store};
use Bitrix24Mcp\Services\AgentAdapter;
Bootstrap::init();
try {
    if (!(new AgentAdapter())->configured()) { throw new RuntimeException('Agent is not configured'); }
    $event=['kind'=>'client_message','source'=>'chat','dialog_id'=>'synthetic','message_id'=>'1','author_id'=>10,'text'=>'Здравствуйте! Вы на связи?'];
    $context=['contract_version'=>'1','event'=>$event,
        'case'=>['id'=>'synthetic-test','version'=>1,'status'=>'NEW','data'=>['source'=>'chat','dialog_id'=>'synthetic',
            'last_client_message_id'=>'1','task_id'=>null,'messages'=>[$event]]],
        'policy'=>['capabilities'=>['chat.send_external'],'external_dialogs'=>['synthetic'],'external_authors'=>['synthetic'=>[10]]]];
    $started=microtime(true);$plan=(new AgentAdapter())->plan($context);
    if (count($plan['actions'] ?? [])!==1 || ($plan['actions'][0]['type'] ?? '')!=='reply' || trim((string)($plan['actions'][0]['message'] ?? ''))==='') {
        throw new RuntimeException('Expected one nonempty reply action; received '.Store::json($plan));
    }
    echo Store::json(['success'=>true,'elapsed_seconds'=>round(microtime(true)-$started,2),'plan'=>$plan])."\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
