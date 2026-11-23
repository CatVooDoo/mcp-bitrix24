<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{CommandRunner,Env,Store,Logger};

/** Uses the user's existing Antigravity login without changing global settings. */
final class AgyAdapter
{
    public function plan(array $context): array
    {
        $workspace=Env::dataDir().'/agy-planner';
        $agents=$workspace.'/.agents/agents';
        if (!is_dir($agents) && !mkdir($agents,0700,true) && !is_dir($agents)) { throw new \RuntimeException('Cannot create agy workspace'); }
        $profile=(string)file_get_contents(Env::root().'/config/agy-planner.md');
        $path=$agents.'/b24-planner.md';
        // No partial profile while another worker starts agy.
        $tmp=$path.'.'.Store::id().'.tmp';
        if (file_put_contents($tmp,$profile,LOCK_EX)===false) { throw new \RuntimeException('Cannot write agy profile'); }
        chmod($tmp,0600);
        if (!rename($tmp,$path)) { @unlink($tmp); throw new \RuntimeException('Cannot install agy profile'); }
        $input=Store::json(GeminiAdapter::compact($context));
        $schema=($context['policy']['escalation_channel'] ?? '')==='task' ? 'agy-task-plan-schema.json' : 'agy-plan-schema.json';
        // One streaming user event keeps customer data out of process argv.
        // Closing stdin after it finishes the fresh conversation automatically.
        $argv=[Env::get('B24_AGY_BIN','agy'),'--input-format','stream-json','--output-format','stream-json','--agent','b24-planner',
            '--disable-slash-commands','--sandbox','--settings',Env::root().'/config/agy-planner.json',
            '--json-schema',Env::root().'/config/'.$schema,'--print-timeout','110s',
            '--effort',Env::get('B24_AGY_EFFORT','low')];
        $model=Env::get('B24_AGY_MODEL');
        $effort=Env::get('B24_AGY_EFFORT','low');
        if($model!=='' && preg_match('/-(low|medium|high|xhigh|max)$/',$model,$preset) && $preset[1]!==$effort){
            throw new \DomainException('agy model preset conflicts with effort; select a matching model tier');
        }
        if ($model!=='') { $argv[]='--model';$argv[]=$model; }
        $started=microtime(true);
        $raw=(new CommandRunner())->run($argv,Store::json(['event'=>'user','message'=>['content'=>$input]])."\n",$workspace,120,null,
            static function(string $out): bool {
                $lines=explode("\n",$out);array_pop($lines); // Ignore an incomplete last JSON line.
                foreach ($lines as $line) {
                    $event=json_decode($line,true);
                    if (is_array($event) && ($event['event'] ?? '')==='result') { return true; }
                }
                return false;
            });
        $envelope=null;$results=0;$structuredText=null;
        foreach (explode("\n",trim($raw)) as $line) {
            if (trim($line)==='') { continue; }
            $shape=json_decode($line,false,512,JSON_THROW_ON_ERROR);
            $event=json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if (($event['event'] ?? '')==='result') {
                $envelope=$event['result'] ?? null;$results++;
                $structuredText=isset($shape->result->structured_output)
                    ? json_encode($shape->result->structured_output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE) : null;
            }
        }
        if ($results!==1) { throw new \RuntimeException('Expected exactly one agy result'); }
        if (!is_array($envelope) || ($envelope['status'] ?? '')!=='SUCCESS' || !empty($envelope['error'])
            || ($envelope['finished_reason'] ?? '')==='print_timeout') {
            $reason=($envelope['finished_reason'] ?? '')==='print_timeout' ? 'print_timeout'
                : ($structuredText===null ? 'missing_structured_output' : 'cli_error');
            Logger::warning('agy plan rejected',['reason'=>$reason,'status'=>$envelope['status'] ?? null,
                'cli_error'=>is_string($envelope['error'] ?? null) ? mb_substr($envelope['error'],0,500) : null,'has_structured_output'=>$structuredText!==null,
                'context_bytes'=>strlen($input),'elapsed_ms'=>(int)((microtime(true)-$started)*1000)],'agent');
            throw new \RuntimeException('agy plan rejected: '.$reason.'; no actions queued');
        }
        $text=$structuredText ?? $envelope['response'] ?? null;
        if (!is_string($text)) { throw new \RuntimeException('agy response is missing'); }
        $plan=GeminiAdapter::decodePlan($text);
        // Some agy versions return completed JSON in response without duplicating
        // it into structured_output. Apply the same schema ourselves, never accept prose.
        \Bitrix24Mcp\Mcp\SchemaValidator::validate($plan,json_decode((string)file_get_contents(Env::root().'/config/'.$schema),true,512,JSON_THROW_ON_ERROR),'plan');
        $usage=[];
        foreach (['input','output','thinking','cache_read','total'] as $name) {
            $value=$envelope['usage'][$name.'_tokens'] ?? null;
            // Logger intentionally masks keys containing "token" (credentials).
            if (is_int($value)) { $usage[$name.'_count']=$value; }
        }
        Logger::info('agy plan received',['context_bytes'=>strlen($input),'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
            'remote_seconds'=>$envelope['duration_seconds'] ?? null,'usage'=>$usage],'agent');
        return $plan;
    }
}
