# ZEF Framework — Changelog v2.10.0 "Enterprise Feature Pack"

> Status: **IMPLEMENTED** (additive release; konstanta wire `ZefVersion::VERSION` tetap `2.7.0`
> demi kompatibilitas monolith — pola yang sama dengan v2.8.0/v2.9.0).
>
> Rilis ini melengkapi 15 butir enterprise dari `docs/ROADMAP.md` dalam satu paket koheren,
> mengikuti pipeline wajib: *changelog claim → implementation → feature test → baseline
> regression → integration/behavior test → CI execution → evidence*.

---

## 1. Container System (Enterprise)

### 1.1 Contextual binding
- `Container::when(string $consumer): ContextualBindingBuilder` → `needs(string $dep)` → `give(string $target)`.
- Implementasi **registry rewrite pre-freeze**: dependency `$dep` milik definisi consumer
  disubstitusi menjadi ID sintetis `@contextual:<consumer>|<dep>` yang dialiaskan ke `$target`.
  Konsekuensinya *semua* jaminan graph tetap berlaku otomatis: `DependencyGraphValidator`
  memvalidasi keberadaan target, `assertSingletonClosure`, deteksi siklus, dan
  cross-module budget **tanpa satu baris pun perubahan pada resolver/validator**.
- Error jelas: consumer tidak terdaftar / consumer tidak mendeklarasikan dep tersebut /
  binding ganda untuk pasangan (consumer, dep) → `InvalidConfigurationException`.

### 1.2 Service decoration chain
- `Container::decorate(string $id, callable $decorator): void`;
  decorator signature `fn(ResolutionContext $ctx, object $inner): object`.
- Diterapkan pada `validateAndFreeze()` **sebelum** kompilasi via `applyDecorations()`:
  definisi asli dipindah ke `@inner:<id>:base`, setiap decorator menjadi definisi pembungkus
  dengan deps `[<prevChainId>]` — first-registered = outermost, dekorasi berlapis disarangkan.
- Lifetime/module/lazy/tags asli dipertahankan pada definisi pembungkus → singleton caching,
  request scope, warmSingletons, dan cross-module budget bekerja seperti definisi biasa.
- Alias yang menunjuk ke service terdekorasi otomatis mendekorasi juga (resolusi melewati id).

### 1.3 Service providers + deferred loading
- Port baru `Zef\Framework\Container\ServiceProviderInterface` (`register()` + `provides()`)
  dan marker `DeferrableProviderInterface` (pola Laravel) serta `BootableProviderInterface`
  (`boot()`, opsional).
- `Container::registerProvider()`: eager → `register()` langsung; deferred → disimpan dan
  baru dieksekusi saat `get($id)` pertama kali meminta salah satu `provides()` id-nya
  (composition-time deferred loading, sebelum freeze).
- `Container::bootProviders()`: memanggil `boot()` pada provider yang sudah ter-register,
  sekali saja. Budget 64 provider (`OverflowException`).

### 1.4 Container events
- `Container::onResolving(callable)` — `fn(string $id, array $deps): void`, dipanggil
  sebelum instansiasi (cache hit tidak memicu event).
- `Container::onResolved(callable)` — `fn(string $id, mixed $instance): mixed`; nilai balik
  non-null menggantikan instance sebelum dicaching (decorator-like hook runtime).
- Listener disimpan di registry; guard `hasX()` menjamin biaya nol saat tidak dipakai.
  Exception dari listener dibungkus `ServiceResolutionException` dengan konteks service ID.
- Budget 32 listener per jenis (`OverflowException`).

## 2. Router System (Enterprise)

### 2.1 Route groups dengan prefixes
- `Router::group(array $attributes, callable $routes): void` — attributes:
  `prefix` (string, wajib mulai `/`, tanpa `/` akhir), `name` (prefix nama rute),
  `middleware` (list service-ID), `priority` (default baru). Group bersarang didukung
  (stack di-append: prefix digabung, name digabung, middleware digabung).
- Setiap route kini membawa metadata `middleware: list<string>` (route tanpa group → `[]`)
  — key aditif, tidak mengubah matching.

### 2.2 Route middleware assignment (metadata)
- Middleware per-rute kini terekspor via `Router::getRoutes()` (key `middleware`) sehingga
  pipeline builder aplikasi dapat menggabungkan middleware rute secara deterministik;
  butir roadmap naik dari *parsial* ke *didukung penuh di level metadata*.

### 2.3 Route caching & compilation
- `Router::exportRoutes(): array` — struktur murni (routes + signatureIndex + nameIndex +
  sequence + custom constraints + fallback + budget), siap `var_export`.
- `Router::fromCompiledArray(array): Router` — memulihkan router yang sudah ter-sorted dan
  langsung `freeze()` (radix ter-compile) **tanpa** memvalidasi ulang rute satu per satu.
