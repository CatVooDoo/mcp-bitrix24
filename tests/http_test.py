#!/usr/bin/env python3
"""Loopback-only integration checks. No real settings/credentials or external API calls."""
import json, os, socket, subprocess, tempfile, time, urllib.request, urllib.error
from pathlib import Path
SRC = Path(__file__).resolve().parents[1]
checks = 0

def assert_ok(value, message):
    global checks
    if not value: raise AssertionError(message)
    checks += 1

def request(base, path, data=None, headers=None, method=None):
    raw = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(base+path, data=raw, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=8) as r: return r.status, r.read()
    except urllib.error.HTTPError as e: return e.code, e.read()

with tempfile.TemporaryDirectory(prefix='b24-http-') as tmp:
    temp = Path(tmp)
    profile = {'portal_id':'http-test','external_dialogs':['chat1'],'external_authors':{'chat1':[10]},'internal_dialogs':[],
               'employee_ids':[1650], 'actor_id':99, 'mailbox_ids':[], 'capabilities':['crm.read','chat.read','chat.send_external','chat.send_internal']}
    (temp/'profile.json').write_text(json.dumps(profile))
    env = os.environ.copy()
    env.update({'B24_DATA_DIR':tmp,'B24_DATABASE_DSN':'sqlite:'+tmp+'/test.db','B24_PROFILE_FILE':tmp+'/profile.json',
                'B24_SECRETS_FILE':tmp+'/absent.json','C_REST_WEB_HOOK_URL':'https://unit.invalid/rest/99/unit/',
                'MCP_HTTP_AUTH_TOKEN':'test-secret','B24_OUTGOING_WEBHOOK_TOKEN':'outgoing-secret','B24_SEND_ENABLED':'false',
                'MCP_ALLOWED_ORIGINS':'https://allowed.example','B24_TEST_CAPTURE':tmp+'/capture.jsonl'})
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    log=open(temp/'server.log','w')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(SRC/'public'),str(SRC/'tests/http_router.php')],env=env,stdout=log,stderr=log)
    try:
        for _ in range(100):
            try:
                if request(base,'/health')[0]==200: break
            except OSError: time.sleep(.05)
        auth={'Authorization':'Bearer test-secret','Content-Type':'application/json','Accept':'application/json, text/event-stream'}
        ping={'jsonrpc':'2.0','id':1,'method':'ping'}
        assert_ok(request(base,'/mcp',ping,{'Content-Type':'application/json'})[0]==401,'anonymous MCP must be denied')
        assert_ok(request(base,'/mcp',ping,{**auth,'Origin':'https://evil.example'})[0]==403,'bad Origin must be denied')
        assert_ok(request(base,'/mcp',ping,{**auth,'Origin':'https://allowed.example'})[0]==200,'allowed Origin')
        assert_ok(request(base,'/mcp',None,auth,method='GET')[0]==405,'GET is not an SSE endpoint')
        assert_ok(request(base,'/mcp',ping,{**auth,'MCP-Protocol-Version':'9999'})[0]==400,'unsupported version')
        assert_ok(request(base,'/mcp',{'jsonrpc':'2.0','method':'unknown'},auth)[0]==202,'notifications must have empty 202 response')
        result=request(base,'/mcp',{'jsonrpc':'2.0','id':2,'method':'tools/list'},auth)
        assert_ok(result[0]==200 and any(t['name']=='b24_apply_plan' for t in json.loads(result[1])['result']['tools']),'MCP tools list')
        assert_ok(request(base,'/config/secrets.local.json')[0]==404,'private configs not exposed')
        event={'event':'ONIMMESSAGEADD','auth':{'domain':'unit.invalid','application_token':'outgoing-secret'},
               'data':{'PARAMS':{'DIALOG_ID':'chat1','MESSAGE_ID':1,'FROM_USER_ID':10,'MESSAGE':'Question'}}}
        assert_ok(request(base,'/webhook',event,{'Content-Type':'application/json'})[0]==200,'durable webhook ACK')
        assert_ok(request(base,'/webhook',event,{'Content-Type':'application/json'})[0]==200,'duplicate webhook ACK')
        bad=json.loads(json.dumps(event));bad['auth']['domain']='other.invalid'
        assert_ok(request(base,'/webhook',bad,{'Content-Type':'application/json'})[0]==403,'cross-portal event denied')
        preview={'jsonrpc':'2.0','id':3,'method':'tools/call','params':{'name':'b24_list_events','arguments':{}}}
        first=json.loads(request(base,'/mcp',preview,auth)[1]);second=json.loads(request(base,'/mcp',preview,auth)[1])
        events=json.loads(first['result']['content'][0]['text'])['events']
        assert_ok(len(events)==1,'webhook/poll identity deduplicates')
        assert_ok(first==second,'preview does not consume events')
        claim={'jsonrpc':'2.0','id':4,'method':'tools/call','params':{'name':'b24_claim_event','arguments':{}}}
        e=json.loads(json.loads(request(base,'/mcp',claim,auth)[1])['result']['content'][0]['text'])['event']
        get_case={'jsonrpc':'2.0','id':5,'method':'tools/call','params':{'name':'b24_get_case','arguments':{'case_id':e['data']['case_id']}}}
        case=json.loads(json.loads(request(base,'/mcp',get_case,auth)[1])['result']['content'][0]['text'])['case']
        apply={'jsonrpc':'2.0','id':6,'method':'tools/call','params':{'name':'b24_apply_plan','arguments':{'event_id':e['id'],'lease_token':e['lease_token'],'expected_version':case['version'],'actions':[{'type':'reply','message':'Draft reply'}]}}}
        reply=json.loads(request(base,'/mcp',apply,auth)[1]);assert_ok(not reply['result']['isError'],'claim/get/plan transaction over HTTP')
        # Exercise real cURL transport against this local mock, including mail envelope and batch splitting.
        test_php=r'''
        spl_autoload_register(function($c){$p='Bitrix24Mcp\\';if(str_starts_with($c,$p)) require getenv('B24_TEST_SRC').'/src/'.str_replace('\\','/',substr($c,strlen($p))).'.php';});
        $http=new Bitrix24Mcp\Core\HttpClient();
        $socket=stream_socket_server('tcp://127.0.0.1:0');$closed=stream_socket_get_name($socket,false);fclose($socket);
        $connection=$http->request('http://'.$closed,[]);
        $bad=$http->request(getenv('B24_TEST_BASE').'/fake/unauthorized',[]);
        $server=$http->request(getenv('B24_TEST_BASE').'/fake/server-error',[]);
        putenv('C_REST_WEB_HOOK_URL='.getenv('B24_TEST_BASE').'/fake/rest/');
        $batch=[];for($i=0;$i<120;$i++)$batch['item'.$i]=['method'=>'crm.contact.get','params'=>['id'=>$i]];
        $result=(new Bitrix24Mcp\Core\BitrixClient())->callBatch($batch);
        putenv('B24_REST_V3_URL='.getenv('B24_TEST_BASE').'/fake/');
        $mail=(new Bitrix24Mcp\Services\MailService())->reply(15,'Hello','mail-key',['from'=>'support@example.org','to'=>['client@example.org'],'subject'=>'Re: Test']);
        putenv('B24_AGENT_URL='.getenv('B24_TEST_BASE').'/fake/agent');
        $plan=(new Bitrix24Mcp\Services\AgentAdapter())->plan(['case'=>[]]);
        echo json_encode(['connection'=>$connection,'unauthorized'=>$bad,'server_error'=>$server,'batch_count'=>count($result['result']),'mail'=>$mail['success'],'plan'=>$plan]);
        '''
        env['B24_TEST_SRC']=str(SRC);env['B24_TEST_BASE']=base
        run=subprocess.run(['php','-r',test_php],env=env,capture_output=True,text=True,check=True)
        result=json.loads(run.stdout)
        assert_ok(not result['connection']['success'] and result['connection']['request_not_sent'] and not result['connection']['uncertain'],'refused TCP connection is safe to retry')
        assert_ok(not result['unauthorized']['success'] and result['unauthorized']['permanent'],'401 is a failure')
        assert_ok(not result['server_error']['success'] and result['server_error']['uncertain'],'500 requires cautious delivery handling')
        assert_ok(result['batch_count']==120,'batch is split without dropping items')
        assert_ok(result['mail'],'mail REST 3.0 success response')
        captures=[json.loads(x) for x in (temp/'capture.jsonl').read_text().splitlines()]
        mail=next(x for x in captures if x['path'].endswith('mail.message.reply'))
        assert_ok(mail['key']=='mail-key' and mail['body']['replyToMessageId']==15 and mail['body']['to']==['client@example.org'],'mail idempotency and original recipient')
        assert_ok(result['plan']['actions'][0]['type']=='reply','HTTP agent adapter')
        # Fail-closed setup check in a separate CLI PHP process, without printing any secret.
        code='require getenv("B24_TEST_SRC")."/settings.php"; if (MCP_HTTP_AUTH_TOKEN!=="") exit(2); echo C_REST_IGNORE_SSL ? "bad" : "tls-ok";'
        empty=env.copy();empty['MCP_HTTP_AUTH_TOKEN']=''
        r=subprocess.run(['php','-r',code],env=empty,capture_output=True,text=True,check=True)
        assert_ok(r.stdout=='tls-ok','TLS verification enabled by default')
    finally:
        server.terminate()
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:server.kill();server.wait()
        log.close()
print(f'{checks} HTTP/transport checks passed; all network traffic used loopback mocks')
