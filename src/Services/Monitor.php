<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{BitrixClient,Store};

/** Event reconciliation is independent of the agent and never acknowledges its jobs. */
class Monitor
{
    public function __construct(private readonly CaseService $cases, private readonly BitrixClient $api=new BitrixClient(),
        private readonly MailService $mail=new MailService()) {}
    public function tick(): array
    {
        $errors=[]; $count=0; $s=$this->cases->store;
        if (!$s->get('source_cursors','identity')) {
            $r=$this->api->call('profile');
            if (!empty($r['success'])) { $s->insert('source_cursors','identity',['actor_id'=>(int)($r['result']['ID'] ?? 0)],'done'); }
        }
        $openlineDialogs=$this->discoverOpenLineDialogs($errors);
        $dialogs=array_merge($this->cases->policy->get('external_dialogs',[]),$this->cases->policy->get('internal_dialogs',[]),array_keys($openlineDialogs));
        foreach ($s->all('employee_requests','waiting') as $r) {
            if (($r['data']['channel'] ?? '')!=='task') { $dialogs[]=$r['data']['dialog_id']; }
        }
        foreach (array_unique($dialogs) as $dialog) {
            try { $count+=$this->dialog((string)$dialog,null,$openlineDialogs[(string)$dialog] ?? null); } catch (\Throwable $e) { $errors[$dialog]=$e->getMessage(); }
        }
        $taskIds=$this->cases->policy->get('task_ids',[]);
        foreach ($s->all('cases',null) as $case) { if (!empty($case['data']['task_id'])) { $taskIds[]=(int)$case['data']['task_id']; } }
        foreach (array_unique($taskIds) as $id) {
            try { $count+=$this->task((int)$id); } catch (\Throwable $e) { $errors['task:'.$id]=$e->getMessage(); }
        }
        foreach ($this->cases->policy->get('mailbox_ids',[]) as $id) {
            try { $count+=$this->mailbox((int)$id); } catch (\Throwable $e) { $errors['mail:'.$id]=$e->getMessage(); }
        }
        $old=$s->get('source_cursors','monitor_health'); $value=['checked_at'=>time(),'errors'=>$errors,'ingested'=>$count];
        if ($old) { $s->update('source_cursors',$old,$value,'done'); } else { $s->insert('source_cursors','monitor_health',$value,'done'); }
        return $value;
    }
    public function dialog(string $dialog, ?int $taskId=null, ?string $openlineEntityId=null): int
    {
        $s=$this->cases->store; $key='dialog:'.hash('sha256',$dialog); $cursor=$s->get('source_cursors',$key);
        $boundary=(int)($cursor['data']['message_id'] ?? 0); $last=null; $messages=[]; $count=0;
        do {
            $params=['DIALOG_ID'=>$dialog,'LIMIT'=>50]; if ($last!==null) { $params['LAST_ID']=$last; }
            $r=$this->api->call('im.dialog.messages.get',$params);
            if (empty($r['success'])) { throw new \RuntimeException('History unavailable'); }
            $batch=$r['result']['messages'] ?? []; if (!$batch) { break; }
            $ids=array_map(fn($m)=>(int)$m['id'],$batch); $min=min($ids);
            foreach ($batch as $m) { if ((int)$m['id']>$boundary) { $messages[(int)$m['id']]=$m; } }
            if ($last!==null && $min>=$last) { throw new \RuntimeException('History pagination did not advance'); }
            $last=$min;
            if (count($messages)>100000) { throw new \RuntimeException('History exceeds reconciliation budget; choose an initial boundary'); }
        } while ($min>$boundary && count($batch)>=50);
        if (!$messages) { return 0; }
        ksort($messages); $latest=max(array_keys($messages));
        $initialNew=!$cursor && $this->cases->policy->get('initial_history','new')==='new';
        // Internal waits may predate this monitor's first pass: never skip an answer to an active request.
        $activeWait=false;
        foreach ($s->all('employee_requests','waiting') as $req) { if ($req['data']['dialog_id']===$dialog) { $activeWait=true; break; } }
        $freshCutoff = time() - 120;
        foreach ($messages as $m) {
            $occurredAt = !empty($m['date']) ? (strtotime((string)$m['date']) ?: 0) : 0;
            $isFreshOpenLine = $openlineEntityId !== null && $occurredAt >= $freshCutoff;
            if ($initialNew && !$activeWait && $taskId===null && !$isFreshOpenLine) { continue; }
            $system=in_array($m['system'] ?? $m['params']['SYSTEM'] ?? false,[true,1,'1','Y'],true);
            $event=['source'=>'chat','dialog_id'=>$dialog,'message_id'=>(string)$m['id'],
                'author_id'=>(int)($m['author_id'] ?? 0),'text'=>(string)($m['text'] ?? ''),
                'occurred_at'=>$occurredAt ?: time(),'reply_to'=>$m['reply_id'] ?? $m['params']['REPLY_ID'] ?? null,'files'=>$m['params']['FILE_ID'] ?? [],'system'=>$system,'task_id'=>$taskId];
            if ($openlineEntityId!==null) { $event['openline_entity_id']=$openlineEntityId; }
            $this->cases->ingest($event);
            $count++;
        }
        $data=['dialog_id'=>$dialog,'message_id'=>$latest,'checked_at'=>time()];
        if ($cursor) { $s->update('source_cursors',$cursor,$data,'done'); } else { $s->insert('source_cursors',$key,$data,'done'); }
        return $count;
    }
    public function task(int $taskId): int
    {
        $s=$this->cases->store;
        $linked=array_values(array_filter($s->all('cases',null),fn($c)=>(int)($c['data']['task_id'] ?? 0)===$taskId));
        if (!$linked && !in_array($taskId,$this->cases->policy->get('task_ids',[]),true)) { throw new \DomainException('Task is not configured or linked to a case'); }
        $r=$this->api->call('tasks.task.get',['taskId'=>$taskId]);
        if (empty($r['success'])) { throw new \RuntimeException('Task unavailable'); }
        $task=$r['result']['task'] ?? []; $count=0;
        $responsible=(int)($task['responsibleId'] ?? $task['RESPONSIBLE_ID'] ?? 0);$ownerChanged=false;
        if ($responsible && $this->cases->policy->employee($responsible)) {
            $s->db->transaction(function()use($s,$linked,$taskId,$responsible,&$ownerChanged){
                foreach($linked as $case){
                    $case=$this->cases->get($case['id']);
                    if((int)($case['data']['task_responsible_id'] ?? 0)!==$responsible){
                        $d=$case['data'];$d['task_responsible_id']=$responsible;$s->update('cases',$case,$d,$case['status']);$ownerChanged=true;
                    }
                }
                foreach($s->all('employee_requests','waiting') as $request){
                    if(($request['data']['channel'] ?? '')==='task' && (int)$request['data']['task_id']===$taskId
                        && (int)$request['data']['employee_id']!==$responsible){
                        $d=$request['data'];$d['employee_id']=$responsible;$s->update('employee_requests',$request,$d,'waiting');$ownerChanged=true;
                    }
                }
            });
            if($ownerChanged){
                // A reply may have arrived just before the reassignment was observed.
                foreach($s->scan('inbox_events','unmatched') as $event){
                    if((int)($event['data']['task_id'] ?? 0)===$taskId && (int)($event['data']['author_id'] ?? 0)===$responsible){
                        $this->cases->ingest($event['data']);
                    }
                }
            }
        }
        if (!empty($task['chatId'])) { $count+=$this->dialog('chat'.$task['chatId'],$taskId); }
        // Legacy comments are supported alongside new-card chat messages.
        $r=$this->api->call('task.commentitem.getlist',[$taskId,['ID'=>'ASC'],[],['NAV_PARAMS'=>['nPageSize'=>50,'iNumPage'=>1]]]);
        if (!empty($r['success'])) {
            $page=1;
            do {
                $comments=$r['result'] ?? [];
                foreach ($comments as $c) {
                    if (!is_array($c) || empty($c['ID'])) { continue; }
                    $this->cases->ingest(['source'=>'task_comment','dialog_id'=>'task:'.$taskId,'message_id'=>(string)$c['ID'],
                        'occurred_at'=>strtotime((string)($c['POST_DATE'] ?? '')) ?: 0,'task_id'=>$taskId,'author_id'=>(int)($c['AUTHOR_ID'] ?? 0),'text'=>(string)($c['POST_MESSAGE'] ?? '')]); $count++;
                }
                if (count($comments)<50) { break; }
                $r=$this->api->call('task.commentitem.getlist',[$taskId,['ID'=>'ASC'],[],['NAV_PARAMS'=>['nPageSize'=>50,'iNumPage'=>++$page]]]);
                if ($page>1000) { throw new \RuntimeException('Legacy comment pagination exceeded budget'); }
            } while (!empty($r['success']));
        }
        foreach ($linked as $case) {
            $key='task-state:'.$case['id']; $old=$s->get('source_cursors',$key);
            $fingerprint=hash('sha256',Store::json([$task['status'] ?? null,$task['deadline'] ?? null,$task['changedDate'] ?? null]));
            $freshCase=$this->cases->get($case['id']);
            $eventAlreadyQueued=$freshCase['data']['latest_event_id']!==$case['data']['latest_event_id'];
            if ($old && $old['data']['fingerprint']!==$fingerprint && $case['status']!=='HUMAN_OWNED' && !$eventAlreadyQueued) {
                $s->db->transaction(function () use ($s,$case,$task,$taskId,$fingerprint) {
                    $id=hash('sha256','taskupdate|'.$case['id'].'|'.$fingerprint);
                    if ($s->insert('inbox_events',$id,['case_id'=>$case['id'],'kind'=>'task_updated','task_id'=>$taskId,'task'=>$task])) {
                        $fresh=$this->cases->get($case['id']);$d=$fresh['data'];$d['latest_event_id']=$id; $s->update('cases',$fresh,$d,$fresh['status']);
                    }
                });
            }
            if ($old) { $s->update('source_cursors',$old,['fingerprint'=>$fingerprint],'done'); } else { $s->insert('source_cursors',$key,['fingerprint'=>$fingerprint],'done'); }
        }
        return $count;
    }
    public function mailbox(int $mailbox): int
    {
        $this->cases->policy->mail($mailbox); $s=$this->cases->store; $key='mailbox:'.$mailbox;
        $cursor=$s->get('source_cursors',$key); $until=time();
        $from=(int)($cursor['data']['timestamp'] ?? ($this->cases->policy->get('initial_history','new')==='new' ? $until : $until-86400*30));
        $count=0;
        for ($page=1;$page<=10000;$page++) {
            $r=$this->mail->call('mail.message.list',['mailboxId'=>$mailbox,'dateFrom'=>date(DATE_ATOM,$from-300),'dateTo'=>date(DATE_ATOM,$until),'pagination'=>['page'=>$page,'limit'=>200]]);
            if (empty($r['success'])) { throw new \RuntimeException('Mailbox unavailable: '.($r['error'] ?? 'unknown')); }
            $items=$r['result']['result']['items'] ?? [];
            foreach ($items as $item) {
                $event=$this->mail->event($item);
                if ($event) { $this->cases->ingest($event); $count++; }
            }
            if (count($items)<200) { break; }
            if ($page===10000) { throw new \RuntimeException('Mail reconciliation budget exceeded'); }
        }
        $data=['timestamp'=>$until,'mailbox_id'=>$mailbox];
        if ($cursor) { $s->update('source_cursors',$cursor,$data,'done'); } else { $s->insert('source_cursors',$key,$data,'done'); }
        return $count;
    }
    /** Discover OpenLine dialogs matching configured external_openline_prefixes. */
    private function discoverOpenLineDialogs(array &$errors): array
    {
        if (empty($this->cases->policy->get('external_openline_prefixes', []))) { return []; }
        $found = [];
        try {
            $recent = $this->api->call('im.recent.get', ['SKIP_OPENLINES' => 'N']);
            foreach ($recent['result']['items'] ?? [] as $item) {
                $chat = $item['chat'] ?? [];
                $entityId = (string)($chat['entity_id'] ?? '');
                $dialogId = (string)($item['id'] ?? '');
                if ($dialogId !== '' && ($chat['entity_type'] ?? '') === 'LINES' && $this->cases->policy->externalOpenLineAllowed($entityId)) {
                    $this->registerOpenLineDialog($dialogId, $entityId);
                    $found[$dialogId] = $entityId;
                }
            }
        } catch (\Throwable $e) { $errors['openlines_recent'] = $e->getMessage(); }
        try {
            $acts = $this->api->call('crm.activity.list', [
                'order' => ['ID' => 'DESC'],
                'filter' => ['PROVIDER_ID' => 'IMOPENLINES_SESSION'],
                'select' => ['ID', 'PROVIDER_PARAMS'],
            ]);
            $s = $this->cases->store;
            foreach ($acts['result'] ?? [] as $act) {
                $userCode = (string)($act['PROVIDER_PARAMS']['USER_CODE'] ?? '');
                if (!$this->cases->policy->externalOpenLineAllowed($userCode)) { continue; }
                $ucKey = 'openline_user_code:' . hash('sha256', $userCode);
                $cached = $s->get('source_cursors', $ucKey);
                $dialogId = (string)($cached['data']['dialog_id'] ?? '');
                if ($dialogId === '' || (time() - (int)($cached['data']['checked_at'] ?? 0)) >= 600) {
                    $d = $this->api->call('imopenlines.dialog.get', ['USER_CODE' => $userCode]);
                    $res = $d['result'] ?? [];
                    $resolved = (string)($res['dialog_id'] ?? (!empty($res['id']) ? 'chat' . $res['id'] : ''));
                    if ($resolved !== '') {
                        $dialogId = $resolved;
                        $ucData = ['user_code' => $userCode, 'dialog_id' => $dialogId, 'checked_at' => time()];
                        if ($cached) { $s->update('source_cursors', $cached, $ucData, 'done'); } else { $s->insert('source_cursors', $ucKey, $ucData, 'done'); }
                    }
                }
                if ($dialogId !== '') {
                    $this->registerOpenLineDialog($dialogId, $userCode);
                    $found[$dialogId] = $userCode;
                }
            }
        } catch (\Throwable $e) { $errors['openlines_crm'] = $e->getMessage(); }
        foreach ($this->cases->store->all('cases', null) as $case) {
            if ($case['status'] === 'RESOLVED') { continue; }
            $dialogId = (string)($case['data']['dialog_id'] ?? '');
            if ($dialogId === '') { continue; }
            $cursor = $this->cases->store->get('source_cursors', 'openline_dialog:' . $dialogId);
            $entityId = (string)($cursor['data']['entity_id'] ?? '');
            if ($this->cases->policy->externalOpenLineAllowed($entityId)) {
                $found[$dialogId] = $entityId;
            }
        }
        return $found;
    }
    private function registerOpenLineDialog(string $dialogId, string $entityId): void
    {
        $s = $this->cases->store;
        $key = 'openline_dialog:' . $dialogId;
        $old = $s->get('source_cursors', $key);
        $data = ['dialog_id' => $dialogId, 'entity_id' => $entityId, 'checked_at' => time()];
        if ($old) { $s->update('source_cursors', $old, $data, 'done'); } else { $s->insert('source_cursors', $key, $data, 'done'); }
    }
}
