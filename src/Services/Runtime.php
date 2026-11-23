<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{Database,Policy,Store};

final class Runtime
{
    private static ?CaseService $instance=null;
    public static function cases(): CaseService
    {
        if (!self::$instance) {
            $db=new Database(); $db->migrate();
            self::$instance=new CaseService(new Store($db),new Policy());
        }
        return self::$instance;
    }
}
