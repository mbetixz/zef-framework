# Database Core & orkestrasi transaksi

> **v2.18.0** — Database Core: query builder, adapter PDO, migrator, repository base.
> **v2.22.0 / v2.22.1** — orkestrasi transaksi: `TransactionManager`,
> `TransactionalCommandBus`, `UnitOfWork`, retry flush opt-in, dan log hook lambat.

Panduan ini menjelaskan cara memakai lapisan database ZEF: membangun SQL yang
aman, membuka koneksi PDO, menjalankan transaksi (termasuk nested dan isolation
level), mengelola migrasi skema, menulis repository, dan mengorkestrasi
transaksi di sekitar command CQRS.

Semua kelas berada di namespace `Zef\Framework\Database` (kecuali
`TransactionalCommandBus` di `Zef\Framework\CQRS`). Tidak ada dependensi
Composer baru. Anda hanya butuh `ext-pdo` beserta driver `pdo_mysql`,
`pdo_pgsql`, atau `pdo_sqlite`.

| Komponen | Layer | Fungsi |
|----------|-------|--------|
| `QueryBuilder`, `SqlQuery`, `SqlExpression` | Domain | Membangun SQL + parameter terikat, tanpa koneksi |
| `ConnectionInterface`, `IsolationLevel` | Domain (port) | Kontrak eksekusi dan transaksi |
| `PdoConnection`, `ConnectionConfig` | Infrastructure | Adapter PDO untuk MySQL, PostgreSQL, SQLite |
| `Migrator`, `MigrationInterface` | Application | Migrasi skema berversi dengan lock |
| `Repository` | Application | Gateway tabel tipis di atas `QueryBuilder` |
| `TransactionManagerInterface`, `TransactionManager` | Domain / Application | Scope transaksi terkelola + hook `afterCommit` |
| `UnitOfWork`, `UnitOfWorkRetryPolicy` | Application / Domain | Antrean tulisan deferred + retry transient opt-in |
| `TransactionalCommandBus` | Application | Decorator command bus: satu command = satu transaksi |

---

## Query builder

`QueryBuilder` adalah builder fluent yang murni. Builder tidak menyentuh
koneksi. Hasil `build()` adalah value object `SqlQuery{sql, params}` dengan
parameter posisional `?`. Output deterministik: state builder yang sama selalu
menghasilkan string SQL yang sama.

```php
use Zef\Framework\Database\QueryBuilder;

$query = QueryBuilder::table('orders', 'o')
    ->select('o.id', 'o.total', 'customers.name AS customer')
    ->leftJoin('customers', 'customers.id', '=', 'o.customer_id')
    ->where('o.status', '=', 'paid')
    ->whereIn('o.region', ['id', 'sg'])
    ->whereNotNull('o.shipped_at')
    ->orderBy('o.created_at', 'DESC')
    ->limit(20)
    ->build();

$rows = $connection->fetchAll($query);
```

Fitur yang didukung:

- **SELECT:** `select()`, alias `AS`, `distinct()`, `join()` (inner/left/right/cross),
  `leftJoin()`, `groupBy()`, `having()`, `orderBy()`, `limit()`, `offset()`.
- **Kondisi:** `where()`, `orWhere()`, `whereColumn()`, `whereNested()` (grup
  kurung), `whereIn()`/`whereNotIn()` (list atau subquery `QueryBuilder`),
  `whereNull()`, `whereNotNull()`, `whereBetween()`, `whereLike()`.
  `whereLike($col, $pattern, escapeWildcards: true)` meng-escape `%`/`_` dan
  menambahkan klausa `ESCAPE '\'`.
- **Agregat:** `count()` dan `aggregate($fn, $col)` dengan whitelist
  `COUNT`/`SUM`/`AVG`/`MIN`/`MAX`. Hasilnya `SELECT COUNT(*) AS aggregate`.
- **Tulis:** `insert()`, `insertRows()` (multi-baris), `update()`, `delete()`.
- **Inspeksi:** `toSql()` dan `getBindings()`.

### Grammar identifier anti-injection

Setiap identifier (tabel, kolom, alias) divalidasi dengan grammar
`[A-Za-z_][A-Za-z0-9_]{0,63}` lalu di-double-quote. Nama boleh memuat maksimal
satu titik (`table.column`). Input pengguna tidak pernah bisa menjadi sintaks
SQL. Semua nilai menjadi placeholder `?`. Nilai yang diterima hanya
`null`/`bool`/`int`/`float`/`string`/`SqlExpression`.

