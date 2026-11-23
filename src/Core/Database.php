<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

use PDO;
use Throwable;

final class Database
{
    public readonly PDO $pdo;
    public function __construct(?PDO $pdo = null)
    {
        if ($pdo === null) {
            $dir = Env::dataDir();
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create private data directory');
            }
            $pdo = new PDO(Env::get('B24_DATABASE_DSN', 'sqlite:' . $dir . '/runtime.sqlite'),
                Env::get('DB_USERNAME'), Env::get('DB_PASSWORD'));
        }
        $this->pdo = $pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout=10000');
            $pdo->exec('PRAGMA journal_mode=WAL');
        }
    }
    public function driver(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME); }
    public function migrate(): void
    {
        $text = $this->driver() === 'mysql' ? 'LONGTEXT' : 'TEXT';
        foreach (Store::TABLES as $table) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS $table (
                id VARCHAR(191) PRIMARY KEY, payload $text NOT NULL, status VARCHAR(32) NOT NULL,
                available_at BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64) NOT NULL DEFAULT '',
                lease_until BIGINT NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL)");
        }
    }
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) { return $fn(); }
        $this->pdo->beginTransaction();
        try { $value = $fn(); $this->pdo->commit(); return $value; }
        catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
    }
}
