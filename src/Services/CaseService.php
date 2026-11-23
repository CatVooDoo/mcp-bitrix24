<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{Policy,Store};

/** All event/state/outbox changes commit together; network I/O happens in workers. */
final class CaseService
{
    public function __construct(public readonly Store $store, public readonly Policy $policy) {}

    public function ingest(array $event): array
    {
        foreach (['source','message_id','dialog_id','author_id'] as $key) {
            if (!isset($event[$key]) || (string)$event[$key] === '') { throw new \InvalidArgumentException('Missing event '.$key); }
        }
        $event['portal_id'] = $this->policy->get('portal_id');
        $eventId = hash('sha256',$event['portal_id'].'|'.$event['source'].'|'.$event['dialog_id'].'|'.$event['message_id']);
        $saved = $this->store->db->transaction(function () use ($event,$eventId) {
            $existing=$this->store->get('inbox_events',$eventId);
            if ($existing && ($existing['status']!=='unmatched' || !$this->matchEmployeeReply($event))) { return $existing; }
            // Reserve identity first: simultaneous webhook/poll ingestion cannot both update the case.
            if (!$existing && !$this->store->insert('inbox_events',$eventId,$event,'received')) { return $this->store->get('inbox_events',$eventId); }
            $row = $existing ?? $this->store->get('inbox_events',$eventId);
            if ($this->ownMessage($event) || !empty($event['system']) || !empty($event['bot']) || ($event['source']==='chat' && (int)$event['author_id']<=0)) {
                return $this->store->update('inbox_events',$row,$event,'ignored');
            }
            $request = $this->matchEmployeeReply($event);
            if ($request) {
                $case = $this->get($request['data']['case_id']);
                $alreadyReplied=false;
                if($existing && !empty($event['occurred_at'])){
                    foreach($this->store->all('outbox_actions','done') as $sent){
                        if(($sent['data']['type'] ?? '')==='reply' && $sent['data']['case_id']===$case['id']
                            && (int)($sent['data']['sent_at'] ?? 0)>=(int)$event['occurred_at']
                            && in_array($request['id'],$sent['data']['source_refs'] ?? [],true)){$alreadyReplied=true;break;}
                    }
                }
                $data = $request['data']; $data['answer'] = $event; $data['answered_at'] = time();
                $this->store->update('employee_requests',$request,$data,'answered');
                $caseData = $case['data']; $caseData['employee_answer'] = $event; $caseData['answer_request_id'] = $request['id'];
                if(!$alreadyReplied){$caseData['latest_event_id'] = $eventId;}
                $this->store->update('cases',$case,$caseData,$alreadyReplied ? $case['status'] : ($case['status'] === 'HUMAN_OWNED' ? 'HUMAN_OWNED' : 'READY_TO_REPLY'));
                $event['case_id'] = $case['id']; $event['kind'] = 'employee_answer';
                return $this->store->update('inbox_events',$row,$event,$alreadyReplied ? 'done' : ($case['status'] === 'HUMAN_OWNED' ? 'ignored' : 'ready'));
            }
            $dialog = (string)$event['dialog_id'];
            $openlineAllowed = $this->policy->externalOpenLineAllowed(isset($event['openline_entity_id']) ? (string)$event['openline_entity_id'] : null);
            if ($event['source'] === 'mail') { $this->policy->mail((int)($event['mailbox_id'] ?? 0)); }
            elseif (!in_array($dialog,$this->policy->get('external_dialogs',[]),true) && !$openlineAllowed) {
                // Uncorrelated employee traffic is retained, never treated as a customer question.
                return $this->store->update('inbox_events',$row,$event,'unmatched');
            }
            $caseId = substr(hash('sha256',$this->policy->get('portal_id').'|'.$dialog),0,32);
            $case = $this->store->get('cases',$caseId);
            if ($this->policy->employee((int)$event['author_id'])) {
                if ($case) { $this->store->update('cases',$case,$case['data'],'HUMAN_OWNED'); }
                return $this->store->update('inbox_events',$row,$event,'ignored');
            }
            if ($event['source'] !== 'mail') {
                $authorsMap = $this->policy->get('external_authors',[]);
                if (!$openlineAllowed || isset($authorsMap[$dialog])) {
                    $allowed = $authorsMap[$dialog] ?? [];
                    if (!$allowed || !in_array((int)$event['author_id'],$allowed,true)) {
                        return $this->store->update('inbox_events',$row,$event,'unmatched');
                    }
                }
            }
            // A resolved conversation starts a new issue; its old task and
            // project context must not receive unrelated follow-up requests.
            $data = $case && $case['status']!=='RESOLVED'
                ? $case['data'] : ['dialog_id'=>$dialog,'source'=>$event['source'],'messages'=>[], 'task_id'=>null];
            $data['last_client_message_id'] = (string)$event['message_id'];
            $data['latest_event_id'] = $eventId;
            $data['messages'] = array_slice(array_merge($data['messages'],[$event]),-30);
            if (isset($event['mailbox_id'])) { $data['mailbox_id'] = $event['mailbox_id']; $data['mail_reply'] = $event['mail_reply']; }
            if ($case) { $this->store->update('cases',$case,$data,$case['status']==='HUMAN_OWNED' ? 'HUMAN_OWNED' : 'NEW'); }
            else { $this->store->insert('cases',$caseId,$data,'NEW'); }
            $event['case_id'] = $caseId; $event['kind'] = 'client_message';
            return $this->store->update('inbox_events',$row,$event,$case && $case['status']==='HUMAN_OWNED' ? 'ignored' : 'ready');
        });
        $this->archive($saved);
        return $saved;
    }

