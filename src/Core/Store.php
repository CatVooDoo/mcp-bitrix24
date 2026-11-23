<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

/** Durable, versioned records. Conditional updates implement leases across workers. */
final class Store
{
    public const TABLES = ['cases', 'employee_requests', 'inbox_events', 'outbox_actions', 'source_cursors', 'audit_log'];
    public function __construct(public readonly Database $db) {}
    private function table(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) { throw new \InvalidArgumentException('Unknown table'); }
        return $table;
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public function get(string $table, string $id): ?array
    {
        $q = $this->db->pdo->prepare('SELECT * FROM ' . $this->table($table) . ' WHERE id=?');
        $q->execute([$id]); $row = $q->fetch();
        return $row ? $this->decode($row) : null;
    }
    private function decode(array $row): array
    {
        $row['data'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['payload']);
        foreach (['version','attempts','lease_until','available_at','updated_at'] as $key) { $row[$key] = (int)$row[$key]; }
        return $row;
    }
    public function insert(string $table, string $id, array $data, string $status = 'ready', int $availableAt = 0): bool
    {
        try {
            $q = $this->db->pdo->prepare('INSERT INTO ' . $this->table($table) . ' (id,payload,status,available_at,updated_at) VALUES (?,?,?,?,?)');
            $q->execute([$id, self::json($data), $status, $availableAt, time()]); return true;
        } catch (\PDOException $e) {
            if (in_array((string)$e->getCode(), ['23000','23505'], true) && $this->get($table, $id)) { return false; }
            throw $e;
        }
    }
    public function update(string $table, array $record, array $data, string $status, int $availableAt = 0): array
    {
        $q = $this->db->pdo->prepare('UPDATE ' . $this->table($table) . ' SET payload=?,status=?,available_at=?,version=version+1,updated_at=? WHERE id=? AND version=?');
        $q->execute([self::json($data),$status,$availableAt,time(),$record['id'],$record['version']]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Concurrent update; reload and retry'); }
        return $this->get($table, $record['id']);
    }
    public function list(string $table, ?string $status = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM ' . $this->table($table) . ($status === null ? '' : ' WHERE status=?') . ' ORDER BY updated_at,id LIMIT ' . max(1,min(1000,$limit));
        $q = $this->db->pdo->prepare($sql); $q->execute($status === null ? [] : [$status]);
        return array_map(fn($row) => $this->decode($row), $q->fetchAll());
    }
    /** Internal scans are paginated and cannot silently stop after 1000 records. */
    public function scan(string $table, ?string $status = null): \Generator
    {
        $after=''; $table=$this->table($table);
        do {
            $q=$this->db->pdo->prepare("SELECT * FROM $table WHERE id>?".($status===null ? '' : ' AND status=?').' ORDER BY id LIMIT 200');
            $q->execute($status===null ? [$after] : [$after,$status]);$rows=$q->fetchAll();
            foreach ($rows as $row) { $after=$row['id'];yield $this->decode($row); }
        } while (count($rows)===200);
    }
    public function all(string $table, ?string $status = null): array
    {
        return iterator_to_array($this->scan($table,$status),false);
    }
    public function claim(string $table, int $seconds = 120): ?array
    {
        $table = $this->table($table); $now = time();
        $q = $this->db->pdo->prepare("SELECT id,version FROM $table WHERE available_at<=? AND (status='ready' OR (status='processing' AND lease_until<?)) ORDER BY available_at,updated_at,id LIMIT 20");
        $q->execute([$now,$now]);
        foreach ($q->fetchAll() as $row) {
            $token = self::id();
            $u = $this->db->pdo->prepare("UPDATE $table SET status='processing',lease_token=?,lease_until=?,attempts=attempts+1,version=version+1 WHERE id=? AND version=? AND (status='ready' OR (status='processing' AND lease_until<?))");
            $u->execute([$token,$now+$seconds,$row['id'],$row['version'],$now]);
            if ($u->rowCount() === 1) { return $this->get($table,$row['id']); }
        }
        return null;
    }
    public function requireLease(string $table, string $id, string $token): array
    {
        $row = $this->get($table,$id);
        if (!$row || $row['status'] !== 'processing' || $row['lease_until'] < time() || !hash_equals($row['lease_token'],$token)) {
            throw new \RuntimeException('Missing or expired lease');
        }
        return $row;
    }
    public function retry(string $table, array $row, string $error, bool $permanent = false): void
    {
        $data = $row['data']; $data['last_error'] = $error;
        $dead = $permanent || $row['attempts'] >= 8;
        $this->update($table,$row,$data,$dead ? 'dead' : 'ready',time()+min(3600, 5 * 2 ** min(9,$row['attempts'])) + random_int(0,5));
    }
    public function audit(string $event, string $entityId, array $details = []): void
    {
        $this->insert('audit_log',self::id(),['event'=>$event,'entity_id'=>$entityId,'details'=>$details],'done');
    }
}
