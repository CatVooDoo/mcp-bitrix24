<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

/** Shell-free process with bounded output and nonblocking stdin/stdout/stderr. */
final class CommandRunner
{
    public function run(array $argv, string $input, string $cwd, int $timeout = 120, ?array $environment = null, ?callable $finished = null): string
    {
        if (!$argv || !array_is_list($argv) || count(array_filter($argv,'is_string'))!==count($argv) || $argv[0]==='') {
            throw new \InvalidArgumentException('Command must be a nonempty JSON argv array');
        }
        $process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$environment,['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start agent command'); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe,false); }
        $out=''; $exit=-1; $started=microtime(true); $running=true; $offset=0; $inputOpen=true;
        try {
            do {
                // Drain output while feeding input: either side may fill its pipe first.
                $out.=stream_get_contents($pipes[1],65536);
                stream_get_contents($pipes[2],65536);
                if (strlen($out)>1048576) { throw new \RuntimeException('Agent output too large'); }
                // Streaming CLIs can keep their session open after the final result.
                if ($finished!==null && $finished($out)) { $exit=0;break; }
                if ($inputOpen) {
                    if ($offset<strlen($input)) {
                        $written=@fwrite($pipes[0],substr($input,$offset,65536));
                        if ($written===false) { throw new \RuntimeException('Agent closed its input before consuming the context'); }
                        $offset+=$written;
                    }
                    if ($offset===strlen($input)) { fclose($pipes[0]);$inputOpen=false; }
                }
                $status=proc_get_status($process); $running=$status['running'];
                if (!$running) { $exit=$status['exitcode']; break; }
                if (microtime(true)-$started>max(1,$timeout)) { throw new \RuntimeException('Agent command timeout'); }
                usleep(20000);
            } while (true);
            $out.=stream_get_contents($pipes[1]);
            if (strlen($out)>1048576) { throw new \RuntimeException('Agent output too large'); }
        } finally {
            if ($running) { proc_terminate($process,9); }
            if ($inputOpen) { fclose($pipes[0]); }
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if ($exit!==0) { throw new \RuntimeException('Agent command failed (exit '.$exit.'); check CLI installation, authentication and quota'); }
        return $out;
    }
}
