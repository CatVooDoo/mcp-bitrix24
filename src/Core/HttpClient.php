<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

final class HttpClient
{
    public function request(string $url, array $body, array $headers = []): array
    {
        $scheme = parse_url($url,PHP_URL_SCHEME);
        if (!in_array($scheme,['https','http'],true)) { throw new \InvalidArgumentException('HTTP(S) URL required'); }
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>Store::json($body),CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_DNS_SHUFFLE_ADDRESSES=>true,CURLOPT_DNS_CACHE_TIMEOUT=>0,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json'],$headers)]);
        // A failed TCP connection cannot have delivered a POST. Retry only that
        // phase; a timeout after connecting must still require reconciliation.
        for ($attempt=0;$attempt<3;$attempt++) {
            $result=curl_exec($ch); $error=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $notSent=$result===false && (float)curl_getinfo($ch,CURLINFO_CONNECT_TIME)===0.0
                && (int)curl_getinfo($ch,CURLINFO_REQUEST_SIZE)===0
                && in_array(curl_errno($ch),[CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_CONNECT,CURLE_OPERATION_TIMEDOUT],true);
            if (!$notSent) { break; }
        }
        unset($ch);
        if ($result===false) { return ['success'=>false,'error'=>'transport_error','error_description'=>$error,'uncertain'=>!$notSent,'request_not_sent'=>$notSent,'http_status'=>$code]; }
        try { $json=json_decode($result,true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return ['success'=>false,'error'=>'invalid_json','http_status'=>$code,'uncertain'=>$code>=200 && $code<300]; }
        if ($code<200 || $code>=300 || !is_array($json) || !empty($json['error'])) {
            return ['success'=>false,'error'=>(is_array($json) && is_string($json['error'] ?? null)) ? $json['error'] : 'http_'.$code,'http_status'=>$code,'permanent'=>in_array($code,[400,401,403,404,422],true), 'uncertain'=>$code>=500, 'error_description'=>(is_array($json) ? ($json['error_description'] ?? 'Remote API rejected request') : 'Remote API rejected request')];
        }
        return ['success'=>true,'result'=>$json,'http_status'=>$code];
    }
}
