<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{BitrixClient,Env,Store,Logger};

class Worker
{
    public function __construct(private readonly CaseService $cases, private readonly AgentAdapter $agent = new AgentAdapter(),
        private readonly ChatService $chat = new ChatService(), private readonly TaskService $tasks = new TaskService(),
        private readonly MailService $mail = new MailService(), private readonly BitrixClient $api = new BitrixClient()) {}

    public function tick(): array
    {
        $stats=['planned'=>0,'dispatched'=>0,'reminded'=>0];
        foreach ($this->cases->store->list('inbox_events','notification',100) as $notification) {
            if ($notification['available_at'] > time()) { continue; }
            try {
                (new Monitor($this->cases,$this->api,$this->mail))->task((int)$notification['data']['task_id']);
                $this->cases->store->update('inbox_events',$notification,$notification['data'],'done');
            } catch (\Throwable $e) {
                $data=$notification['data']; $data['last_error']=$e->getMessage();
                $this->cases->store->update('inbox_events',$notification,$data,$e instanceof \DomainException ? 'dead' : 'notification',time()+60);
            }
        }
        $this->recoverUnknown();
        $stats['reminded']=$this->timers();
        // Deliver already prepared replies before waiting for another model turn.
        if ($action=$this->claimAction()) { $this->dispatchChecked($action);$stats['dispatched']++;return $stats; }
        if ($this->agent->configured() && ($event=$this->cases->store->claim('inbox_events',180))) {
            try {
                $case=$this->cases->get($event['data']['case_id']);
                if ($case['status']==='HUMAN_OWNED' || $case['data']['latest_event_id']!==$event['id']) {
                    $this->cases->store->update('inbox_events',$event,$event['data'],'superseded');
                } else {
                    $context=['contract_version'=>'1','event'=>$event['data'],'case'=>$case,'policy'=>$this->cases->policy->profile];
                    $context['knowledge']=SupportKnowledge::context($case);
                    $context['requests']=array_values(array_filter($this->cases->store->all('employee_requests'),
                        fn($r)=>$r['data']['case_id']===$case['id'] && (in_array($r['status'],['queued','waiting'],true)
                            || $r['id']===($case['data']['answer_request_id'] ?? null))));
                    if (!empty($case['data']['task_id'])) { $context['task']=(new TaskService($this->api))->getTaskFullContext((int)$case['data']['task_id']); }
                    if ($case['data']['source']==='chat') { $context['chat']=$this->chat->getHistory($case['data']['dialog_id'],50);
                        $context['crm']=(new DialogArchive($this->cases->policy))->dossier($case['data']['dialog_id'],fn()=>(new CrmService($this->api))->getClientDossier(['chat_id'=>$case['data']['dialog_id']])); }
                    $plan=$this->agent->plan($context);
                    $this->cases->applyPlan($event['id'],$event['lease_token'],$case['version'],$plan);
                    $stats['planned']++;
                }
            } catch (\Throwable $e) {
                $current=$this->cases->store->get('inbox_events',$event['id']);
                if ($current && $current['status']==='processing') {
                    $this->cases->store->retry('inbox_events',$current,$e->getMessage());
                    if (($current['data']['kind'] ?? '')==='client_message') {
                        $final=$current['attempts']>=8;
                        $this->serviceNotice($current['data']['case_id'],$current['id'],$final ? 'planning_final' : 'planning',
                            $final ? 'Сейчас не удаётся подготовить ответ. Ваше сообщение сохранено. Пожалуйста, повторите запрос позже.'
                                : 'Подготовка ответа задерживается. Ваше сообщение сохранено, попробую обработать его ещё раз.');
                    }
                    Logger::warning('Event planning failed; retry scheduled',['event_id'=>$event['id'],
                        'attempt'=>$current['attempts'],'exception'=>get_class($e)],'worker');
                }
            }
        }
        if ($action=$this->claimAction()) { $this->dispatchChecked($action); $stats['dispatched']++; }
        return $stats;
    }
    private function serviceNotice(string $caseId,string $eventId,string $kind,string $message): void
    {
        $s=$this->cases->store;$case=$this->cases->get($caseId);$d=$case['data'];
        if ($d['source']!=='chat' || $d['latest_event_id']!==$eventId || in_array($case['status'],['HUMAN_OWNED','RESOLVED'],true)) { return; }
        $lastSuccess=0;
        foreach ($s->all('outbox_actions','done') as $sent) {
            if (($sent['data']['type'] ?? '')==='reply' && empty($sent['data']['system_notice'])
                && ($sent['data']['case_id'] ?? '')===$caseId) { $lastSuccess=max($lastSuccess,$sent['updated_at']); }
            if (($sent['data']['type'] ?? '')==='reply' && empty($sent['data']['system_notice'])
                && ($sent['data']['case_id'] ?? '')===$caseId && ($sent['data']['client_message_id'] ?? '')===$d['last_client_message_id']) { return; }
        }
        // An ongoing incident must not produce the same warning after every
        // new customer message. Old notices predate notice_kind; match their text.
        foreach ($s->all('outbox_actions') as $notice) {
            if (!empty($notice['data']['system_notice']) && ($notice['data']['case_id'] ?? '')===$caseId
                && in_array($notice['status'],['ready','processing','done'],true) && $notice['updated_at']>max(time()-900,$lastSuccess)
                && (($notice['data']['notice_kind'] ?? '')===$kind || ($notice['data']['message'] ?? '')===$message)) { return; }
        }
        $s->insert('outbox_actions',hash('sha256','service-notice|'.$eventId.'|'.$kind),[
            'case_id'=>$caseId,'event_id'=>$eventId,'type'=>'reply','source'=>'chat','dialog_id'=>$d['dialog_id'],
            'client_message_id'=>$d['last_client_message_id'],'message'=>$message,'system_notice'=>true,'notice_kind'=>$kind]);
    }
    private function dispatchChecked(array $action): void
    {
        $this->dispatch($action);$row=$this->cases->store->get('outbox_actions',$action['id']);
        if (!$row || !empty($row['data']['system_notice'])) { return; }
        if (in_array($row['status'],['dead','delivery_unknown'],true) || ($row['status']==='ready' && $row['attempts']>=2)) {
            $case=$this->cases->get($row['data']['case_id']);
            $message=$row['data']['type']==='task'
                ? 'Не удалось подтвердить передачу запроса менеджеру. Ваше сообщение сохранено; сообщу о результате после подтверждения.'
                : 'Отправка ответа задерживается. Ваше сообщение сохранено, проверяю доставку.';
            $this->serviceNotice($case['id'],$row['data']['event_id'] ?? $case['data']['latest_event_id'],'delivery',$message);
        }
    }
    private function cancelNotices(string $caseId,string $messageId): void
    {
        $s=$this->cases->store;
        foreach ($s->all('outbox_actions','ready') as $notice) {
            if (!empty($notice['data']['system_notice']) && $notice['data']['case_id']===$caseId
                && $notice['data']['client_message_id']===$messageId) {
                $s->update('outbox_actions',$notice,$notice['data'],'cancelled');
            }
        }
    }
    private function claimAction(): ?array
    {
        // Expired sends are never blindly retried: the remote side may already have committed.
        $s=$this->cases->store;
        $q=$s->db->pdo->prepare("SELECT id,version FROM outbox_actions WHERE status='ready' AND available_at<=? ORDER BY available_at,updated_at,id LIMIT 20");
        $q->execute([time()]);
        foreach ($q->fetchAll() as $r) {
            $candidate=$s->get('outbox_actions',$r['id']);
            if (!empty($candidate['data']['depends_on'])) {
                $dependency=$s->get('outbox_actions',$candidate['data']['depends_on']);
                if (!$dependency || $dependency['status']!=='done') { continue; }
            }
            $token=Store::id();
            $u=$s->db->pdo->prepare("UPDATE outbox_actions SET status='processing',lease_token=?,lease_until=?,attempts=attempts+1,version=version+1 WHERE id=? AND version=? AND status='ready'");
            $u->execute([$token,time()+300,$r['id'],$r['version']]);
            if ($u->rowCount()===1) { return $s->get('outbox_actions',$r['id']); }
        }
        return null;
    }
    private function dispatch(array $row): void
    {
        $s=$this->cases->store; $a=$row['data']; $locked=false; $deliveryStarted=false;
        try {
            $q=$s->db->pdo->prepare('UPDATE cases SET lease_token=?,lease_until=? WHERE id=? AND lease_until<?');
            $q->execute([$row['lease_token'],time()+300,$a['case_id'],time()]);
            if ($q->rowCount()!==1) { $s->retry('outbox_actions',$row,'Case is busy'); return; }
            $locked=true; $case=$this->cases->get($a['case_id']);
            if ($case['status']==='HUMAN_OWNED' || $case['status']==='RESOLVED' || $case['data']['last_client_message_id']!==$a['client_message_id']) {
                $s->update('outbox_actions',$row,$a,'cancelled');
                if (isset($a['request_id']) && ($r=$s->get('employee_requests',$a['request_id']))) { $s->update('employee_requests',$r,$r['data'],'cancelled'); }
                return;
            }
            if (!Env::bool('B24_SEND_ENABLED')) { $s->update('outbox_actions',$row,$a,'draft'); return; }
            // A more recent employee reply owns the customer conversation.
            if ($a['type']==='reply' && $a['source']==='chat' && !$this->freshness($case)) {
                $s->update('outbox_actions',$row,$a,'cancelled'); return;
            }
            if (in_array($a['type'],['ask_employee','reminder'],true) && str_starts_with($a['dialog_id'],'chat')) {
                $members=$this->api->call('im.chat.user.list',['CHAT_ID'=>(int)substr($a['dialog_id'],4)]);
                if (empty($members['success']) || !is_array($members['result'])) { throw new \RuntimeException('Cannot verify private chat membership'); }
                foreach ($members['result'] as $member) {
                    $id=(int)(is_array($member) ? ($member['id'] ?? $member['ID'] ?? 0) : $member);
                    $identity=$s->get('source_cursors','identity');$actor=(int)($identity['data']['actor_id'] ?? $this->cases->policy->get('actor_id',0));
                    if (!$this->cases->policy->employee($id) && $id!==$actor) { throw new \DomainException('Private chat contains an unapproved participant'); }
                }
            }
            $deliveryStarted=true;
            $result = match ($a['type']) {
                'reply' => $a['source']==='mail'
                    ? $this->mailReply($case,$a,$row['id']) : $this->clientReply($a),
                'ask_employee', 'reminder' => $this->internalReply($a),
                'task' => $this->task($case,$a),
                'task_reminder' => $this->taskReminder($a),
                default => throw new \DomainException('Unsupported outbox action'),
            };
            if (empty($result['success'])) {
                if (empty($result['request_not_sent']) && (!empty($result['uncertain']) || in_array($result['error'] ?? '',['curl_error','exception','transport_error','invalid_response'],true))) {
                    $a['last_error']='Delivery requires reconciliation'; $s->update('outbox_actions',$row,$a,'delivery_unknown');
                } else { $s->retry('outbox_actions',$row,(string)($result['error'] ?? 'API failed'),!empty($result['permanent'])); }
                return;
            }
            $s->db->transaction(function () use ($s,$row,$a,$result) {
                $case=$this->cases->get($a['case_id']); $data=$case['data'];
                $a['external_id']=$result['message_id'] ?? $result['task_id'] ?? $result['comment_id'] ?? $result['result']['result']['item']['id'] ?? null;
                $a['comment_id']=$result['comment_id'] ?? null;
                $a['sent_at']=time(); $status=$case['status'];
                if ($a['type']==='task' && !empty($result['task_id'])) {
                    $data['task_id']=(int)$result['task_id'];
                    $data['task_responsible_id']=(int)($result['responsible_id'] ?? $a['fields']['responsible_id'] ?? 0);
                    foreach ($s->all('employee_requests',null) as $r) {
                        if ($r['data']['case_id']===$case['id'] && in_array($r['status'],['queued','waiting'],true)) {
                            $rd=$r['data']; $rd['task_id']=$data['task_id']; $s->update('employee_requests',$r,$rd,$r['status']);
                        }
                    }
                }
                if ($a['type']==='task' && isset($a['request_id'])) {
                    $a['task_id']=(int)$data['task_id'];$a['comment_id']=$result['comment_id'] ?? null;
                    $request=$s->get('employee_requests',$a['request_id']);$rd=$request['data'];
                    $rd['task_id']=$a['task_id'];$rd['dialog_id']='task:'.$a['task_id'];
                    $rd['asked_at']=time();$rd['external_id']=$result['comment_id'] ?? null;
                    $s->update('employee_requests',$request,$rd,'waiting');$status='WAITING_EMPLOYEE';
                    if (!empty($a['auto_confirm'])) {
                        $due=(new \DateTimeImmutable('@'.$a['feedback_due_at']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');
                        $confirmation=!empty($result['task_id']) ? 'Создал задачу №'.$a['task_id'].'.' : 'Добавил ваше уточнение в задачу №'.$a['task_id'].'.';
                        $s->insert('outbox_actions',hash('sha256','task-confirmation|'.$row['id']),[
                            'case_id'=>$case['id'],'type'=>'reply','source'=>$a['source'],'dialog_id'=>$case['data']['dialog_id'],
                            'client_message_id'=>$a['client_message_id'],'depends_on'=>$row['id'],
                            'message'=>$confirmation.' Плановый ответ менеджера — '.$due.' МСК.']);
                    }
                }
                if ($a['type']==='ask_employee') {
                    $r=$s->get('employee_requests',$a['request_id']); $rd=$r['data']; $rd['external_id']=$a['external_id']; $rd['asked_at']=time();
                    $s->update('employee_requests',$r,$rd,'waiting'); $status='WAITING_EMPLOYEE';
                }
                if ($a['type']==='reply') {
                    if (empty($a['system_notice'])) { $this->cancelNotices($case['id'],$a['client_message_id']); }
                    $data['last_reply_id']=$a['external_id']; $data['last_reply_at']=time();
                    $data['last_reply_text']=$a['message'];
                    $waiting=array_filter($s->all('employee_requests','waiting'),fn($r)=>$r['data']['case_id']===$case['id']);
                    if (empty($a['system_notice'])) { $status=$waiting ? 'WAITING_EMPLOYEE' : 'WAITING_CLIENT'; }
                }
                // Incoming messages or operator takeover during network I/O must win.
                if ($case['status']==='HUMAN_OWNED' || $data['last_client_message_id']!==$a['client_message_id']) { $status=$case['status']; }
                $s->update('cases',$case,$data,$status);
                $s->update('outbox_actions',$row,$a,'done'); $s->audit('action_sent',$case['id'],['action_id'=>$row['id'],'external_id'=>$a['external_id']]);
            });
            try {
                $archive=new DialogArchive($this->cases->policy); $fresh=$this->cases->get($a['case_id']);
                $archive->snapshot($fresh); $archive->record($fresh['data']['dialog_id'],'actions',$row['id'],$s->get('outbox_actions',$row['id']));
                foreach ($s->all('employee_requests',null) as $r) { if ($r['data']['case_id']===$fresh['id']) { $archive->record($fresh['data']['dialog_id'],'requests',$r['id'],$r); } }
            } catch (\Throwable) { /* Rebuildable projection; confirmed sends must stay confirmed. */ }
        } catch (\Throwable $e) {
            $current=$s->get('outbox_actions',$row['id']);
            if ($current && $current['status']==='processing') {
                if ($deliveryStarted && !$e instanceof \DomainException && !$e instanceof \InvalidArgumentException) {
                    $a['last_error']=$e->getMessage(); $s->update('outbox_actions',$current,$a,'delivery_unknown');
                } else { $s->retry('outbox_actions',$current,$e->getMessage(),$e instanceof \DomainException || $e instanceof \InvalidArgumentException); }
            }
        } finally {
            if ($locked) { $q=$s->db->pdo->prepare("UPDATE cases SET lease_token='',lease_until=0 WHERE id=? AND lease_token=?"); $q->execute([$a['case_id'],$row['lease_token']]); }
        }
    }
    private function clientReply(array $a): array
    {
        $this->cases->policy->external($a['dialog_id'],$this->cases->store); $this->cases->policy->capability('chat.send_external');
        return $this->chat->sendReply($a['dialog_id'],$a['message']);
    }
    private function internalReply(array $a): array
    {
        $this->cases->policy->internal($a['dialog_id'],(int)$a['employee_id']);
        $this->cases->policy->capability('chat.send_internal');
        return $this->chat->sendReply($a['dialog_id'],$a['message']);
    }
    private function mailReply(array $case,array $a,string $key): array
    {
        $this->cases->policy->mail((int)$case['data']['mailbox_id']);
        $this->cases->policy->capability('mail.reply');
        return $this->mail->reply((int)$a['client_message_id'],$a['message'],$key,$case['data']['mail_reply']);
    }
    private function task(array $case,array $a): array
    {
        $taskId=(int)($case['data']['task_id'] ?? 0);
        if ($taskId) {
            $current=$this->tasks->getTask($taskId);
            $unavailable=($current['error'] ?? '')==='task_unavailable';
            if (empty($current['success']) && !$unavailable) {
                // Only a read was attempted: no comment/task could be delivered.
                return array_merge($current,['request_not_sent'=>true,'uncertain'=>false]);
            }
            if ($unavailable || in_array((int)($current['task']['status'] ?? 0),[5,7],true)) {
                $this->cases->policy->capability('tasks.create');
                $s=$this->cases->store;
                $s->db->transaction(function()use($s,$case,$taskId){
                    $fresh=$this->cases->get($case['id']);$d=$fresh['data'];
                    if ((int)$d['task_id']!==$taskId) { throw new \RuntimeException('Task link changed'); }
                    $d['previous_task_ids']=array_values(array_unique(array_merge($d['previous_task_ids'] ?? [],[$taskId])));
                    $d['task_id']=null;unset($d['task_responsible_id'],$d['employee_answer'],$d['answer_request_id']);
                    $s->update('cases',$fresh,$d,$fresh['status']);
                    foreach($s->all('employee_requests') as $r){
                        if($r['data']['case_id']===$case['id'] && in_array($r['status'],['queued','waiting'],true)){
                            $rd=$r['data'];$rd['task_id']=null;$rd['dialog_id']='task:pending';$s->update('employee_requests',$r,$rd,$r['status']);
                        }
                    }
                    $s->audit('unavailable_task_detached',$case['id'],['task_id'=>$taskId]);
                });
                $taskId=0;
            }
        }
        $this->cases->policy->capability($taskId ? 'tasks.comment' : 'tasks.create');
        $marker=isset($a['request_id']) ? '[REQUEST:'.$a['request_id']."]\n" : '';
        if ($taskId) { return $this->tasks->addComment($taskId,$marker.$a['message']); }
        $fields=$a['fields'];
        $fields['description']=$marker.($fields['description'] ?? $a['message'])."\n[CASE:".$case['id']."]\nКанал: ".$case['data']['dialog_id'];
        return $this->tasks->createTask($fields);
    }
    private function taskReminder(array $a): array
    {
        $this->cases->policy->capability('tasks.comment');
        $request=$this->cases->store->get('employee_requests',$a['request_id']);
        if (!$request || $request['status']!=='waiting' || ($request['data']['channel'] ?? '')!=='task') {
            throw new \DomainException('Task request is no longer waiting');
        }
        return $this->tasks->addComment((int)$request['data']['task_id'],$a['message']);
    }
    private function freshness(array $case): bool
    {
        $h=$this->chat->getHistory($case['data']['dialog_id'],50);
        if (empty($h['success'])) { throw new \RuntimeException('Cannot verify current conversation'); }
        foreach ($h['messages'] as $m) {
            if ((int)($m['id'] ?? 0)<=(int)$case['data']['last_client_message_id']) { continue; }
            $id=(int)($m['author_id'] ?? 0);
            $identity=$this->cases->store->get('source_cursors','identity');
            $actor=(int)($identity['data']['actor_id'] ?? $this->cases->policy->get('actor_id',0));
            if ($id<=0 || $id===$actor || !empty($m['system'])) { continue; }
            if ($this->cases->policy->employee($id)) {
                $this->cases->store->update('cases',$case,$case['data'],'HUMAN_OWNED'); return false;
            }
            $this->cases->ingest(['source'=>'chat','dialog_id'=>$case['data']['dialog_id'],'message_id'=>(string)$m['id'],'author_id'=>$id,'text'=>$m['text'] ?? '','reply_to'=>$m['reply_id'] ?? null]);
            return false;
        }
        return true;
    }
    private function recoverUnknown(): void
    {
        $s=$this->cases->store;
        foreach ($s->all('outbox_actions','processing') as $a) {
            if ($a['lease_until']<time()) { $s->update('outbox_actions',$a,$a['data'],'delivery_unknown'); }
        }
        // Reconcile one uncertain chat reply per tick, with a cooldown. Task
        // creation is never automatically replayed after an ambiguous result.
        foreach ($s->all('outbox_actions','delivery_unknown') as $row) {
            $a=$row['data'];
            if ($row['available_at']>time() || $row['updated_at']>time()-30 || $a['type']!=='reply' || $a['source']!=='chat') { continue; }
            $h=$this->chat->getHistory($a['dialog_id'],50);$visible=false;$match=null;
            $identity=$s->get('source_cursors','identity');$actor=(int)($identity['data']['actor_id'] ?? $this->cases->policy->get('actor_id',0));
            foreach ($h['messages'] ?? [] as $m) {
                if ((string)$m['id']===$a['client_message_id']) { $visible=true; }
                if ((int)$m['id']>(int)$a['client_message_id'] && (int)($m['author_id'] ?? 0)===$actor
                    && trim((string)($m['text'] ?? ''))===trim($a['message'])) { $match=$m; }
            }
            if (!empty($h['success']) && $match) {
                $s->db->transaction(function()use($s,$row,$a,$match){
                    $a['external_id']=$match['id'];$a['sent_at']=time();unset($a['last_error']);
                    $s->update('outbox_actions',$row,$a,'done');$case=$this->cases->get($a['case_id']);$d=$case['data'];
                    if (empty($a['system_notice'])) { $this->cancelNotices($case['id'],$a['client_message_id']); }
                    if ($d['last_client_message_id']===$a['client_message_id']) {
                        $d['last_reply_id']=$match['id'];$d['last_reply_text']=$a['message'];$d['last_reply_at']=time();
                        $s->update('cases',$case,$d,$case['status']);
                    }
                    $s->audit('reply_delivery_reconciled',$case['id'],['action_id'=>$row['id'],'message_id'=>$match['id']]);
                });
            } else {
                // Two successful, separated history reads must cover the
                // original client message and show the reply is absent.
                $a['reconcile_misses']=!empty($h['success']) && $visible ? (int)($a['reconcile_misses'] ?? 0)+1 : 0;
                $case=$this->cases->get($a['case_id']);$status='delivery_unknown';
                if ($case['data']['last_client_message_id']!==$a['client_message_id']) { $status='cancelled'; }
                elseif ($a['reconcile_misses']>=2) { $status='ready';unset($a['last_error']); }
                $s->update('outbox_actions',$row,$a,$status,$status==='ready' ? 0 : time()+60);
            }
            break;
        }
    }
    public function timers(): int
    {
        $count=0; $s=$this->cases->store;
        foreach ($s->all('employee_requests','waiting') as $r) {
            $d=$r['data']; if ($d['due_at']>time()) { continue; }
            $s->db->transaction(function () use ($s,$r,$d,&$count) {
                $case=$this->cases->get($d['case_id']);
                if (in_array($case['status'],['HUMAN_OWNED','RESOLVED'],true)) { return; }
                $max=(int)$this->cases->policy->get('max_reminders',3);
                if ($d['reminders'] >= $max) {
                    $s->update('employee_requests',$r,$d,'overdue');
                    $s->update('cases',$case,$case['data'],'HUMAN_OWNED');
                    $s->audit('employee_timeout_escalated',$case['id'],['request_id'=>$r['id']]);
                    return;
                }
                $number=$d['reminders']+1;
                $s->insert('outbox_actions',hash('sha256','reminder|'.$r['id'].'|'.$number),[
                    'case_id'=>$case['id'],'type'=>($d['channel'] ?? '')==='task' ? 'task_reminder' : 'reminder',
                    'request_id'=>$r['id'],'dialog_id'=>$d['dialog_id'],'employee_id'=>$d['employee_id'],
                    'task_id'=>$d['task_id'] ?? null,
                    'source'=>$case['data']['source'],'client_message_id'=>$case['data']['last_client_message_id'],
                    'message'=>'[REQUEST:'.$r['id'].'] Срок обратной связи истёк. Нужен ответ: '.$d['question']]);
                $d['reminders']=$number; $d['due_at']=time()+(int)$this->cases->policy->get('employee_timeout_seconds',3600);
                $s->update('employee_requests',$r,$d,'waiting');
                // Wake the agent to prepare a factual client update; no invented deadline.
                $eventId=hash('sha256','timeout|'.$r['id'].'|'.$number);
                $s->insert('inbox_events',$eventId,['case_id'=>$case['id'],'kind'=>'employee_timeout','request_id'=>$r['id']]);
                $cd=$case['data']; $cd['latest_event_id']=$eventId; $s->update('cases',$case,$cd,'WAITING_EMPLOYEE');
                $count++;
            });
        }
        return $count;
    }
}
