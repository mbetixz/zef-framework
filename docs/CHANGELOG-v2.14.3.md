# ZEF Framework — CHANGELOG v2.14.3

**Tema: Edge-Case Matrix ronde 3 — fase 2b (Container core).** Lanjutan langsung
fase 0–1 (v2.14.2): katalog adversarial `docs/EDGE-CASE-MATRIX.md` dipakai sebagai
kurikulum, log mutan Infection sebagai bukti celah. Target fase 2b adalah tiga
kelas inti container yang berada di pusat gravitasi kerumitan framework:
`Application/Container/Container.php` (68 escape), `Autowiring/AutowireCompilerPass.php`
(75 escape), dan `ContainerResolver.php` (40 escape) — total 183 escape pada
baseline segar.

## Hasil terukur (filter 3 kelas, threads=2, Infection PCOV)

| Metrik | Sebelum | Sesudah | Δ |
|---|---:|---:|---:|
| MSI (3 kelas) | 67% | **92%** | +25 |
| Covered MSI | 76% | **94%** | +18 |
| Mutation coverage | 88% | **97%** | +9 |
| Escaped mutants | 110 | **27** | −83 |
| Not-covered mutants | 64 | **22** | −42 |
| Mutan tewas tambahan | — | **+103** | — |

Estimasi dampak global: +~1.2 poin MSI pada baseline 8.907 mutan. Gate mutasi
naik **66/71 → 68/73** (margin ~2 poin di bawah estimasi global baru).

## Test baru

`tests/Unit/EdgeMatrixContainerTest.php` — **62 test / 155 asersi**, semua
berasal dari skenario mutan yang lolos (bukan produksi mekanis). Dua batch:

**Batch 1 (48 test)** — dari 110 escape baseline:
- AutowireCompilerPass: siklus rantai di-trim ke siklus saja (`chain` exact
  `[A,B,C,A]`, bukan `[D,A,B,C,A]`); ledger `reusedIds` tercatat tepat sekali
  untuk registrasi pra-passis (semantik riil: kelas hasil generate masuk
  `generatedIds`, bukan `reusedIds`); klas tidak ada / interface / abstrak
  ditolak dengan pesan exact; union wajib vs optional; `#[Value]` null/missing/
  share-dedup; variadic scalar list + non-array; literal matrix (escaping
  string, `false`, `'0'`, nested array, elemen null, objek non-exportable).
