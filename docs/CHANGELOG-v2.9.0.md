# ZEF Framework — Changelog v2.9.0 (Advanced Autowiring Engine)

> Ekstensi Container v2.7.0 dengan **Autowiring Engine** penuh: resolusi via PHP 8
> Attributes (`#[Inject]`, `#[Value]`, `#[Target]`), interface binding, koleksi
> variadic, integrasi `DependencyGraphValidator`, dan kompilasi AOT (ahead-of-time)
> menjadi Closure murni tanpa Reflection pada runtime.
>
> Seluruh perubahan bersifat **aditif**: `Container`, `ServiceDefinition`,
> `DependencyGraphValidator`, `ContainerCompiler`, `ArchitecturePolicy`, dan semantik
> `frozen` **tidak diubah sama sekali**. Konstanta `ZefVersion::VERSION` tetap `2.7.0`
> demi kompatibilitas wire (mengikuti keputusan rilis v2.8.0).

## Klaim Fitur

### PHP 8 Attributes & Parameter Resolution (Domain — `Zef\Framework\Autowiring`)
- **`#[Inject('service.id')]` / `#[Inject(SecretKey::class)]`** (parameter) — resolusi
  eksplisit ke service ID atau FQCN; bila ID berupa kelas konkret yang belum
  terdaftar, kelas tersebut di-autowire secara rekursif.
- **`#[Value('config.key')]`** (parameter) — resolusi parameter scalar/primitive
  (`string`, `int`, `float`, `bool`, `array`, `iterable`) dari nilai konfigurasi yang
  diberikan ke engine; nilai direpresentasikan sebagai *synthetic value service*
  `@value:<key>` sehingga tetap tampil dalam graf dependensi dan tervalidasi penuh.
- **Fallback default constructor** — parameter scalar tanpa `#[Value]` memakai nilai
  default (atau `null` untuk nullable) yang di-bake sebagai literal pada kode hasil
  generasi; posisi argumen tetap benar meskipun parameter di tengah tidak terikat.
- **`#[Target(ConcreteClass::class)]`** (parameter) — binding interface/abstract ke
  konkret tertentu tanpa mendaftarkan alias global.

### Interface & Variadic Autowiring
- **Interface binding berlapis** — urutan resolusi deterministik:
  `#[Inject]` → `#[Target]` → alias terdaftar → service ID = FQCN → autowire konkret
  (bila instantiable) → default/nullable → error konfigurasi yang jelas.
- **Variadic `HandlerInterface ...$handlers`** — engine mengumpulkan SELURUH service
  yang meng-implementasikan tipe tersebut dari registry (service ber-ID kelas,
  service hasil autowiring, dan factory closure dengan return type terdeklarasi),
  lalu menyuntikkannya sebagai array argumen posisional.

### Integrasi Graph Validation & Zero-Reflection Runtime
- Autowiring **bukan** pemanggilan `ReflectionClass` di `get()` — refleksi hanya
  berjalan pada fase *compile pass* (sebelum `validateAndFreeze()`).
- Setiap kelas yang diproses menghasilkan `ServiceDefinition` dengan **daftar
  `$dependencies` lengkap** (termasuk rantai dependensi transitif lewat definisi
  dependennya masing-masing), sehingga `validateAndFreeze()` yang asli tetap menjadi
  gerbang tunggal untuk: deteksi siklus (`ServiceCircularDependencyException`),
  batas referensi antar-modul (`ModuleDependencyViolationException`), dan closure
  lifetime singleton (`InvalidConfigurationException`).
- Siklus antar-kelas terdeteksi lebih awal pada saat compile pass (pesan menyertakan
  rantai kelas), tanpa menggantikan validasi graf bawaan.

### AOT Caching / Compilation Readiness
- `AutowireAotCompiler` menghasilkan **kode PHP murni** per service berupa
  `static fn ($ctx, $d0, ...) => new \FQCN(...)` — tanpa `Reflection*` di dalamnya.
