# Configuration System v2

Configuration System v2 (sejak **v2.21.0**, diperkuat di **v2.21.1** dan
**v2.23.0**) adalah lapisan **pengaturan aplikasi**: nilai seperti host
database, timeout, feature flag, dan kredensial yang berbeda per lingkungan.
Anda mendaftarkan satu atau lebih *sumber* (file PHP, environment, file
terkompilasi), opsional sebuah *secrets provider* dan *skema*, lalu kernel
memvalidasi semuanya saat `boot()` dan menyediakan bag `Config` bertipe dan
immutable.

Semua kelas berada di namespace `Zef\Framework\Config`; kernel adalah
`Zef\Framework\Application`.

---

## 1. Hubungan dengan config modul (`ConfigAggregator` / `config:show`)

ZEF punya dua mekanisme config yang **saling melengkapi** dan tidak saling
menggantikan:

| | Config modul (v2.7.0) | Configuration System v2 (v2.21.0+) |
|---|---|---|
| Isi | Definisi modul: service, route, middleware, kebijakan container | Pengaturan aplikasi per lingkungan |
| Didaftarkan lewat | `ConfigProviderInterface` → `Application::addProvider()` | `registerConfigSource()` / `registerSecretsProvider()` / `setConfigSchema()` |
| Dibaca lewat | `Application::getConfigAggregator()` → `ConfigAggregator::get()` | `Application::config()` atau singleton `Config::class` |
| Validasi | Tidak ada skema | Skema bertipe, fail-fast saat boot |
| CLI | `bin/zef config:show [key]` (lihat [`CLI.md`](CLI.md)) | Tidak ditampilkan oleh `config:show` |

Gunakan `make:config` / `ConfigProvider` untuk menyatakan **apa yang disediakan
modul**. Gunakan Config v2 untuk **nilai yang diatur operator** per deployment.

---

## 2. Mulai cepat

```php
<?php

use Zef\Framework\Application;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\EnvConfigSource;
use Zef\Framework\Config\FileSecretsProvider;
use Zef\Framework\Config\PhpFileConfigSource;

enum AppEnv: string { case Dev = 'dev'; case Prod = 'prod'; }

$app = new Application();

// Urutan penting: sumber TERAKHIR menang.
$app->registerConfigSource(new PhpFileConfigSource(__DIR__ . '/config/app.php'));
$app->registerConfigSource(new EnvConfigSource());           // prefix ZEF_
$app->registerSecretsProvider(new FileSecretsProvider('/run/secrets'));

$app->setConfigSchema(ConfigSchema::of(
    new ConfigKey('app.env', ConfigValueType::Enum, required: true, enumClass: AppEnv::class),
    new ConfigKey('database.host', ConfigValueType::String, required: true),
    new ConfigKey('database.port', ConfigValueType::Int, default: 5432, min: 1, max: 65535),
    new ConfigKey('database.password', ConfigValueType::String, required: true),
    new ConfigKey('http.debug', ConfigValueType::Bool, default: false),
));

$app->boot(); // melempar ConfigValidationException jika config tidak valid

$config = $app->config();                        // atau $container->get(Config::class)
$port   = $config->int('database.port');         // 5432
$env    = $config->enum('app.env', AppEnv::class);
```

Semua metode `register*`/`set*` harus dipanggil **sebelum** `boot()`. Setelah
boot, pemanggilan melempar `LogicException`.

---

## 3. Sumber konfigurasi

Setiap sumber mengimplementasikan `ConfigSourceInterface` (`name()` + `load()`).
Nama sumber harus unik.

### Aturan merge

- Sumber digabung sesuai urutan registrasi. Sumber **terakhir** menang.
- Array asosiatif digabung secara rekursif. List dan skalar diganti utuh.
- Kunci bertitik (`'database.port' => 5432`) adalah shorthand untuk struktur
  nested. Anda bisa mencampur kedua gaya di sumber mana pun.

### `PhpFileConfigSource`