- `Zef\Framework\Router\RouteCache` — `export()`, `write(Router, $path)` (atomik: tmp file +
  rename, `<?php return ...;` murni), `load($path): Router` (include file hasil kompilasi).

### 2.4 Fallback routes & custom 404
- `Router::fallback(string $handlerService)` + `Router::matchOrFallback($method, $path)`:
  `RouteNotFoundException` ditangkap dan diganti entri `['fallback' => true, ...]`;
  `MethodNotAllowedException` (405) **tidak** tertutup fallback — semantik 405 tetap.
- `Router::hasFallback()`, dan fallback ikut ter-export/ter-load oleh RouteCache.

### 2.5 API versioning
- `Zef\Framework\Http\ApiVersionNegotiator` — negosiasi versi dari (urutan prioritas):
  prefix path `/v{n}` > header (default `X-Api-Version`) > query `?api_version=` > default.
- Hasil VO `ApiVersion {version, source}`; versi tidak didukung →
  `ApiVersionUnsupportedException` (menyertakan daftar versi didukung; header/query yang
  terlalu panjang diperlakukan unsupported, bukan error 500).
- `splitPathPrefix('/v2/users')` → `['2', '/users']` untuk integrasi router.

## 3. HTTP & API features

### 3.1 Sorting + Filtering spec (list endpoints)
- `Zef\Framework\Resource\SortSpec` — parse `?sort=-price,name` terhadap whitelist wajib;
  key `SortKey{field, desc}`; `applyTo()` sorting in-memory multi-key (sort stabil PHP 8);
  `toQuery()` round-trip. Lenient seperti `PageRequest`: field di luar whitelist diabaikan
  (endpoint listing tidak boleh 500), maksimum 8 key, field ≤ 64 byte.
- `Zef\Framework\Resource\FilterSpec` — bentuk nested `filter[status]=open` dan
  `filter[price_gte]=100`; operator `eq,neq,gt,gte,lt,lte,like,in` (`_in` memisah koma);
  VO `FilterCondition{field, op, value}`; `applyTo()` in-memory; whitelist wajib
  (anti-SQL-injection by design: hanya nama kolom whitelist yang bisa sampai ke caller),
  value ≤ 256 byte, maksimum 32 kondisi.

## 4. Security

### 4.1 Key rotation (AES-256-GCM)
- `Zef\Framework\Security\RotatingKeyRing implements EncryptionInterface` — memegang
  `list<string> keys` (validasi identik `AesGcmEncryptor`) + `activeIndex`.
- `encrypt()` memakai kunci aktif; `decrypt()` mencoba semua kunci berurutan (format
  `zefenc1` tidak membawa key-id, rotasi bekerja pada data lama); gagal semua →
  `RuntimeException` asli. `withActiveIndex()` immutable untuk rotasi runtime.
- Dokumentasi operasional: jaga ring tetap kecil (decrypt O(n) untuk payload asing).

## 5. Validation & input handling

### 5.1 Localized error messages
- `Zef\Framework\Validation\MessageCatalog` — immutable; `with(locale, rule, template)`;
  `templateFor()` dengan fallback chain `id_ID → id → *`; bawaan `defaultEnglish()` untuk
  seluruh rule v2.8.0 (required, type, min_length, max_length, min, max, email, uuid, in,
  pattern, custom).
- `ValidationTranslator::translate(ValidationResult, locale, fieldLabels)` — interpolasi
  `{{field}}`, `{{label}}`, `{{rule}}` (nilai dari developer, bukan user); rule/locale yang
  tidak dikenal mempertahankan pesan asli.

### 5.2 Form request objects
- `Zef\Framework\Http\FormRequest` — `fromArray(Validator, payload)` /
  `fromServerRequest(Validator, ServerRequest)` (query + parsedBody, parsedBody menang);
  `isValid()`, `validated()` (hanya field yang dideklarasikan), `errors()`, `result()`.
  Terintegrasi penuh dengan rules engine v2.8.0 (termasuk ReDoS-guarded pattern).

## 6. Developer experience

### 6.1 Tinker/REPL
- `Zef\Framework\Runtime\TinkerSession` — loop eval per-baris dengan state persist antar
  baris (`get_defined_vars` diff), output `var_export` ter-truncasi, error terkontain
  per-baris (`[error] ...`), stop kata kunci `exit`/`quit`. Murni in-process → unit-testable.
- `bin/zef tinker [-e <expr>] [--no-boot] [--force]` — boot aplikasi nyata (ekspos `$app`
  dan `$container`), menolak berjalan ketika `ZEF_ENV=production` tanpa `--force`.

## 7. Deployment & DevOps

