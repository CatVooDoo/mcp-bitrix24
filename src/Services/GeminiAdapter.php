<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{CommandRunner,Env,Store,Logger};

/** Gemini proposes actions; only the durable worker can execute them. */
final class GeminiAdapter
{
    public function plan(array $context): array
    {
        $workspace=Env::dataDir().'/gemini-planner';
        if (!is_dir($workspace) && !mkdir($workspace,0700,true) && !is_dir($workspace)) {
            throw new \RuntimeException('Cannot create Gemini workspace');
        }
        $localBinary=Env::root().'/var/tools/node_modules/.bin/gemini';
        $binary=Env::get('B24_GEMINI_BIN',is_executable($localBinary) ? $localBinary : 'gemini');
        $argv=[$binary,'--output-format','json','--extensions','none','--approval-mode','default',
            '--admin-policy',Env::root().'/config/gemini-planner-policy.toml',
            '--prompt','Use only the JSON context on stdin. Follow its instructions. Return exactly one JSON object {"actions":[...]}, without markdown. Do not use tools. Reply in the customer language. Never invent CRM facts, task IDs or deadlines. Truncated data is incomplete.'];
        $model=Env::get('B24_GEMINI_MODEL');
        if ($model!=='') { $argv[]='--model'; $argv[]=$model; }
        $environment=getenv();
        $environment['GEMINI_CLI_SYSTEM_SETTINGS_PATH']=Env::root().'/config/gemini-planner.json';
        $input=Store::json(self::compact($context)); $started=microtime(true);
        $output=(new CommandRunner())->run($argv,$input,$workspace,120,$environment);
        $envelope=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || !empty($envelope['error']) || !is_string($envelope['response'] ?? null)) {
            throw new \RuntimeException('Invalid Gemini CLI response');
        }
        $plan=self::decodePlan($envelope['response']);
        Logger::info('Gemini plan received',['context_bytes'=>strlen($input),'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
            'stats'=>$envelope['stats'] ?? []],'agent');
        return $plan;
    }

    public static function decodePlan(string $text): array
    {
        $text=trim($text);
        if (preg_match('/\A```(?:json)?\s*\n?(.*?)\n?```\z/s',$text,$match)) { $text=$match[1]; }
        $shape=json_decode($text,false,512,JSON_THROW_ON_ERROR);
        if (!$shape instanceof \stdClass || !isset($shape->actions) || !is_array($shape->actions)) {
            throw new \RuntimeException('Gemini must return a JSON object with an actions array');
        }
        $plan=json_decode($text,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($plan) || !isset($plan['actions']) || !is_array($plan['actions']) || !array_is_list($plan['actions']) || count($plan['actions'])>5) {
            throw new \RuntimeException('Gemini must return an actions array of at most five actions');
        }
        return $plan;
    }

    /** Bound context deterministically; mark cuts rather than silently presenting partial facts as complete. */
    public static function compact(array $context): array
    {
        $result=array_intersect_key($context,array_flip(['instructions','now','contract_version','event','case','policy','requests','task','chat','crm','knowledge']));
        if(isset($result['task']['task'])){
            $result['task']['task']=array_intersect_key($result['task']['task'],array_flip([
                'id','title','description','status','statusText','responsibleId','groupId','deadline','closedDate','chatId','timeEstimate'
            ]));
        }
        foreach(['chatMessages','comments','results'] as $key){
            if(isset($result['task'][$key]) && is_array($result['task'][$key])){
                $items=array_values($result['task'][$key]);
                if($key==='chatMessages'){$items=array_values(array_filter($items,fn($m)=>(int)($m['author_id'] ?? 0)>0 && empty($m['system'])));}
                usort($items,fn($a,$b)=>(int)($a['id'] ?? $a['ID'] ?? 0)<=>(int)($b['id'] ?? $b['ID'] ?? 0));
                $result['task'][$key]=array_slice($items,-8);
                if(count($items)>8){$result['task'][$key.'_omitted']=count($items)-8;}
            }
        }
        if (isset($result['case']['data']['messages'])) { $result['case']['data']['messages']=array_slice($result['case']['data']['messages'],-6); }
        if (isset($result['chat']['messages'])) {
            $messages=$result['chat']['messages'];
            usort($messages,fn($a,$b)=>(int)($a['id'] ?? 0)<=>(int)($b['id'] ?? 0));
            $result['chat']['messages']=array_slice($messages,-6);
        }
        // Full event and current case identifiers remain available for correlation.
        foreach (['case','requests','task','chat','crm'] as $key) {
            if (isset($result[$key])) { $result[$key]=self::clip($result[$key],0); }
        }
        if (strlen(Store::json($result))>60000) { throw new \RuntimeException('Gemini context exceeds 60 KB; narrow CRM/task data'); }
        return $result;
    }
    private static function clip(mixed $value,int $depth): mixed
    {
        if (is_string($value)) { return mb_strlen($value)>3000 ? mb_substr($value,0,3000).' [TRUNCATED]' : $value; }
        if (!is_array($value)) { return $value; }
        if ($depth>12) { return ['_truncated'=>true]; }
        if (array_is_list($value)) {
            $items=array_map(fn($v)=>self::clip($v,$depth+1),array_slice($value,0,8));
            if (count($value)>8) { $items[]=['_truncated'=>true,'omitted'=>count($value)-8]; }
            return $items;
        }
        $out=[];
        foreach ($value as $key=>$item) {
            if (in_array($key,['files','users','user','attachments','time','raw','html','bodyHtml'],true)) { continue; }
            $out[$key]=self::clip($item,$depth+1);
        }
        return $out;
    }
}