Memuat file PHP yang me-`return` array. File yang hilang, error parse, atau
nilai return non-array membuat boot gagal dengan `InvalidConfigurationException`
yang menyebut nama file.

```php
<?php // config/app.php
return [
    'app' => ['env' => 'dev'],
    'database' => [
        'host'     => 'localhost',
        'password' => '%secret:db_password%',
    ],
    'cache.redis.ttl' => 300, // shorthand bertitik
];
```

### `EnvConfigSource`

Membaca variabel environment dengan prefix (default `ZEF_`). **Double
underscore (`__`) menjadi titik**, dan sisa nama di-lowercase:

| Variabel environment | Kunci config |
|---|---|
| `ZEF_DATABASE__HOST=db.internal` | `database.host` |
| `ZEF_HTTP__DEBUG=true` | `http.debug` |
| `ZEF_CACHE__REDIS__TTL=600` | `cache.redis.ttl` |

Nilai selalu berupa string mentah. Konversi tipe terjadi saat validasi skema
dan di accessor bertipe (lihat §5). Gunakan `new EnvConfigSource('MYAPP_')`
untuk prefix lain.

### `CompiledConfigSource`

Memuat file hasil `ConfigCompiler` (lihat §6) untuk boot produksi tanpa parsing
sumber, environment, maupun secrets.

---

## 4. Secrets

Tulis nilai sebagai referensi `%secret:nama%` (seluruh nilai, nama
`[A-Za-z0-9._-]`, maksimal 128 karakter). `ConfigLoader` meresolusi referensi
melalui secrets provider yang terdaftar saat load. Referensi yang tidak
ditemukan menjadi pelanggaran validasi.

### `FileSecretsProvider`

Direktori secret gaya Docker/Kubernetes: satu file per kunci, isi file di-trim.
Nama kunci divalidasi sehingga path traversal ditolak.

```php
$app->registerSecretsProvider(new FileSecretsProvider('/run/secrets'));
// %secret:db_password% → isi /run/secrets/db_password
```

### `ResilientSecretsProvider` (v2.21.1)

Decorator untuk provider yang bisa gagal sementara (misalnya vault jaringan):

- retry terbatas (`maxAttempts`, default 3) dengan backoff eksponensial
  `backoffSeconds * 2^(attempt-1)`, dibatasi 30 detik per sleep;
- hook `onRetry(key, attempt, cause)` yang tidak pernah menerima nilai secret;
- fallback ke nilai terakhir yang berhasil (`preferStaleOnFailure`, default
  `true`). Tanpa nilai tersimpan, exception asli diteruskan sehingga boot tetap
  fail-fast.

```php
use Zef\Framework\Config\ResilientSecretsProvider;

$app->registerSecretsProvider(new ResilientSecretsProvider(
    inner: $vaultProvider,
    maxAttempts: 4,
    backoffSeconds: 0.1,
    onRetry: fn (string $key, int $attempt, \Throwable $e) => $logger->warning(
        "secret {$key} retry {$attempt}: {$e->getMessage()}"
    ),
    metrics: $container->get(ConfigMetricsInterface::class), // opsional, v2.23.0
));
```

#### Metrics (v2.23.0)

Parameter opsional `metrics` menerima `ConfigMetricsInterface`. Kernel
mem-binding `ConfigMetricsInterface::class` ke `MeterConfigMetrics` (di atas
`MeterInterface`), yang menghasilkan counter:

| Metric | Label | Kapan |
|---|---|---|
| `zef.config.secrets.retries.total` | `provider`, `key` | Sebelum setiap retry |
| `zef.config.secrets.success.total` | `provider` | Provider berhasil menjawab |
| `zef.config.secrets.fallback.total` | `provider` | Nilai stale disajikan |

Label hanya berisi nama kunci dan nama provider (default: nama kelas pendek
provider dalam). Nilai secret tidak pernah menjadi label. Tanpa `metrics`,
`NullConfigMetrics` dipakai dan tidak ada yang dipancarkan.

---