### 7.1 Kubernetes manifests
- `deploy/k8s/deployment.yaml` — 2 replica, securityContext non-root/read-only rootfs/
  drop ALL capabilities, probes liveness `/health/live` + readiness `/health/ready`,
  resource requests/limits, env `ZEF_ENV=production`.
- `deploy/k8s/service.yaml` — ClusterIP + comments roll-out.

## 8. Dokumentasi

- `README.md` — headline v2.10.0, daftar fitur, contoh pakai (contextual binding,
  decorator, group, fallback, sort/filter, keyring, i18n, form request, tinker).
- `docs/ARCHITECTURE.md` — bagian 10 "Rilis v2.10.0": peta kelas baru per layer, titik
  integrasi, invariant yang dijaga (frozen, graph, budget).
- `docs/ROADMAP.md` — checkbox 15 butir ditandai `[x]`.

---

## Verifikasi (evidence — diisi angka riil di akhir pipeline)

| Tahap | Hasil | Status |
|---|---|---|
| Syntax lint (`php -l`, seluruh file PHP) | 315 file / 0 gagal | ✅ |
| Feature test `--self-test=v210` | 107 assertion PASSED / 0 FAILED | ✅ |
| Baseline regression `--self-test` penuh | 414 PASSED / 0 FAILED (145 baseline + 103 v280 + 59 v290 tetap hijau + 107 v210) | ✅ |
| HTTP compare monolith vs refactor | 11/11 rute MATCH byte-identik | ✅ |
| CI 5 langkah dijalankan lokal | lint 315/0 · self-test 414/0 · v290 59/0 · v210 107/0 · composer.json OK | ✅ |
| Classmap statis | 342 kelas (+19), 23 shim eager tetap | ✅ |
| ZIP di-rebuild | zef-framework.zip (400 file) | ✅ |

### Bukti bug yang ditangkap oleh pipeline (feature test menemukan bug implementasi)

1. **Collision detection mati** — baris `$this->signatureIndex[$signature] = $pattern;` hilang
   saat refaktorisasi `add()` untuk group support; rute duplikat lolos. Ditangkap oleh
   assertion "duplicate route via same effective pattern still collides", dipulihkan.
2. **`splitPathPrefix()` kehilangan slash** — `/v2/users` → rest `users` (tanpa `/`).
   Ditangkap assertion splitPathPrefix, diperbaiki.
3. **`supportedVersions()` tipe campur** — `array_fill_keys(['1','2'])` mengubah key
   string numerik menjadi int sehingga `in_array('2', ..., true)` gagal; kini list
   token asli disimpan terpisah.
4. **`MessageCatalog` menolak wildcard `'*'`** — validasi ctor menolak kunci locale
   bawaan; kini `'*'` di-whitelist dan locale dinormalisasi lowercase di `with()`.
5. **Middleware group dobel** — akumulasi seluruh stack group menggandakan middleware
   induk pada rute bersarang; kini hanya innermost group yang dipakai (sudah memuat
   merge parent).

## Alur Penggunaan (ringkas)

```php
// 1) Contextual binding
$c->when(BillingService::class)->needs(PaymentGateway::class)->give('gateway.stripe');

// 2) Decoration
$c->decorate('mailer', fn($ctx, $inner) => new LoggingMailer($inner, $ctx->get('logger')));

// 3) Providers (deferred)
$c->registerProvider(new ReportServiceProvider()); // register() baru jalan saat get()

// 4) Events
$c->onResolved(fn($id, $instance) => $instance instanceof Warmed ? $instance->warmed() : null);

// 5) Router groups + fallback
$r->group(['prefix' => '/api/v2', 'name' => 'api.v2.', 'middleware' => ['auth.token']], function ($r) {
    $r->add('GET', '/users', 'user.handler.index', name: 'users'); // /api/v2/users, nama api.v2.users
});
$r->fallback('error.handler.notFound');
$hit = $r->matchOrFallback('GET', '/nope'); // ['handler' => 'error.handler.notFound', 'fallback' => true, ...]

// 6) Sort/filter
$sort = SortSpec::fromQuery($q, ['price', 'name']); // ?sort=-price
$rows = $sort->applyTo($rows);
$filter = FilterSpec::fromQuery($q, ['status', 'price']); // ?filter[status]=open&filter[price_gte]=100
$rows = $filter->applyTo($rows);

// 7) Key rotation
$crypto = new RotatingKeyRing([newKey, oldKey]); // encrypt=newKey, decrypt=apapun di ring

// 8) Validation i18n
$translated = (new ValidationTranslator(MessageCatalog::defaultEnglish()
    ->with('id', 'required', '{{label}} wajib diisi.')))
    ->translate($result, 'id', ['email' => 'Email']);

// 9) Form request
$form = FormRequest::fromServerRequest($validator, $request);
if ($form->isValid()) { save($form->validated()); }
```
