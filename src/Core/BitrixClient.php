<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

/** Timed webhook REST transport. No write is automatically replayed after a timeout. */
class BitrixClient
{
    public function __construct(private readonly HttpClient $http=new HttpClient()) {}
    public function call(string $method,array $params=[]): array
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)*$/',$method)) { throw new \InvalidArgumentException('Invalid REST method'); }
        $base=defined('C_REST_WEB_HOOK_URL') ? (string)C_REST_WEB_HOOK_URL : Env::get('C_REST_WEB_HOOK_URL');
        if ($base==='') { return ['success'=>false,'error'=>'webhook_not_configured','permanent'=>true,'result'=>null]; }
        $r=$this->http->request(rtrim($base,'/').'/'.$method.'.json',$params);
        if (!$r['success']) { return $r+['result'=>null]; }
        $raw=$r['result'];
        return ['success'=>true,'result'=>$raw['result'] ?? null,'total'=>$raw['total'] ?? null,
            'next'=>$raw['next'] ?? null,'time'=>$raw['time'] ?? null,'error'=>null,'error_description'=>null];
    }
    public function callBatch(array $batch,int $halt=0): array
    {
        $results=[]; $errors=[];
        foreach (array_chunk($batch,50,true) as $chunk) {
            $commands=[];
            foreach ($chunk as $key=>$command) {
                if (!is_array($command) || empty($command['method'])) { throw new \InvalidArgumentException('Batch command must contain method and params'); }
                $commands[$key]=$command['method'].(!empty($command['params']) ? '?'.http_build_query($command['params']) : '');
            }
            $r=$this->call('batch',['halt'=>$halt,'cmd'=>$commands]);
            if (!$r['success']) { return array_merge($r,['result'=>$results,'result_error'=>$errors,'partial'=>!empty($results)]); }
            $results=array_merge($results,$r['result']['result'] ?? []);
            $errors=array_merge($errors,$r['result']['result_error'] ?? []);
            if ($halt && $errors) { break; }
        }
        return ['success'=>!$errors,'result'=>$results,'result_error'=>$errors];
    }
}