## 5. Skema dan validasi

### `ConfigKey`

```php
new ConfigKey(
    key: 'database.port',          // grammar: segmen [A-Za-z0-9_-] dipisah titik, maks 256 karakter
    type: ConfigValueType::Int,    // String | Int | Float | Bool | Enum | Array
    required: false,
    default: 5432,
    enumClass: null,               // wajib untuk ConfigValueType::Enum (backed enum)
    min: 1, max: 65535,            // hanya Int/Float
    pattern: null,                 // hanya String, regex dengan delimiter
    description: 'Port database',
);
```

`new ConfigSchema($keys, allowUnknownKeys: false)` mengaktifkan mode strict
yang menolak kunci yang tidak dideklarasikan. `ConfigSchema::of(...$keys)`
adalah shorthand dengan kunci tak dikenal diizinkan.

### Grammar nilai

| Tipe | Diterima |
|---|---|
| `Int` | `int` atau string numerik penuh `/^-?\d+$/` |
| `Float` | `int`/`float` atau `/^-?\d+(\.\d+)?$/` |
| `Bool` | `bool`, atau `true/1/on/yes` dan `false/0/off/no` (case-insensitive) |
| `String` | hanya string, tidak pernah dikonversi |
| `Enum` | nilai backing dari `enumClass` |

String kosong hanya valid untuk kunci `String`. Sejak v2.21.1, pesan penolakan
menyertakan hint: hapus variabel environment kosong atau isi nilai konkret.

### Fail-fast yang mengumpulkan semua pelanggaran

`boot()` memanggil `config()` sebelum modul apa pun didaftarkan. Validator
mengumpulkan **semua** pelanggaran (secret hilang, kunci wajib kosong, tipe
salah, di luar min/max, kunci tak dikenal di mode strict) dalam urutan
deterministik, lalu melempar satu `ConfigValidationException`. Exception ini
mewarisi `InvalidConfigurationException`, sehingga operator melihat seluruh
masalah dalam satu kali boot. Default dari skema diterapkan hanya jika tidak
ada pelanggaran.

### Accessor bertipe

| Metode | Hasil |
|---|---|
| `string()`, `int()`, `float()`, `bool()`, `array()` | Nilai terkonversi sesuai grammar |
| `enum($key, Enum::class)` | Instance backed enum |
| `get($key, $default = null)` | Nilai mentah, tanpa konversi |
| `has($key)`, `all()`, `keys()` | Keberadaan kunci, seluruh tree, daftar path leaf |

Accessor bertipe sengaja **tidak** menerima default. Letakkan default di
`ConfigKey::$default`. Kunci yang tidak ada atau bertipe salah melempar
`InvalidConfigurationException` (misalnya `Configuration key 'x' is not
configured.`).

---

## 6. Produksi: config terkompilasi

`ConfigCompiler` menulis bag yang sudah tervalidasi ke file PHP murni. Boot
produksi lalu hanya memuat file itu.

```php
use Zef\Framework\Config\CompiledConfigSource;
use Zef\Framework\Config\ConfigCompiler;

// Saat build/deploy:
(new ConfigCompiler())->export($app->config(), 'var/cache/config.php');

// Saat boot produksi:
$app->registerConfigSource(new CompiledConfigSource('var/cache/config.php'));
```

Jaminan keamanan (v2.21.1):

- file ditulis atomik (tmp + rename) dengan mode **`0600`**, diterapkan sebelum
  rename sehingga tidak ada jendela world-readable. Ubah lewat
  `new ConfigCompiler(fileMode: 0o640)` jika perlu;
- header file memuat versi framework dan peringatan *"Contains resolved
  secrets — keep out of version control, chmod 600"*;
- `var/cache/config.php` sudah tercantum di `.gitignore`.

> **Peringatan:** file terkompilasi berisi secret yang sudah diresolusi. Jangan
> commit file ini dan jangan longgarkan izinnya.

---

## 7. Kueri pola (v2.21.1)

