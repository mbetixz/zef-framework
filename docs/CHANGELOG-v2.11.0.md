# ZEF Framework — Changelog v2.11.0 "RadixTree Namespace Container"

> Status: **IMPLEMENTED** (additive release; konstanta wire
> `ZefVersion::VERSION` tetap `2.7.0` demi kompatibilitas monolith — pola yang sama
> dengan v2.8.0/v2.9.0/v2.10.0).
>
> Rilis ini menerapkan **arsitektur hybrid container** yang diminta user: flat hashmap
> O(1) tetap menjadi jalur resolusi utama, sementara **Radix Tree berbasis segmen
> namespace** menjadi struktur sekunder pre-built untuk namespace scoping, boundary
> enforcement tanpa tagging manual, batch fetching, dan fallback resolution.
> Pipeline wajib: *changelog claim → implementation → feature test → baseline
> regression → integration/behavior test → CI execution → evidence*.

---

## 1. Desain: mengapa hybrid, bukan pengganti

- Jalur cepat (fast path) TIDAK berubah: `Container::get()` → `CompiledContainerPlan::canonical()`
  tetap O(1) hashmap lookup; nol traversal saat ID terdaftar eksplisit.
- Radix Tree dibangun **sekali** pada `validateAndFreeze()` (post graph-validation,
  sehingga semua ID sudah kanonik dan terbukti ada) dari **service yang terdaftar**
  (definitions + aliases, bukan seluruh classmap autoloader — container hanya
  mengindeks service, bukan kelas), lalu **disegel (sealed)**: read-only setelah freeze.