Guard lain yang aktif secara default:

- `UPDATE`/`DELETE` tanpa `WHERE` melempar exception, kecuali Anda memanggil
  `allowUnbounded()` lebih dulu.
- `where('col', '=', null)` ditolak. Gunakan `whereNull()`.
- `whereIn()` dengan list kosong ditolak.

### SQL mentah lewat `SqlExpression`

Untuk fungsi, ekspresi, atau fragmen dinamis, bungkus SQL secara eksplisit
agar raw SQL tetap mudah di-grep. Jika Anda mengirim fragmen mentah (berisi
spasi, `(`, `,`, atau operator) sebagai identifier, `QueryBuilder` menolaknya
dan menunjuk ke salah satu jalur berikut:

```php
use Zef\Framework\Database\SqlExpression;
use Zef\Framework\Database\SqlQuery;

QueryBuilder::table('events')
    ->select('id', new SqlExpression('LOWER(name) AS name_lc'))
    ->where('created_at', '<', new SqlExpression('CURRENT_TIMESTAMP'));

QueryBuilder::table('events')->selectRaw('COUNT(DISTINCT user_id) AS users');

$connection->execute(SqlQuery::raw('VACUUM'));
```

> **Peringatan:** isi `SqlExpression`, `selectRaw()`, dan `SqlQuery::raw()`
> tidak divalidasi. Jangan pernah menyisipkan input pengguna ke dalamnya.

### Tulisan tanpa batas (`allowUnbounded`)

```php
$query = QueryBuilder::table('sessions')->delete()->allowUnbounded()->build();
```

`SqlQuery::$unbounded` menandai statement ini. Saat `PdoConnection` dibuat
dengan `MeterInterface` dan/atau `LogExporterInterface`, eksekusinya
memancarkan counter `zef.db.unbounded_statement` dan record WARN berisi SQL
lengkap sebagai jejak audit.

---

## Koneksi PDO

### Konfigurasi

`ConnectionConfig::fromArray()` memvalidasi konfigurasi saat konstruksi.
Konfigurasi yang salah melempar `ConnectionException` sebelum ada socket yang
dibuka.

| Kunci | Tipe | Keterangan |
|-------|------|------------|
| `driver` | `string` | Wajib. `mysql`, `pgsql`, atau `sqlite` |
| `dbname` | `string` | Wajib. Untuk SQLite: path file atau `:memory:` |
| `host` | `string` | Wajib untuk mysql/pgsql. Tanpa spasi, maksimal 255 byte |
| `port` | `int` | Wajib untuk mysql/pgsql. Rentang 1–65535 |
| `user`, `password` | `?string` | Opsional |
| `charset` | `?string` | Opsional. Default `utf8mb4` untuk MySQL |
| `options` | `array` | Hanya `persistent` (`bool`) dan `timeout` (`int\|float` > 0) |

```php
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;

$config = ConnectionConfig::fromArray([
    'driver'   => 'pgsql',
    'host'     => getenv('DB_HOST'),
    'port'     => 5432,
    'dbname'   => 'app',
    'user'     => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'options'  => ['timeout' => 5],
]);

$connection = new PdoConnection($config);
// Opsional: new PdoConnection($config, null, $meter, $auditLogs);
```

Koneksi bersifat lazy. PDO baru terhubung saat statement pertama dieksekusi.

`ConnectionInterface` menyediakan `execute()` (jumlah baris terdampak),
`fetchAll()`, `fetchOne()`, dan `lastInsertId()`.

### Pemetaan error

`\PDOException` tidak pernah bocor ke aplikasi. Adapter memetakannya ke:

- `ConnectionException` untuk kegagalan fase koneksi atau konfigurasi.
- `QueryException` untuk kegagalan prepare/execute. Pesannya memuat SQL.
- `TransactionException` untuk penyalahgunaan transaksi (commit tanpa begin,
  nesting melebihi batas, dan sejenisnya).

Ketiganya turunan `DatabaseException`.

### Transaksi nested dengan SAVEPOINT

`transaction(callable)` membuka transaksi, menjalankan callback dengan
koneksi sebagai argumen, lalu commit. Throwable apa pun memicu rollback
otomatis dan dilempar ulang.