Selain lookup eksak, `Config` menyediakan kueri di atas indeks radix yang
dibangun sekali saat konstruksi:

```php
// '*' mencocokkan tepat SATU segmen; hasil terurut path => nilai
$config->query('database.connections.*.host');
// ['database.connections.mysql.host' => '...', 'database.connections.pgsql.host' => '...']

// Semua leaf di bawah prefix literal, sebagai path relatif ('' = seluruh tree)
$config->subtree('database.connections.mysql');
// ['host' => '...', 'port' => 3306]

// Resolusi template: kunci tersimpan 'services.*.timeout' menjawab kunci konkret
$config->longestMatch('services.paypal.timeout'); // null jika tidak ada kandidat
```

Pada `longestMatch()`, kandidat paling spesifik (paling sedikit `*`) menang.
Seri dipecahkan secara leksikografis.

### `PatternQueryCache` (v2.23.0)

Memo in-process untuk `query()` yang diulang dengan pola yang sama (misalnya
per request di worker RoadRunner). Cache terikat ke satu instance `Config`
sehingga tidak bisa stale. Hasil dikembalikan sebagai salinan.

```php
use Zef\Framework\Config\PatternQueryCache;

$queries = new PatternQueryCache($config);
$hosts   = $queries->query('database.connections.*.host');
$queries->clear();   // untuk worker long-lived
$queries->stats();   // diagnostik hit/miss
```

`subtree()` dan `longestMatch()` sengaja tidak di-memo.

### `RadixTreeCache` (v2.23.0)

Cache disk untuk indeks radix, agar boot melewati pembangunan ulang indeks:

```php
use Zef\Framework\Config\RadixTreeCache;

$cache = new RadixTreeCache();                                   // mode file default 0600
$cache->store($values, 'var/cache/config-index.cache');          // saat compile
$config = $cache->hydrate($values, 'var/cache/config-index.cache'); // saat boot, tidak pernah menulis
```

Cache otomatis diabaikan dan indeks dibangun ulang jika versi framework atau
fingerprint SHA-256 nilai berubah, atau file hilang/korup. Boot tidak pernah
gagal karena cache. File ini juga berisi nilai secret yang sudah diresolusi,
sehingga ditulis dengan `0600` seperti config terkompilasi. Jauhkan file cache
ini dari version control.

---

## 8. Versi skema dan migrasi (v2.23.0)

`ConfigSchema` punya field `version` (default `ConfigSchema::CURRENT_VERSION`
= 1). Naikkan versi hanya untuk perubahan yang benar-benar breaking, lalu
daftarkan langkah migrasi dengan `ConfigMigrator`:

```php
use Zef\Framework\Config\ConfigMigrator;

$migrator = new ConfigMigrator();
$migrator->to(2, function (array $values): array {
    // v1 → v2: 'db' diganti nama menjadi 'database'
    $values['database'] = $values['db'] ?? [];
    unset($values['db']);
    return $values;
});

$app->setConfigSchema(new ConfigSchema($keys, version: 2));
$app->setConfigMigrator($migrator, sourceSchemaVersion: 1);
```

Aturan:

- migrasi berjalan pada tree mentah hasil merge, **sebelum** resolusi secrets,
  validasi, dan default. Langkah melakukan rename/restrukturisasi, bukan
  resolusi `%secret:...%`;
- hanya naik versi. Downgrade ditolak dengan `InvalidConfigurationException`;
- setiap hop versi wajib punya langkah terdaftar. Hop tanpa langkah gagal fast;
- beberapa langkah untuk versi yang sama berjalan sesuai urutan registrasi;
- migrator wajib disertai skema. `sourceSchemaVersion` default ke
  `ConfigSchema::CURRENT_VERSION` dan harus positif.

---

## Lihat juga

- [`CLI.md`](CLI.md) — `config:show` untuk config modul
- `CHANGELOG-v2.21.0.md`, `CHANGELOG-v2.21.1.md`, `CHANGELOG-v2.23.0.md`
