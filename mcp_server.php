<?php
declare(strict_types=1);
require_once __DIR__.'/src/Core/Bootstrap.php';
use Bitrix24Mcp\Core\{Bootstrap,HttpGuard};
use Bitrix24Mcp\Mcp\McpRouter;
Bootstrap::init();
$router=new McpRouter();
if (PHP_SAPI==='cli') {
    while (($line=fgets(STDIN,1048578))!==false) {
        if (trim($line)==='') { continue; }
        $response=$router->handle($line);
        if ($response!==null) { fwrite(STDOUT,$response."\n"); fflush(STDOUT); }
    }
    exit;
}
HttpGuard::origin(); HttpGuard::bearer();
header('Content-Type: application/json; charset=utf-8');
// Stateless Streamable HTTP: JSON responses; no sessions, GET streams or DELETE lifecycle.
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST'); HttpGuard::fail(405,'Use POST'); }
$type=strtolower(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]);
if ($type!=='application/json') { HttpGuard::fail(415,'Expected application/json'); }
$version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
if ($version!==null && !in_array($version,McpRouter::VERSIONS,true)) { HttpGuard::fail(400,'Unsupported MCP protocol version'); }
$response=$router->handle(HttpGuard::body());
if ($response===null) { http_response_code(202); exit; }
echo $response;
