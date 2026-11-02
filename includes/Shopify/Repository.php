<?php
namespace WD29\Bridge\Shopify;

/** Separate connection history: never reuses the Woo/PrestaShop pair's mappings. */
final class Repository
{
    private $adapter;
    private $table;
    public function __construct($adapter) { $this->adapter = $adapter; $this->table = $adapter->prefix() . 'inklura_shopify_'; }
    public function sql(string $sql, array $args = []) { return $this->adapter->sql(str_replace('{s}', $this->table, $sql), $args); }
    public function install(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS {s}map (kind varchar(16) NOT NULL, remote_id varchar(96) NOT NULL, local_id bigint unsigned NOT NULL, local_hash char(64) NOT NULL DEFAULT '', remote_hash char(64) NOT NULL DEFAULT '', address_hash char(64) NOT NULL DEFAULT '', quantity bigint NULL, inventory_id varchar(96) NOT NULL DEFAULT '', parent_id varchar(96) NOT NULL DEFAULT '', PRIMARY KEY(kind,remote_id), UNIQUE KEY native_identity(kind,local_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->sql("CREATE TABLE IF NOT EXISTS {s}queue (seq bigint unsigned NOT NULL AUTO_INCREMENT, event_id char(64) NOT NULL, kind varchar(24) NOT NULL, remote_id varchar(96) NOT NULL, state varchar(16) NOT NULL DEFAULT 'pending', attempts int NOT NULL DEFAULT 0, next_try bigint NOT NULL DEFAULT 0, error varchar(96) NOT NULL DEFAULT '', intent longtext NULL, created_at bigint NOT NULL, PRIMARY KEY(seq), UNIQUE KEY event_identity(event_id), KEY pending_jobs(state,next_try), KEY resource_order(remote_id,state,seq)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function map(string $kind, string $remote): ?array { return $this->sql('SELECT * FROM {s}map WHERE kind=? AND remote_id=?', [$kind,$remote])[0] ?? null; }
    public function local(string $kind, int $id): ?array { return $this->sql('SELECT * FROM {s}map WHERE kind=? AND local_id=?', [$kind,$id])[0] ?? null; }
    public function bind(string $kind, string $remote, int $id, string $parent = '', string $inventory = ''): array
    {
        if (!in_array($kind, ['product','variant','customer','order','coupon'], true) || $id < 1) { throw new Failure('invalid_mapping'); }
        $existing = $this->map($kind, $remote); $owner = $this->local($kind, $id);
        if (($existing && (int)$existing['local_id'] !== $id) || ($owner && $owner['remote_id'] !== $remote)) { throw new Failure('mapping_identity_conflict'); }
        $this->sql('INSERT IGNORE INTO {s}map (kind,remote_id,local_id,parent_id,inventory_id) VALUES (?,?,?,?,?)', [$kind,$remote,$id,$parent,$inventory]);
        $existing = $this->map($kind, $remote);
        if (!$existing || (int)$existing['local_id'] !== $id) { throw new Failure('mapping_identity_conflict'); }
        return $existing;
    }
    public function hashes(string $kind, string $remote, array $local, array $source): void
    {
        $this->sql('UPDATE {s}map SET local_hash=?,remote_hash=? WHERE kind=? AND remote_id=?', [Model::hash($local),Model::hash($source),$kind,$remote]);
        if ($kind==='customer') {
            $this->sql('UPDATE {s}map SET address_hash=? WHERE kind=? AND remote_id=?',[Model::addressHash($local['billing']),$kind,$remote]);
            $map=$this->map($kind,$remote);update_user_meta((int)$map['local_id'],'_inklura_shopify_address_hash',Model::fullAddressHash($local['billing']));
        }
    }
    public function enqueue(string $kind, string $remote, ?string $delivery = null): void
    {
        if (!in_array($kind, ['product','customer','order','inventory','product_deleted','customer_deleted','export_product','export_customer','coupon','export_coupon','coupon_deleted','discount_unsupported'], true)) { throw new Failure('invalid_job_kind'); }
        if($kind==='coupon' || $kind==='coupon_deleted'){$this->sql("UPDATE {s}queue SET next_try=0 WHERE kind='coupon' AND remote_id=? AND state='pending' AND error='scheduled_discount_waiting'",[$remote]);}
        if ($delivery === null && $this->sql("SELECT seq FROM {s}queue WHERE kind=? AND remote_id=? AND state IN ('pending','failed','conflict') LIMIT 1", [$kind,$remote])) { return; }
        $id = hash('sha256', $delivery === null ? random_bytes(32) : $delivery);
        $this->sql('INSERT IGNORE INTO {s}queue (event_id,kind,remote_id,created_at) VALUES (?,?,?,?)', [$id,$kind,$remote,time()]);
    }
    public function jobs(int $limit = 5): array
    {
        return $this->sql("SELECT q.* FROM {s}queue q WHERE q.state='pending' AND q.next_try<=? AND NOT EXISTS (SELECT 1 FROM {s}queue older WHERE older.remote_id=q.remote_id AND older.seq<q.seq AND older.state IN ('pending','failed','conflict')) ORDER BY q.seq LIMIT " . max(1,min(10,$limit)), [time()]);
    }
    public function state(array $job, string $state, string $code = '', int $delay = 0): void
    {
        if (!in_array($state, ['pending','done','audit','failed','conflict'], true)) { throw new Failure('invalid_job_state'); }
        $this->sql('UPDATE {s}queue SET state=?,error=?,attempts=attempts+1,next_try=? WHERE seq=?', [$state,$code,time()+$delay,$job['seq']]);
    }
    public function intent(array $job, array $intent): void { $this->sql('UPDATE {s}queue SET intent=? WHERE seq=?', [json_encode($intent,JSON_THROW_ON_ERROR),$job['seq']]); }
    public function lock(): bool { return (int)($this->sql('SELECT GET_LOCK(?,0) AS acquired', ['inklura_shopify_' . sha1($this->table)])[0]['acquired'] ?? 0) === 1; }
    public function unlock(): void { $this->sql('SELECT RELEASE_LOCK(?)', ['inklura_shopify_' . sha1($this->table)]); }
    public function retry(int $seq): void { $this->sql("UPDATE {s}queue SET state='pending',next_try=0,error='' WHERE seq=? AND state IN ('failed','conflict','audit')", [$seq]); }
    public function summary(): array { return $this->sql('SELECT state,COUNT(*) AS total FROM {s}queue GROUP BY state'); }
}