```php
$orderId = $connection->transaction(function (ConnectionInterface $conn): string {
    $conn->execute(QueryBuilder::table('orders')->insert(['total' => 100])->build());
    $id = $conn->lastInsertId();

    // Nested: menjadi SAVEPOINT, bukan transaksi baru.
    $conn->transaction(function (ConnectionInterface $inner) use ($id): void {
        $inner->execute(QueryBuilder::table('audit')->insert(['order_id' => $id])->build());
    });

    return $id;
});
```

- Level terluar memakai `BEGIN`. Level dalam memakai `SAVEPOINT zef_sp2`,
  `zef_sp3`, dan seterusnya.
- `transactionLevel()` mengembalikan kedalaman saat ini.
- Batas nesting 16 level.
- Anda juga dapat memakai `beginTransaction()`/`commit()`/`rollBack()`
  manual. Adapter memetakan panggilan nested ke savepoint dengan aturan yang sama.

### Isolation level

Enum `IsolationLevel`: `ReadUncommitted`, `ReadCommitted`, `RepeatableRead`,
`Serializable`.

```php
use Zef\Framework\Database\IsolationLevel;

$connection->transaction($fn, IsolationLevel::Serializable);
```

- Isolation hanya diterapkan di transaksi terluar melalui
  `SET TRANSACTION ISOLATION LEVEL`.
- Pada `transaction()` nested, argumen isolation **diabaikan tanpa error**.
- Pada `beginTransaction($isolation)` nested, adapter melempar `TransactionException`.
- SQLite tidak mendukung isolation level dan menolaknya dengan pesan eksplisit.

---

## Migrasi

`Migrator` menerapkan migrasi berversi secara berurutan dan mencatatnya di
tabel `zef_migrations`.

### Menulis migrasi

Implementasikan `MigrationInterface`. Versi wajib 14 digit dengan format
`YYYYmmddHHMMSS`.

```php
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\MigrationInterface;
use Zef\Framework\Database\SqlQuery;

final class CreateOrdersTable implements MigrationInterface
{
    public function version(): string { return '20260925090000'; }
    public function name(): string { return 'create_orders_table'; }

    public function up(ConnectionInterface $connection): void
    {
        $connection->execute(SqlQuery::raw(
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER NOT NULL)'
        ));
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute(SqlQuery::raw('DROP TABLE orders'));
    }

    // null = pakai TTL lock default Migrator; isi lebih besar untuk step berat.
    public function getLockTtl(): ?float { return null; }
}
```

### Menjalankan migrasi

```php
use Zef\Framework\Database\Migrator;

$migrator = new Migrator($connection);          // TTL lock default 300.0 detik
$migrator->register(new CreateOrdersTable());

$migrator->plan();       // list<string> versi yang akan diterapkan, tanpa efek samping
$migrator->migrate();    // terapkan semua yang pending, urut ascending
$migrator->rollback(1);  // batalkan N migrasi terakhir, urut descending
```

- Setiap migrasi berjalan di transaksinya sendiri. `up()` dan pencatatan di
  `zef_migrations` di-commit atau di-rollback bersama. Jika `up()` melempar,
  versinya tidak tercatat.
- `applied()` dan `pending()` mengembalikan daftar versi.
- `rollback()` menolak jumlah langkah yang tidak valid dan versi yang tidak terdaftar.

### Lock migrasi

`Migrator` memegang lock satu baris di tabel `zef_migrations_lock` agar dua
runner tidak berjalan bersamaan (misalnya saat beberapa pod deploy sekaligus).

- Runner kedua ditolak dengan pesan yang memuat `age Ns, ttl Ns`.
- Lock dianggap stale berdasarkan TTL yang **tercatat di baris lock**, bukan
  TTL runner yang mencoba merebutnya.
- Sebelum setiap step, runner memperbarui lock (heartbeat) dengan TTL efektif.
  TTL efektif adalah `getLockTtl()` milik migrasi tersebut, atau
  `$lockTtlSeconds` dari constructor `Migrator`. Step lambat seperti
  `ALTER TABLE` besar tidak akan direbut di tengah jalan.
- Nilai TTL ≤ 0 ditolak dengan `InvalidArgumentException`.
- Lock selalu dilepas di blok `finally`, termasuk saat migrasi gagal.
- Argumen kedua constructor (`?callable $now`) menerima penyedia waktu unix
  untuk pengujian.

---

## Repository base

`Repository` adalah gateway tabel tipis di atas `QueryBuilder`. Kelas ini
sengaja **bukan ORM**: tidak ada identity map, lazy relation, atau change tracking.

