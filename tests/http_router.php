<?php
declare(strict_types=1);
// Used ONLY by the local integration test server. Never deploy this router.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$routes=['/mcp'=>'mcp.php','/webhook'=>'webhook.php','/health'=>'health.php'];
if (isset($routes[$path])) { require dirname(__DIR__).'/public/'.$routes[$path];return; }
if (str_starts_with($path,'/fake/')) {
    header('Content-Type: application/json');
    $body=json_decode((string)file_get_contents('php://input'),true) ?? [];
    $capture=getenv('B24_TEST_CAPTURE');
    if ($capture) { file_put_contents($capture,json_encode(['path'=>$path,'body'=>$body,'key'=>$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null])."\n",FILE_APPEND|LOCK_EX); }
    if ($path==='/fake/unauthorized') { http_response_code(401);echo '{"error":"INVALID_CREDENTIALS"}';return; }
    if ($path==='/fake/server-error') { http_response_code(500);echo '{"error":"INTERNAL_SERVER_ERROR"}';return; }
    if (str_ends_with($path,'im.message.add.json') || str_ends_with($path,'imbot.message.add.json')) { echo '{"result":1001}';return; }
    if (str_ends_with($path,'mail.message.reply')) { echo '{"result":{"success":true,"to":["client@example.org"]}}';return; }
    if (str_ends_with($path,'batch.json')) {
        $result=[];foreach ($body['cmd'] ?? [] as $name=>$cmd) { $result[$name]=['ok'=>true]; }
        echo json_encode(['result'=>['result'=>$result,'result_error'=>[]]]);return;
    }
    if ($path==='/fake/agent') { echo '{"actions":[{"type":"reply","message":"Ответ от тестового агента"}]}';return; }
    echo '{"result":{"ID":99}}';return;
}
http_response_code(404);
