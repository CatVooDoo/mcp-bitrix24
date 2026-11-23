<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/src/Core/Bootstrap.php';
use Bitrix24Mcp\Core\{Bootstrap,Env,Store,BitrixClient};
use Bitrix24Mcp\Services\{Runtime,Monitor,Worker,AgentAdapter,DialogArchive,MailService};
Bootstrap::init();
$command=$argv[1] ?? 'help';
try {
    $cases=Runtime::cases(); $s=$cases->store;
    if ($command==='migrate') { echo "Database ready.\n"; }
    elseif ($command==='doctor') {
        $status=['php'=>PHP_VERSION,'database'=>$s->db->driver(),'profile'=>$cases->policy->get('portal_id'),
            'http_token_configured'=>MCP_HTTP_AUTH_TOKEN!=='','webhook_token_configured'=>B24_OUTGOING_WEBHOOK_TOKEN!=='',
            'tls_verification'=>!C_REST_IGNORE_SSL,'agent_configured'=>(new AgentAdapter())->configured(),
            'automation_enabled'=>Env::bool('B24_AUTOMATION_ENABLED'),'send_enabled'=>Env::bool('B24_SEND_ENABLED'),
            'mail_configured'=>Env::get('B24_REST_V3_URL')!=='','channels'=>$cases->policy->get('external_dialogs',[]),
            'monitor'=>$s->get('source_cursors','monitor_health'),'worker'=>$s->get('source_cursors','worker_health'),
            'delivery_unknown'=>count($s->list('outbox_actions','delivery_unknown',1000))];
        if (in_array('--probe',$argv,true)) {
            $api=new BitrixClient(); $profile=$api->call('profile');
            $status['portal_access']=$profile['success'];
            if (!empty($profile['success'])) {
                $identity=['actor_id'=>(int)($profile['result']['ID'] ?? 0)];$old=$s->get('source_cursors','identity');
                if ($old) { $s->update('source_cursors',$old,$identity,'done'); } else { $s->insert('source_cursors','identity',$identity,'done'); }
            }
            $status['available_dialogs']=$api->call('im.recent.get',['SKIP_OPENLINES'=>'N']);
            foreach ($cases->policy->get('mailbox_ids',[]) as $id) { $status['mailbox_access'][$id]=(new MailService())->call('mail.message.list',['mailboxId'=>$id,'pagination'=>['limit'=>1]])['success']; }
        }
        echo Store::json($status)."\n";
    } elseif ($command==='worker' || $command==='monitor') {
        if (!Env::bool('B24_AUTOMATION_ENABLED')) { throw new RuntimeException('Set B24_AUTOMATION_ENABLED=true after configuring the profile and agent'); }
        $once=in_array('--once',$argv,true);$monitor=new Monitor($cases);$worker=new Worker($cases);$lastMonitor=0;$stop=false;
        $pollInterval=max(1,min(300,(int)Env::get('B24_POLL_INTERVAL','30')));
        if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$stop){$stop=true;});pcntl_signal(SIGINT,function()use(&$stop){$stop=true;}); }
        do {
            if ($command==='monitor' || (Env::bool('B24_WORKER_MONITOR_ENABLED',true) && time()-$lastMonitor>=$pollInterval)) { $monitor->tick();$lastMonitor=time(); }
            if ($command==='worker') { $worker->tick(); }
            $heartbeat=['pid'=>getmypid(),'at'=>time()];
            if($command==='worker'){$heartbeat+=['send_enabled'=>Env::bool('B24_SEND_ENABLED'),'model'=>Env::get('B24_AGY_MODEL'),'effort'=>Env::get('B24_AGY_EFFORT')];}
            $healthKey=$command==='monitor' ? 'monitor_worker_health' : 'worker_health';$old=$s->get('source_cursors',$healthKey);
            if ($old) { $s->update('source_cursors',$old,$heartbeat,'done'); } else { $s->insert('source_cursors',$healthKey,$heartbeat,'done'); }
            if (!$once && !$stop) { sleep($command==='monitor' ? $pollInterval : 1); }
        } while (!$once && !$stop);
    } elseif ($command==='archive') {
        $archive=new DialogArchive($cases->policy);
        foreach ($s->all('cases') as $case) { $archive->snapshot($case); }
        foreach (['inbox_events'=>'events','outbox_actions'=>'actions','employee_requests'=>'requests'] as $table=>$kind) {
            foreach ($s->all($table) as $r) { if (!empty($r['data']['case_id'])) { $c=$cases->get($r['data']['case_id']);$archive->record($c['data']['dialog_id'],$kind,$r['id'],$r); } }
        }
        echo "Private archives rebuilt.\n";
    } elseif ($command==='requeue') {
        $id=$argv[2] ?? '';$row=$s->get('outbox_actions',$id);
        if (!$row || !in_array($row['status'],['draft','dead','delivery_unknown'],true)) { throw new RuntimeException('Only draft/dead/unknown actions can be requeued'); }
        if ($row['status']==='delivery_unknown' && !in_array('--verified-not-sent',$argv,true)) { throw new RuntimeException('Verify remote delivery first; --verified-not-sent is required for unknown delivery'); }
        $s->update('outbox_actions',$row,$row['data'],'ready');$s->audit('admin_requeue',$row['data']['case_id'],['action_id'=>$id]);
        echo "Action queued.\n";
    } else {
        echo "Commands: migrate | doctor [--probe] | worker [--once] | monitor [--once] | archive | requeue ACTION_ID [--verified-not-sent]\n";
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