```php
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\Repository;

final class OrderRepository extends Repository
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection, 'orders'); // nama tabel divalidasi di sini
    }

    /** @return list<array<string, mixed>> */
    public function recentPaid(int $limit): array
    {
        return $this->connection->fetchAll(
            $this->qb()->where('status', '=', 'paid')->orderBy('id', 'DESC')->limit($limit)->build(),
        );
    }
}

$orders = new OrderRepository($connection);
$orders->insert(['total' => 100, 'status' => 'paid']);
$orders->find(1);
$orders->findBy(['status' => 'paid', 'deleted_at' => null], ['id' => 'DESC'], 10);
$orders->update(['status' => 'shipped'], ['id' => 1]);
```

- Metode publik: `insert`, `find`, `findOneBy`, `findBy`, `count`, `exists`,
  `update`, `delete`. Metode `qb()` (protected) mengembalikan builder baru
  yang terikat ke tabel repository.
- Kriteria berbentuk array `kolom => nilai`. Nilai `null` otomatis menjadi `IS NULL`.
- `update()` dan `delete()` tanpa kriteria ditolak dengan `InvalidArgumentException`.

---

## Orkestrasi transaksi

Primitif koneksi di atas sudah benar untuk satu scope. Lapisan orkestrasi
v2.22.0 menambahkan tiga hal di atasnya:

1. Kode yang berjalan **setelah commit** (event, invalidasi cache) dan hilang saat rollback.
2. Satu command CQRS = satu transaksi.
3. Tulisan dari beberapa titik handler yang di-flush secara atomik sebelum commit.

### TransactionManager

```php
use Zef\Framework\Database\TransactionManager;

$tx = new TransactionManager($connection);

$tx->withTransaction(function (ConnectionInterface $conn) use ($tx, $cache): void {
    $conn->execute(QueryBuilder::table('orders')->update(['status' => 'paid'])->where('id', '=', 7)->build());

    $tx->afterCommit(static fn () => $cache->delete('order:7'));
});
```

- `withTransaction($fn, ?IsolationLevel)` membuka scope terkelola. Pemanggilan
  nested menjadi savepoint. Commit hanya terjadi di scope terluar.
- `afterCommit($hook)` mengantrekan hook tanpa argumen:
  - Hook berjalan FIFO **setelah** commit terluar sukses.
  - Saat rollback, antrean hook dibuang. Command yang gagal tidak pernah
    memancarkan event.
  - Di luar scope terkelola, hook langsung dijalankan. Kode pemanggil tidak
    perlu tahu apakah sedang berada di dalam transaksi.
  - Hook yang mendaftarkan hook lain saat drain dijalankan inline.
- `inTransaction()` dan `level()` melaporkan status scope terkelola.

Hook yang melempar exception **tidak** membatalkan transaksi. Data sudah
ter-commit saat hook berjalan. Baca
[`TRANSACTION-HOOKS.md`](TRANSACTION-HOOKS.md) untuk semantik error per fase
dan kontrak yang wajib dipatuhi hook (idempotent, cepat, tanpa tulisan DB di
koneksi yang sama).

#### Log hook lambat (v2.22.1)

`TransactionManager` menerima ambang durasi hook dan logger PSR-3 opsional:

```php
$tx = new TransactionManager(
    connection: $connection,
    hookDurationThresholdMs: 50,   // null (default) = tanpa pengukuran
    logger: $psrLogger,            // null (default) = diam
);
```

Saat ambang di-set, `TransactionManager` mengukur setiap hook dengan
`hrtime()`. Hook yang melebihi ambang memicu log level `debug` dengan pesan
`afterCommit hook exceeded slow-hook threshold` dan konteks `elapsed_ms` dan
`threshold_ms`. Guard ini kooperatif dan hanya untuk observabilitas. Hook
tidak dihentikan paksa. Rekomendasi ambang: 50 ms untuk scope request, 500 ms
untuk job/worker.

### UnitOfWork

`UnitOfWork` adalah antrean tulisan deferred (UoW-lite), bukan pelacak entitas.

```php
use Zef\Framework\Database\UnitOfWork;

$uow = new UnitOfWork();
$uow->recordQuery(QueryBuilder::table('orders')->insert(['total' => 100])->build());
$uow->record(static function (ConnectionInterface $conn): void {
    $conn->execute(QueryBuilder::table('stock')->update(['qty' => 9])->where('sku', '=', 'A1')->build());
});

$uow->pending();                        // 2
$tx->withTransaction(fn (ConnectionInterface $conn) => $uow->flush($conn));
```

