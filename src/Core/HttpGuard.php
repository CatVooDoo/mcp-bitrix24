<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

final class HttpGuard
{
    public static function bearer(): void
    {
        $token=defined('MCP_HTTP_AUTH_TOKEN') ? (string)MCP_HTTP_AUTH_TOKEN : Env::get('MCP_HTTP_AUTH_TOKEN');
        if ($token==='') { self::fail(503,'HTTP authentication is not configured'); }
        $auth=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth,'Bearer ') || !hash_equals($token,substr($auth,7))) { header('WWW-Authenticate: Bearer'); self::fail(401,'Unauthorized'); }
    }
    public static function origin(): void
    {
        $origin=$_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed=array_filter(array_map('trim',explode(',',Env::get('MCP_ALLOWED_ORIGINS'))));
        if ($origin!=='' && !in_array($origin,$allowed,true)) { self::fail(403,'Origin is not allowed'); }
    }
    public static function body(): string
    {
        $body=file_get_contents('php://input',false,null,0,1048577);
        if ($body===false || strlen($body)>1048576) { self::fail(413,'Request too large'); }
        return $body;
    }
    public static function fail(int $status,string $message): never
    {
        http_response_code($status); header('Content-Type: application/json');
        echo json_encode(['error'=>$message]); exit;
    }
}
