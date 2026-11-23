<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{Env,Policy,Store};

/** Private, atomically written per-dialog archive. Database remains authoritative. */
final class DialogArchive
{
    public function __construct(private readonly Policy $policy) {}
    public function directory(string $dialog): string
    {
        $path=Env::dataDir().'/portals/'.substr(hash('sha256',$this->policy->get('portal_id')),0,24).'/dialogs/'.hash('sha256',$dialog);
        if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) { throw new \RuntimeException('Cannot create dialog archive'); }
        $this->write($path.'/manifest.json',['dialog_id'=>$dialog,'portal_id'=>$this->policy->get('portal_id')]);
        return $path;
    }
    public function write(string $path,array $data): void
    {
        $tmp=$path.'.'.Store::id().'.tmp';
        if (file_put_contents($tmp,Store::json($data),LOCK_EX)===false) { throw new \RuntimeException('Archive write failed'); }
        chmod($tmp,0600);
        if (!rename($tmp,$path)) { @unlink($tmp); throw new \RuntimeException('Archive rename failed'); }
    }
    public function record(string $dialog,string $kind,string $id,array $data): void
    {
        if (!in_array($kind,['events','actions','requests'],true)) { throw new \InvalidArgumentException('Invalid archive kind'); }
        $dir=$this->directory($dialog).'/'.$kind;
        if (!is_dir($dir)) { mkdir($dir,0700,true); }
        $this->write($dir.'/'.hash('sha256',$id).'.json',$data);
    }
    public function snapshot(array $case): void
    {
        $dir=$this->directory($case['data']['dialog_id']);
        $lock=fopen($dir.'/snapshot.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new \RuntimeException('Archive lock failed'); }
        try {
            $path=$dir.'/case.json'; $old=is_file($path) ? json_decode((string)file_get_contents($path),true) : null;
            if (!$old || (int)$old['version']<=$case['version']) { $this->write($path,$case); }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function dossier(string $dialog,callable $fetch,bool $refresh=false): array
    {
        $dir=$this->directory($dialog); $path=$dir.'/crm.json';
        $lock=fopen($dir.'/crm.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new \RuntimeException('CRM cache lock failed'); }
        try {
            $cache=is_file($path) ? json_decode((string)file_get_contents($path),true) : null;
            if (!$refresh && is_array($cache) && $cache['expires_at']>time()) { return $cache+['cached'=>true,'stale'=>false]; }
            $result=$fetch();
            if (empty($result['success'])) {
                if ($cache) { return array_merge($cache,['success'=>false,'cached'=>true,'stale'=>true,'refresh_error'=>$result['error'] ?? 'unavailable']); }
                return $result+['cached'=>false,'stale'=>true];
            }
            $cache=['success'=>true,'fetched_at'=>time(),'expires_at'=>time()+max(1,(int)Env::get('B24_CRM_CACHE_TTL','300')),'data'=>$result];
            $this->write($path,$cache);
            return $cache+['cached'=>false,'stale'=>false];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function invalidate(string $dialog): void
    {
        $path=$this->directory($dialog).'/crm.json'; if (is_file($path)) { unlink($path); }
    }
}
