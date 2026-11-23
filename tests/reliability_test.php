<?php
use Bitrix24Mcp\Core\BitrixClient;
use Bitrix24Mcp\Services\{AgentAdapter,Worker,MailService,TaskService};
$tests['prepared reply is delivered before another model turn']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Ready answer']]);incoming($c,'2','chat2',20);
    $agent=new class extends AgentAdapter {
        public int $calls=0;
        public function configured(): bool { return true; }
        public function plan(array $context): array { $this->calls++;throw new RuntimeException('unavailable'); }
    };
    $chat=new TestChat();$w=new Worker($c,$agent,$chat,new TestTasks(),new MailService(),new TestApi());$w->tick();
    check($agent->calls===0 && $chat->sent[0]['message']==='Ready answer');
};
$tests['model outage notifies once and later delivers successful answer']=function(){
    $c=fixture();$event=incoming($c);
    $agent=new class extends AgentAdapter {
        public int $calls=0;
        public function configured(): bool { return true; }
        public function plan(array $context): array {
            if (++$this->calls<3) { throw new RuntimeException('provider unavailable'); }
            return ['actions'=>[['type'=>'reply','message'=>'Recovered answer']]];
        }
    };
    $chat=new TestChat();$w=new Worker($c,$agent,$chat,new TestTasks(),new MailService(),new TestApi());
    $w->tick();check(count($chat->sent)===1 && str_contains($chat->sent[0]['message'],'сохранено'));
    $c->store->db->pdo->exec('UPDATE inbox_events SET available_at=0');$w->tick();check(count($chat->sent)===1);
    $c->store->db->pdo->exec('UPDATE inbox_events SET available_at=0');$w->tick();
    check(count($chat->sent)===2 && $chat->sent[1]['message']==='Recovered answer');
    check($c->store->get('inbox_events',$event['id'])['status']==='done');
};
$tests['confirmed delivery is recovered from history without duplicate or stale notice']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Hello']]);$id=$c->store->all('outbox_actions')[0]['id'];
    $chat=new TestChat();$chat->result=['success'=>false,'error'=>'transport_error','uncertain'=>true];$w=worker($c,$chat);$w->tick();
    $chat->messages=[['id'=>1,'author_id'=>10,'text'=>'Question 1'],['id'=>22,'author_id'=>99,'text'=>'Hello']];
    $c->store->db->pdo->exec("UPDATE outbox_actions SET updated_at=0,available_at=0 WHERE status='delivery_unknown'");$w->tick();
    $a=$c->store->get('outbox_actions',$id);check($a['status']==='done' && $a['data']['external_id']===22);
    check(count($chat->sent)===1);
};
$tests['reply retry needs two complete separated history checks proving absence']=function(){
    $c=fixture();incoming($c);plan($c,[['type'=>'reply','message'=>'Hello']]);$id=$c->store->all('outbox_actions')[0]['id'];
    $chat=new TestChat();$chat->result=['success'=>false,'error'=>'transport_error','uncertain'=>true];$w=worker($c,$chat);$w->tick();
    $chat->messages=[['id'=>1,'author_id'=>10,'text'=>'Question 1']];$chat->result=['success'=>true];
    $c->store->db->pdo->exec("UPDATE outbox_actions SET updated_at=0,available_at=0 WHERE status='delivery_unknown'");$w->tick();
    check($c->store->get('outbox_actions',$id)['status']==='delivery_unknown');
    $w->tick();check($c->store->get('outbox_actions',$id)['status']==='delivery_unknown');
    $c->store->db->pdo->exec("UPDATE outbox_actions SET updated_at=0,available_at=0 WHERE status='delivery_unknown'");$w->tick();
    check($c->store->get('outbox_actions',$id)['status']==='done');
};
$tests['task comment failures preserve uncertainty instead of replaying writes']=function(){
    $api=new class extends BitrixClient {
        public function call(string $method,array $params=[]): array { return ['success'=>false,'error'=>'http_503','uncertain'=>true,'permanent'=>false]; }
    };
    $result=(new TaskService($api))->addComment(123,'Question');
    check(!$result['success'] && $result['uncertain'] && !$result['request_not_sent']);
};
$tests['empty successful task lookup is unavailable rather than a valid task']=function(){
    $api=new class extends BitrixClient {public function call(string $method,array $params=[]): array {return ['success'=>true,'result'=>[]];}};
    $r=(new TaskService($api))->getTask(42);check(!$r['success'] && $r['error']==='task_unavailable');
};
$tests['unavailable linked task is replaced once instead of receiving failed comments']=function(){
    $c=taskFixture();$e=taskIncoming($c);$chat=new TestChat();
    $tasks=new class extends TestTasks {
        public bool $missing=false;
        public function getTask(int $taskId): array {return $this->missing ? ['success'=>false,'error'=>'task_unavailable','task'=>null] : parent::getTask($taskId);}
        public function createTask(array $data): array {$this->created++;return ['success'=>true,'task_id'=>100+$this->created];}
    };
    $w=worker($c,$chat,$tasks);plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    incoming($c,'2');plan($c,[['type'=>'task','message'=>'A new request','fields'=>[]]]);$tasks->missing=true;$w->tick();$w->tick();
    $case=$c->get($e['data']['case_id']);check($case['data']['task_id']===102 && $tasks->created===2 && $tasks->comments===0);
    check($case['data']['previous_task_ids']===[101]);
};
$tests['temporary task lookup failure does not create a replacement task']=function(){
    $c=taskFixture();taskIncoming($c);$tasks=new class extends TestTasks {
        public function getTask(int $taskId): array {return ['success'=>false,'error'=>'transport_error','uncertain'=>true];}
    };
    $w=worker($c,new TestChat(),$tasks);plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    incoming($c,'2');plan($c,[['type'=>'task','message'=>'Follow up','fields'=>[]]]);$w->tick();
    check($tasks->created===1 && $tasks->comments===0);
    check(count($c->store->all('outbox_actions','ready'))===1 && !$c->store->all('outbox_actions','delivery_unknown'));
};
$tests['ongoing delivery failure sends one warning across customer follow-ups']=function(){
    $c=taskFixture();taskIncoming($c);$chat=new TestChat();
    $tasks=new class extends TestTasks {public function createTask(array $data): array {return ['success'=>false,'error'=>'denied','permanent'=>true];}};
    $w=worker($c,$chat,$tasks);plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    incoming($c,'2');plan($c,[['type'=>'task','fields'=>[]]]);$w->tick();$w->tick();
    check(count($chat->sent)===1 && str_contains($chat->sent[0]['message'],'Не удалось подтвердить'));
};