- Kunci tree = segmen namespace (pemisah `\`), dengan **path compression** bergaya
  radix (cabang tanpa percabangan/service digabung menjadi label majemuk,
  mis. `Framework\Container`) — memori lebih hemat dan traversal O(K), K = jumlah segmen.

## 2. Komponen baru

### 2.1 `Zef\Framework\Container\NamespaceRadixTree` (Domain)
- Struktur data murni (nol dependensi framework): `insert()`, `annotate()`,
  `seal()` (kompresi + penguncian), `exactMatch()`, `idsUnderPrefix()`,
  `scopeOf()`, `stats()`, `exportArray()` / `fromArray()` (AOT cache — payload
  array murni, bebas Closure/Reflection).
- Semantik segmen: pencocokan **hanya pada batas segmen** — prefix `App\Domain\Use`
  TIDAK cocok dengan `App\Domain\Users\...` (mencegah kebocoran false-positive).
- `idsUnderPrefix()` mengembalikan daftar terurut deterministik; traversal subtree
  tanpa memindai seluruh registry.
- Immutable setelah `seal()`: `insert()`/`annotate()` melempar `LogicException`.

### 2.2 `Zef\Framework\Policy\NamespaceScopePolicy` (Domain)
- VO immutable: `scopes` (prefix namespace => `public`|`internal`|`module`) +
  `maxCrossScopeRefs` (anggaran referensi lintas-cakupan untuk cakupan jenis `module`).
- Semantik: `public` = default (bebas); `internal` = hanya consumer **di dalam subtree
  yang sama** boleh bergantung padanya (pelanggaran = hard deny);
  `module` = consumer di luar subtree diizinkan sampai N target berbeda per pasangan
  (mencerminkan semantik anggaran `DependencyGraphValidator`).

### 2.3 `Zef\Framework\Container\RadixTreeCompilerPass` (Application)
- Dijalankan di `validateAndFreeze()` **setelah** `ContainerCompiler::compile()`
  (graph + module validation tetap berjalan lebih dulu dan tidak diubah).
- Membangun tree dari plan (definitions + nama alias; ID sintetis `@inner:*`,
  `@contextual:*`, `@value:*` dikecualikan), menganotasi cakupan dari policy,
  lalu menegakkan policy: pelanggaran `internal`/`module` melempar
  `ModuleDependencyViolationException` dengan pesan namespace yang jelas.
- Konsumen (consumer) sintetis (mesin dekorasi/kontekstual) dikecualikan dari pemeriksaan.

## 3. API Container baru (additive)

- `Container::configureNamespacePolicy(NamespaceScopePolicy $policy): void` — hanya pra-beku (pre-freeze).
- `Container::getByPrefix(string $prefix): array<string,mixed>` — resolusi massal (batch) seluruh
  service di bawah prefix (terurut by-ID; mis. mengumpulkan seluruh pipeline
  `App\Middleware\` dalam satu panggilan). Wajib pasca-beku (post-freeze) (tree belum ada sebelum freeze).
- `Container::getIdsByPrefix(string $prefix): list<string>` — hanya ID, tanpa instansiasi.
- `Container::registerNamespaceFallback(string $prefix, callable $factory, string $lifetime): void`
  — resolusi cadangan (fallback) tingkat namespace untuk ID yang BELUM terdaftar:
  `get('App\Domain\Unmapped\Anything')` → pabrik (factory) fallback subtree terdekat
  (prefix paling panjang menang). Aman secara PSR-11: `has()` ikut menghormati cakupan
  fallback; fallback **tidak pernah membayangi (shadow)** service terdaftar; fallback
  **tidak pernah** diterapkan pada edge dependency graph (dep harus eksplisit — graph
  validator tidak tersentuh). Lifetime `SINGLETON` (di-cache per-ID) atau `TRANSIENT`;
  `REQUEST` ditolak. Budget 64 fallback.
- `Container::namespaceTree()` / `Container::namespaceStats()` — inspeksi + bukti kompresi.
- `Container::reset(true)` ikut membersihkan singleton fallback.

## 4. Invariant yang dijaga

- `ServiceDefinition`, `DependencyGraphValidator`, `ContainerCompiler`,
  `ContainerResolver`, `ArchitecturePolicy`: **nol perubahan perilaku**; semua
  integrasi bersifat aditif (hook post-compile + guard fallback yang berbiaya nol
  saat fitur tidak dipakai).
- Jalur cepat (Fast path) O(1) untuk FQCN terdaftar tetap identik (terverifikasi regresi HTTP).
- Nol refleksi pada runtime: tree murni string/array; AOT `exportArray()` memungkinkan
  rekonstruksi tanpa proses build.

## 5. Tabel verifikasi (angka riil, pipeline 7 tahap)

| Tahap pipeline | Hasil |
|---|---|
| Changelog claim | File ini ditulis sebelum implementasi (git history + worklog) |
| Implementation | 3 kelas baru (2 Domain + 1 Application) + integrasi aditif Container + 1 suite test |
| Feature test | `--self-test=v211` — **87/87 PASSED** (8 sub-suite) |
| Baseline regression | lint **319 file / 0 gagal**; self-test penuh **501/501 PASSED** (145 baseline + 103 v280 + 59 v290 + 107 v210 tetap hijau); HTTP compare monolith(8081) vs refactor(8082) **11/11 MATCH byte-identik** |
| Integration/behavior | dalam suite v211: lifecycle freeze→getByPrefix→fallback, AOT `var_export` round-trip (payload bebas Closure/Reflection), immutability post-freeze, fallback tidak menyelamatkan graph dep, alias tetap dicek scope-nya; bukti dunia nyata: aplikasi demo boot penuh → tree sealed otomatis, **18 service, 71 segmen mentah → 23 edge (rasio kompresi 3.09)**, `getByPrefix('Zef\Framework\Container')` live |
| CI execution | 6 langkah `.github/workflows/ci.yml` dijalankan lokal: lint 319/0 · self-test 501/0 · v290 59/0 · v210 107/0 · v211 87/0 · composer.json OK |
| Evidence | tabel ini + README (501/501, 19 suite, classmap 353) + ARCHITECTURE bagian 11 + ZIP rebuild |