    public function get(string $id): array
    {
        return $this->store->get('cases',$id) ?? throw new \InvalidArgumentException('Case not found');
    }

    private function ownMessage(array $event): bool
    {
        $identity = $this->store->get('source_cursors','identity');
        $actor = (int)($identity['data']['actor_id'] ?? $this->policy->get('actor_id',0));
        // Staff may answer inside the task using the same account as the webhook.
        if ($actor > 0 && $actor === (int)$event['author_id'] && empty($event['task_id'])) { return true; }
        foreach ($this->store->all('outbox_actions','done') as $action) {
            if (!empty($event['task_id']) && (int)($action['data']['task_id'] ?? 0)===(int)$event['task_id']
                && !empty($action['data']['comment_id']) && (string)$action['data']['comment_id']===(string)$event['message_id']) { return true; }
            if (($action['data']['dialog_id'] ?? '') === $event['dialog_id'] &&
                (string)($action['data']['external_id'] ?? '') === (string)$event['message_id']) { return true; }
        }
        return false;
    }

    private function matchEmployeeReply(array $event): ?array
    {
        if (!$this->policy->employee((int)$event['author_id'])) { return null; }
        $matches = [];
        foreach ($this->store->all('employee_requests','waiting') as $request) {
            $data = $request['data'];
            if ((int)$data['employee_id'] !== (int)$event['author_id']) { continue; }
            $sameTask = !empty($event['task_id']) && (int)($data['task_id'] ?? 0) === (int)$event['task_id'];
            $sameDialog = $data['dialog_id'] === $event['dialog_id'];
            if (!$sameDialog && !$sameTask) { continue; }
            $explicit = str_contains((string)($event['text'] ?? ''),'[REQUEST:'.$request['id'].']');
            $quoted = !empty($event['reply_to']) && (string)$event['reply_to'] === (string)($data['external_id'] ?? '');
            if ($explicit || $quoted) { return $request; }
            if ($sameTask && !empty($event['occurred_at']) && $event['occurred_at'] >= ($data['asked_at'] ?? PHP_INT_MAX)) { $matches[] = $request; }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** The agent returns a plan, never arbitrary REST methods or recipients. */
    public function applyPlan(string $eventId, string $lease, int $expectedVersion, array $plan): array
    {
        return $this->store->db->transaction(function () use ($eventId,$lease,$expectedVersion,$plan) {
            $event = $this->store->requireLease('inbox_events',$eventId,$lease);
            $case = $this->get($event['data']['case_id']);
            if ($case['version'] !== $expectedVersion) { throw new \RuntimeException('Case changed; read it again'); }
            if ($case['status'] === 'HUMAN_OWNED' || $case['data']['latest_event_id'] !== $eventId) {
                $this->store->update('inbox_events',$event,$event['data'],'superseded');
                return ['success'=>true,'status'=>'superseded'];
            }
            $actions = $plan['actions'] ?? null;
            if (!is_array($actions) || !array_is_list($actions) || count($actions)>5) { throw new \InvalidArgumentException('actions must be an array of at most five actions'); }
            $taskChannel=$this->policy->get('escalation_channel')==='task';
            // The worker constructs the confirmation from the actual task result.
            if ($taskChannel && in_array('task',array_column($actions,'type'),true)) {
                $actions=array_values(array_filter($actions,fn($a)=>($a['type'] ?? '')!=='reply'));
            }
            $types = array_column($actions,'type');
            foreach (['reply','ask_employee','task','resolve','handoff'] as $type) {
                if (count(array_filter($types,fn($t)=>$t===$type))>1) { throw new \InvalidArgumentException('Repeated action type'); }
            }
            if ((in_array('resolve',$types,true) || in_array('handoff',$types,true)) && count($actions)>1) { throw new \InvalidArgumentException('Terminal action must be standalone'); }
            usort($actions, fn($a,$b)=>(['task'=>0,'ask_employee'=>1,'reply'=>2,'resolve'=>3,'handoff'=>3][$a['type'] ?? ''] ?? 99) <=> (['task'=>0,'ask_employee'=>1,'reply'=>2,'resolve'=>3,'handoff'=>3][$b['type'] ?? ''] ?? 99));
            $ids = [];
            foreach ($actions as $index=>$action) {
                if (!is_array($action)) { throw new \InvalidArgumentException('Invalid action'); }
                $type = (string)($action['type'] ?? '');
                $payload = ['case_id'=>$case['id'],'event_id'=>$eventId,'type'=>$type,'dialog_id'=>$case['data']['dialog_id'],
                    'client_message_id'=>$case['data']['last_client_message_id'],'source'=>$case['data']['source'], 'depends_on'=>$ids ? $ids[array_key_last($ids)] : null];
                $id = hash('sha256',$eventId.'|'.$index);
                switch ($type) {
                    case 'reply':
                        $this->policy->capability($payload['source']==='mail' ? 'mail.reply' : 'chat.send_external');
                        $payload['message'] = $this->text($action['message'] ?? '');
                        $payload['source_refs'] = $action['source_refs'] ?? [];
                        // A reply to an employee answer must cite that exact request, never another case.
                        if (($event['data']['kind'] ?? '') === 'employee_answer' && !in_array($case['data']['answer_request_id'],$payload['source_refs'],true)) {
                            throw new \InvalidArgumentException('Employee answer request must be cited in source_refs');
                        }
                        $payload['mailbox_id'] = $case['data']['mailbox_id'] ?? null;
                        break;
                    case 'ask_employee':
                        if ($taskChannel) { throw new \DomainException('Escalation must use a linked task; direct messages are disabled'); }
                        $this->policy->capability('chat.send_internal');
                        foreach ($this->store->all('employee_requests',null) as $pending) {
                            if ($pending['data']['case_id'] === $case['id'] && in_array($pending['status'], ['queued','waiting'], true)) {
                                throw new \InvalidArgumentException('An employee request is already pending; keep waiting or hand off');
                            }
                        }
                        $employee = (int)($action['employee_id'] ?? 0);
                        $dialog = (string)($action['dialog_id'] ?? $employee);
                        $this->policy->internal($dialog,$employee);
                        $due = strtotime((string)($action['due_at'] ?? ''));
                        if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/', (string)($action['due_at'] ?? '')) || !$due || $due <= time()) { throw new \InvalidArgumentException('due_at must be in the future, with timezone'); }
                        $requestId = substr($id,0,32);
                        $question = $this->text($action['question'] ?? '');
                        $this->store->insert('employee_requests',$requestId,[
                            'case_id'=>$case['id'],'employee_id'=>$employee,'dialog_id'=>$dialog,'question'=>$question,
                            'due_at'=>$due,'reminders'=>0,'task_id'=>$case['data']['task_id']], 'queued');
                        $payload['request_id'] = $requestId; $payload['dialog_id'] = $dialog;
                        $payload['employee_id'] = $employee;
                        $payload['message'] = '[CASE:'.$case['id'].'] [REQUEST:'.$requestId."]\n".$question."\nОтветьте цитатой на это сообщение или сохраните [REQUEST:…].";
                        break;
                    case 'task':
                        $this->policy->capability($case['data']['task_id'] ? 'tasks.comment' : 'tasks.create');
                        $payload['fields'] = $action['fields'] ?? [];
                        if (!is_array($payload['fields'])) { throw new \InvalidArgumentException('Task fields must be an object'); }
                        if ($taskChannel) { $payload['fields']=SupportKnowledge::taskFields($case,$payload['fields'],$this->policy); }
                        $payload['task_id'] = $case['data']['task_id'];
                        $payload['message'] = $this->text($action['message'] ?? ($payload['fields']['description'] ?? ''));
                        if ($taskChannel) {
                            $requestId=substr($id,0,32);$pending=null;
                            foreach ($this->store->all('employee_requests') as $request) {
                                if ($request['data']['case_id']===$case['id'] && in_array($request['status'],['queued','waiting'],true)) { $pending=$request;break; }
                            }
                            $due=strtotime($payload['fields']['deadline']);
                            if (!$due || $due<=time()) { throw new \InvalidArgumentException('Task feedback deadline must be in the future'); }
                            $rd=['case_id'=>$case['id'],'channel'=>'task','employee_id'=>$payload['fields']['responsible_id'],
                                'dialog_id'=>$case['data']['task_id'] ? 'task:'.$case['data']['task_id'] : 'task:pending',
                                'question'=>$payload['message'],'due_at'=>$due,'reminders'=>0,'task_id'=>$case['data']['task_id']];
                            if ($pending) { $requestId=$pending['id'];$this->store->update('employee_requests',$pending,array_merge($pending['data'],$rd),'queued'); }
                            else { $this->store->insert('employee_requests',$requestId,$rd,'queued'); }
                            $payload['request_id']=$requestId;$payload['feedback_due_at']=$due;$payload['auto_confirm']=true;
                        }
                        break;
                    case 'resolve': case 'handoff': break;
                    default: throw new \InvalidArgumentException('Unsupported action: '.$type);
                }
                if ($type === 'resolve' || $type === 'handoff') {
                    $case = $this->store->update('cases',$case,$case['data'],$type==='resolve' ? 'RESOLVED' : 'HUMAN_OWNED');
                } else {
                    $this->store->insert('outbox_actions',$id,$payload);
                    $ids[] = $id;
                }
            }
            $this->store->update('inbox_events',$event,$event['data'],'done');
            $this->store->audit('plan_applied',$case['id'],['event_id'=>$eventId,'actions'=>$ids]);
            return ['success'=>true,'action_ids'=>$ids,'case'=>$this->get($case['id'])];
        });
    }
    private function text(mixed $value): string
    {
        if (!is_string($value) || trim($value)==='' || mb_strlen($value)>16000) { throw new \InvalidArgumentException('Message must contain 1..16000 characters'); }
        return trim($value);
    }
    public function archive(array $event): void
    {
        try {
            if (!isset($event['data']['case_id'])) { return; }
            $case=$this->get($event['data']['case_id']);
            $archive=new DialogArchive($this->policy);
            $archive->snapshot($case);
            $archive->record($case['data']['dialog_id'],'events',$event['id'],$event);
        } catch (\Throwable $e) {
            \Bitrix24Mcp\Core\Logger::error('Dialog archive projection failed',['exception'=>get_class($e)],'archive');
        }
    }
    public function resume(string $caseId, int $version): array
    {
        $case=$this->get($caseId);
        if ($case['version']!==$version) { throw new \RuntimeException('Case changed'); }
        $this->store->update('cases',$case,$case['data'],'NEW');
        $event=$this->store->get('inbox_events',$case['data']['latest_event_id']);
        if ($event) { $this->store->update('inbox_events',$event,$event['data'],'ready'); }
        return $this->get($caseId);
    }
}