- Container: `isDebug`; custom `InitializationGuard` dipakai nyata; cross-module
  budget 0 = validasi nonaktif (temuan perilaku!), 1 = violation pada edge kedua,
  clamp negatif; `warmSingletons` matrix (eager=1, lazy=0, transient=0,
  unshared=0); `reset()` default mempertahankan singleton vs `reset(true)`;
  contextual binding via-id exact `@contextual:consumer|dep` + resolusi nyata;
  dekorasi chain order `A(B(core))` + synthetic id `@inner:svc:1`; budget
  dekorasi 128 OK / 129 ditolak; budget registrasi saat dekorasi; provider
  64/65; eager boot sekali; deferred lazy→trigger→boot; dua provider satu id
  keduanya jalan; deferred post-freeze message exact; deferred via alias target;
  namespace fallback (REQUEST ditolak, lifetime tak dikenal, wrap gagal exact,
  null ditolak, prefix terpanjang menang, singleton cache vs transient,
  root prefix `\` catch-all); `namespaceStats()` null pra-freeze.
- ContainerResolver: depth clamp 0/−7→1, 255/257/256 exact; resolveRoot tanpa
  bind; scope helper round-trip + miss; state scope 2 layanan saling
  mempertahankan (bug-kelas `[] ?? ...`); transient & unshared singleton tak
  ter-cache; scope tertutup / luar scope; listener resolving/resolved gagal
  dibungkus exact; SRE dari factory rethrow apa adanya (tanpa double-wrap);
  wrap generik dengan canonical id.

**Batch 2 (14 test)** — dari 37 escape sisa yang tertriage masih killable:
- `process()` pada container frozen menolak bahkan list kosong.
- `#[Inject]` sukses mengembalikan sebelum resolusi tipe kelas (precedence).
- Variadic class-typed mengabaikan `#[Value]` (precedence antar strategi).
- `mixed` tanpa default → literal null; scalar wajib tanpa `#[Value]`/default
  → pesan exact; `#[Target]` abstrak → pesan exact.
- `@value` service di-share antar dua consumer tanpa registrasi ganda.
- Dedup dependency: dua param bertipe sama ke layanan TRANSIENT mendapat
  instance yang sama.
- `configurePolicies()` tanpa argumen tetap menonaktifkan validasi cross-module.
- Deferred trigger berulang: `continue` (bukan `break`) pada provider yang
  sudah terdaftar — provider kedua tetap jalan.
- Runtime cycle tanpa edge compile-time tertangkap oleh stack push/pop
  (`ct2b.r1 → r2 → r1`), termasuk format bungkusannya.
- Transient di dalam request scope tidak pernah menulis scope cache.
- Alias ghost dengan module melaporkan module di pesan not-found.

## Temuan perilaku (dokumentasi kontrak, tanpa perubahan src)

1. **Cross-module budget 0 berarti validasi NONAKTIF** (`$maxCrossModuleRefs > 0`
   gate di validator), bukan "nol toleransi". `configurePolicies()` default dan
   `configurePolicies(-5)` sama-sama menonaktifkan. Terkunci oleh test.
2. **Dekorasi mengganti definisi asli** — wrapper terluar memakai id asli
   (tanpa slot registry baru); hanya `@inner:*:base` dan wrapper dalam yang
   mengalokasikan slot. Konsekuensi: budget registrasi tepat tercapai dengan
   2 dekorator + 2 definisi.
3. **Enum default pada dependency kelas tak terikat ditolak** — `var_export`
   tidak bisa merender enum; pesan `is not exportable (EnumClass)` exact.
4. **Duplikat contextual binding tertangkap guard ketergantungan** (deps
   consumer sudah di-rewrite ke via id), bukan oleh ledger dedup — ledger
   berfungsi sebagai defence in depth.
5. **Runtime cycle dibungkus SRE** dengan canonical consumer terluar yang
   memicu deteksi; chain tetap terbaca di pesan.

## Inventaris ekuivalen-mutant fase 2b (jujur — tidak dipaksakan)

27 escape + 22 not-covered tersisa dianalisis satu per satu; pola yang
terbukti ekuivalen (diinventarisasi di `docs/EDGE-CASE-MATRIX.md` §6):
- **Guard redundan**: `(class_exists || interface_exists)` sebelum `is_a`
  (mutually exclusive → negasi tak mengubah hasil); `isset(lifetimeOf) &&
  hasFactory` (keduanya set/not-set bersamaan).
- **Pasangan write/read guard** pada `shared` flag (L114 + L178 resolver):
  mutasi salah satu saja tak terobservasi karena guard pasangannya menutup.
- **isset() tak peduli nilai** (`TrueValue` pada sentinel `registeredProviders`,
  `referenced`) dan `array_keys()` mengabaikan nilai.
- **Defensive dedup**: `array_unique`/`array_values` pada koleksi yang
  mustahil duplikat (registrar menolak id ganda; `continue` mencegah).
- **Masking antar-guard**: `max(-1, …)` dimasking gate `> 0`; budget check
  `>=`→`>` pada base-add dimasking check wrapper; duplicate contextual ledger
  dimasking guard deps-ter-rewrite.
- **Tidak terjangkau via API publik**: catch `Closure::fromCallable` untuk
  factory non-callable (typehint callable di registrar).

## Regresi 13 tools (semua hijau)

| Tool | Hasil |
|---|---|
| validate (composer.json) | valid |
| audit (abandoned policy) | PASSED |
| lint (`php -l` 361 file) | 0 error |
| self-test (bin/zef) | 501/501 (via bridge PHPUnit: hijau) |
| phpunit | **748 test / 12.463 assertion** (+5 skip kondisional) |
| phpstan (max + strict-rules) | 0 error |
| deptrac (fail-on-uncovered) | 0 violation |
| cs-fixer (PER-CS2.0 + @PHP84Migration + risky) | 0 pending |
| phpcs (Slevomat, PSR-1 + phpDoc) | 0 violation |
| rector (agresif) | 0 pending (2 apply: SelfTestBridgeTest instance-method + path direct) |
| bench (phpbench) | ±0.62 µs (stabil) |
| docs (doctum) | build OK |
| coverage gate | **92.05%** (≥ 90) PASSED |
| mutation gate | 68/73 — filter 3 kelas 92/94, margin global terjaga |

## Kompatibilitas

Perilaku runtime `src/**` **tidak berubah** — rilis ini murni test, gate, dan
dokumentasi. Naikkan patch karena gate mutasi berubah (kebijakan rilis: gate
adalah bagian kontrak publik CI).