- **Export** ke berkas PHP (`return ['id' => ['factory' => <closure>, ...]];`) untuk
  cold-start proses berikutnya: `AutowireAotCompiler::loadDefinitions()` +
  `bootContainer()` membangun container dari berkas tanpa menjalankan refleksi
  sama sekali.
- Literal yang tidak dapat diekspor (object/resource) ditolak pada waktu compile
  dengan `InvalidConfigurationException` — kegagalan cepat, tidak pernah runtime.
- Nilai konfigurasi `null` untuk `#[Value]` ditolak saat compile (resolver tidak pernah
  mengembalikan instance null; gunakan default parameter sebagai gantinya).

## Alur Penggunaan

```php
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;
use Zef\Framework\Container\Autowiring\AutowireAotCompiler;

$container = new Container();

// 1. Compile pass — refleksi hanya di sini, sebelum freeze.
$result = (new AutowireCompilerPass(
    configValues: ['db.host' => 'localhost', 'db.port' => 5432],
    module: 'billing',
))->process($container, [PaymentService::class]);

// 2. AOT export — opsional, untuk cold-start proses berikutnya.
AutowireAotCompiler::export($result, 'var/cache/zef-aot-billing.php');

// 3. Gerbang validasi asli — graf dependensi kini lengkap.
$container->validateAndFreeze();

// 4. Runtime — factory murni berupa closure hasil generasi; tanpa refleksi.
$payments = $container->get(PaymentService::class);

// Cold start (proses berikutnya, tanpa compile pass):
$cold = new Container();
AutowireAotCompiler::bootContainer($cold, 'var/cache/zef-aot-billing.php');
$cold->validateAndFreeze();
```

## Kelas Baru
| Layer | Kelas | Peran |
|---|---|---|
| Domain | `Autowiring\Inject`, `Autowiring\Value`, `Autowiring\Target` | PHP 8 attributes |
| Domain | `Autowiring\AutowireClassSpec`, `Autowiring\AutowireParameterSpec` | hasil ekstraksi refleksi (data murni) |
| Domain | `Autowiring\AutowireMetadata`, `Autowiring\AutowireResult` | rencana argument + dependensi per service |
| Application | `Autowiring\ReflectionMetadataExtractor` | refleksi → spec (hanya fase compile) |
| Application | `Autowiring\AutowireCompilerPass` | resolusi binding → registrasi `ServiceDefinition` |
| Application | `Autowiring\AutowireAotCompiler` | code generation + export/load berkas AOT |

## Verifikasi Rilis

| Pemeriksaan | Hasil |
|---|---|
| `php -l` seluruh file (`scripts/lint.php`) | **299 file, 0 gagal** |
| Suite baru `v2.9.0 autowiring suite` (`--self-test=v290`) | **59/59 PASSED** (11 sub-suite) |
| Baseline lengkap (`bin/zef --self-test`) | **307 PASSED / 0 FAILED** (145 monolith + 103 v2.8.0 + 59 v2.9.0) |
| HTTP compare vs monolith (status + body) | **11/11 byte-identik** (permukaan demo tak tersentuh) |
| Bukti zero-reflection pada kode generasi | setiap factory code & berkas AOT tervalidasi bebas string `Reflection` (assertion suite) |
| Cold-start AOT dari berkas tanpa refleksi | container baru dibangun via `bootContainer()` murni `include` + `registerDefinition` — extractor tidak pernah dijalankan |
| CI execution (4 langkah workflow) | lint 0 gagal · self-test 307/307 · v290 59/59 · composer.json OK |

## Catatan Migrasi

Tidak ada. Engine bersifat opt-in: container perilaku lama tidak berubah sampai
`AutowireCompilerPass::process()` dipanggil secara eksplisit sebelum
`validateAndFreeze()`. Panggilan `process()` pada container yang sudah frozen
melempar `LogicException('Container is frozen.')` mengikuti disiplin yang sama
dengan `register()`/`alias()`.
