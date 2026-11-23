<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{Env,HttpClient};

/** REST 3.0 has its own endpoint, JSON body and error contract. */
class MailService
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}
    public function call(string $method, array $params = [], ?string $idempotencyKey = null): array
    {
        if (!in_array($method,['mail.message.list','mail.message.get','mail.message.thread','mail.message.reply','mail.mailbox.list'],true)) { throw new \InvalidArgumentException('Mail method is not permitted'); }
        $base=Env::get('B24_REST_V3_URL');
        if ($base==='') { return ['success'=>false,'error'=>'mail_not_configured','permanent'=>true]; }
        return $this->http->request(rtrim($base,'/').'/'.$method,$params,$idempotencyKey ? ['Idempotency-Key: '.$idempotencyKey] : []);
    }
    public function reply(int $messageId, string $message, string $key, array $envelope = []): array
    {
        if (!filter_var($envelope['from'] ?? '',FILTER_VALIDATE_EMAIL) || empty($envelope['to'])) {
            return ['success'=>false,'error'=>'invalid_mail_envelope','permanent'=>true];
        }
        foreach ($envelope['to'] as $address) {
            if (!filter_var($address,FILTER_VALIDATE_EMAIL)) { return ['success'=>false,'error'=>'invalid_mail_recipient','permanent'=>true]; }
        }
        $r=$this->call('mail.message.reply',[
            'replyToMessageId'=>$messageId,'from'=>$envelope['from'],'to'=>$envelope['to'],
            'subject'=>$envelope['subject'],'body'=>nl2br(htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))],$key);
        if (!empty($r['success']) && empty($r['result']['result']['success'])) {
            return ['success'=>false,'error'=>'mail_reply_rejected','permanent'=>true];
        }
        return $r;
    }
    public function event(array $mail): ?array
    {
        $address=static function (string $v): string {
            if (preg_match('/<([^<>]+)>/',$v,$m)) { $v=$m[1]; }
            return filter_var(trim($v),FILTER_VALIDATE_EMAIL) ? strtolower(trim($v)) : '';
        };
        $from=$address((string)($mail['from'] ?? '')); $own=$address((string)($mail['mailboxEmail'] ?? ''));
        if ($from==='' || $own==='' || $from===$own || empty($mail['id']) || empty($mail['mailboxId'])) { return null; }
        // Suppress obvious automatic notifications. Headers are used when supplied by the portal.
        $headers=array_change_key_case((array)($mail['headers'] ?? []),CASE_LOWER);
        if ((!empty($headers['auto-submitted']) && strtolower($headers['auto-submitted'])!=='no') ||
            in_array(strtolower((string)($headers['precedence'] ?? '')),['bulk','junk','list'],true)) { return null; }
        $subject=str_replace(["\r","\n"],' ',(string)($mail['subject'] ?? ''));
        $threadSubject=preg_replace('/^(?:(?:re|fw|fwd):\s*)+/i','',trim($subject));
        return ['source'=>'mail','dialog_id'=>'mail:'.$mail['mailboxId'].':'.substr(hash('sha256',$from.'|'.mb_strtolower($threadSubject)),0,32),
            'mailbox_id'=>(int)$mail['mailboxId'],'message_id'=>(string)$mail['id'],'author_id'=>0,
            'text'=>(string)($mail['body'] ?? ''),'subject'=>$subject,'occurred_at'=>strtotime((string)($mail['date'] ?? '')) ?: time(),
            'mail_reply'=>['from'=>$own,'to'=>[$from],'subject'=>'Re: '.$threadSubject]];
    }
}