- `record($op)` dan `recordQuery($sql)` menambah operasi ke antrean FIFO.
- `flush($conn)` mengeksekusi operasi secara berurutan dan mengembalikan
  jumlah operasi. Antrean dikosongkan **sebelum** eksekusi, sehingga
  operasi yang gagal tidak pernah diulang dari antrean yang sama.
- `discard()` membatalkan antrean dan mengembalikan jumlah operasi yang dibuang.
- Memanggil `flush()` atau `record()` saat flush sedang berjalan melempar
  `TransactionException`.

### TransactionalCommandBus

Decorator `CommandBusInterface` yang menjalankan setiap `dispatch()` di dalam
`withTransaction`. `UnitOfWork` opsional di-flush ke koneksi transaksi
sebelum commit. Jika flush gagal, seluruh command di-rollback.

```php
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\TransactionalCommandBus;

$tx  = new TransactionManager($connection);
$uow = new UnitOfWork();

// Event hasil handler dirutekan lewat afterCommit() saat $tx dipasang.
$inner = new CommandBus(eventBus: $eventBus, transactions: $tx);

$bus = new TransactionalCommandBus(
    inner: $inner,
    transactions: $tx,
    unitOfWork: $uow,                       // opsional
    isolation: IsolationLevel::ReadCommitted, // opsional, hanya scope terluar
);

$bus->register(PlaceOrder::class, new PlaceOrderHandler($uow));
$bus->dispatch(new PlaceOrder(/* ... */));
```

- Dispatch nested melalui decorator yang sama menjadi savepoint. Command
  terluar yang memegang commit.
- Parameter `transactions` pada `CommandBus` bersifat opsional. Tanpa parameter
  ini, fan-out event berjalan dengan timing pra-v2.22 (langsung setelah handler).

### Retry flush untuk kegagalan transien (v2.22.1, opt-in)

Deadlock, lock-wait timeout, dan serialization failure biasanya hilang dalam
hitungan milidetik. `UnitOfWorkRetryPolicy` mengizinkan fase **flush**
di-retry tanpa menjalankan ulang handler.

```php
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

$policy = new UnitOfWorkRetryPolicy(
    maxAttempts: 3,        // total percobaan, termasuk yang pertama (≥ 1)
    initialDelayMs: 100,   // jeda sebelum percobaan ke-2 (≥ 0)
    maxDelayMs: 30_000,    // batas atas backoff (≥ initialDelayMs)
    multiplier: 2.0,       // faktor backoff eksponensial (≥ 1.0)
    jitterMs: 25,          // jitter acak ± (≥ 0)
);

$bus = new TransactionalCommandBus($inner, $tx, $uow, retryPolicy: $policy);

// atau langsung:
$uow->flushRetrying($conn, $policy);
```

- Default `retryPolicy` adalah `null`: tanpa retry, sama dengan perilaku v2.22.0.
- `flushRetrying()` mempertahankan snapshot antrean di setiap percobaan.
  Antrean baru dikosongkan setelah flush sukses. `flush()` biasa tidak berubah.
- Throwable dianggap retryable jika merupakan instance salah satu
  `retryableClassNames` (default `PDOException`) dan kodenya ada di
  `retryableSqlStates`. Kode default adalah `40001`, `40P01`, `55P03`
  (PostgreSQL) serta `1213`, `1205`, `1040`, `2006` (MySQL/MariaDB). Daftar
  SQLSTATE kosong berarti pencocokan hanya berdasarkan kelas.
- Hanya flush yang di-retry, bukan body handler. Efek samping handler tidak
  pernah diulang.

Detail desain dan batasan retry ada di
[`TRANSACTION-HOOKS.md` § UoW retry strategy](TRANSACTION-HOOKS.md#uow-retry-strategy-v2221-item-2-of-issue-65).

---

## Lihat juga

- [`TRANSACTION-HOOKS.md`](TRANSACTION-HOOKS.md) — semantik error hook `afterCommit`, kontrak hook, timeout, strategi retry.
- [`TUTORIAL-CQRS-101.md`](TUTORIAL-CQRS-101.md) — wiring command bus.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — peta layer hexagonal.
- `CHANGELOG-v2.18.0.md`, `CHANGELOG-v2.22.0.md`, `CHANGELOG-v2.22.1.md` — catatan rilis.
