# ZEF Framework — CHANGELOG v2.21.0

**Tema paket besar #5: Configuration System v2** — lapisan konfigurasi aplikasi
enterprise: multi-source loading (PHP file + environment overlay + secrets
provider), skema tervalidasi dengan fail-fast startup yang mengumpulkan SEMUA
pelanggaran, accessor bertipe ketat (`string()/int()/float()/bool()/array()/enum()`
termasuk enum PHP 8.4 native), serta export config terkompilasi untuk boot
produksi tanpa parsing.

## Ringkasan

- **Kelas baru dalam tiga layer hexagonal** (module config v2.7.0 —
  `ConfigAggregator`/`ConfigProviderInterface` — TIDAK diubah; sistem v2 adalah
  lapisan pengaturan aplikasi yang melengkapi definisi modul):
  - `src/Domain/Config/` (8): `ConfigSourceInterface` + `SecretsProviderInterface`
    (port), `ConfigValueType` (enum kanonis + grammar penerimaan/coercion satu
    sumber kebenaran), `ConfigKey` (VO skema: tipe/required/default/enum/min/max/
    pattern dengan validasi grammar), `ConfigViolation`, `ConfigSchema`
    (koleksi kunci immutable + mode strict unknown-key), `ConfigSchemaValidator`
    (validasi collect-all + penerapan default), `Config` (bag bertipe readonly),
    `ConfigValidationException` (mewarisi `InvalidConfigurationException`,
    membawa seluruh pelanggaran).
  - `src/Application/Config/` (2): `ConfigLoader` (orkestrasi merge deterministik
    → resolusi secret → validasi → default → bag), `ConfigCompiler` (export
    `var_export` atomik tmp+rename untuk boot produksi).
  - `src/Infrastructure/Config/` (4): `PhpFileConfigSource`,
    `EnvConfigSource` (prefix `ZEF_`, `__` → `.`, lowercase, nilai string mentah),
    `FileSecretsProvider` (direktori secret gaya Docker/K8s, anti path-traversal),
    `CompiledConfigSource`.
  - Kernel: `Application::registerConfigSource()` / `registerSecretsProvider()` /
    `setConfigSchema()` / `config()`; `boot()` membangun + memvalidasi config
    (fail-fast) dan mendaftarkan singleton `Config::class` ke container.
  - `InvalidConfigurationException` tidak lagi `final` (BC-safe) agar
    `ConfigValidationException` dapat mewarisinya.
- **Grammar nilai terdokumentasi & teruji**: int menerima `int` atau numeric-string
  penuh `/^-?\d+$/`; float menerima `int|float` atau `/^-?\d+(\.\d+)?$/`; bool
  menerima `bool` atau `'true'/'1'/'on'/'yes'` vs `'false'/'0'/'off'/'no'`
  (case-insensitive); string tidak pernah di-coerce; secret reference hanya
  full-value `%secret:[A-Za-z0-9._-]{1,128}%`.

## Fitur

### Multi-source + overlay deterministik
- Sumber didaftarkan berurutan; sumber TERAKHIR menang. Merge asosiatif rekursif
  (array assoc digabung dalam), list & skalar diganti utuh — deterministik dan
  bebas urutan kunci. Kunci bertitik (`'database.port' => 5432`) adalah shorthand
  path yang dinormalisasi menjadi pohon nested sebelum merge — kedua gaya bisa
  dicampur bebas di sumber mana pun.
- `EnvConfigSource` memetakan `ZEF_DATABASE__HOST` → `database.host`
  (double-underscore = pemisah segmen, sisanya lowercase), nilai tetap string
  mentah; konversi tipe terjadi di accessor dengan grammar eksplisit.

### Skema + fail-fast startup
- `ConfigSchema` mendeklarasikan kunci: tipe, required, default, backed-enum
  class, min/max (int/float), pattern regex (string, delimiter wajib, divalidasi
  saat kompilasi skema). `allowUnknownKeys=false` = mode strict yang menolak
  kunci tak dikenal.
- `ConfigSchemaValidator` mengumpulkan SEMUA pelanggaran (bukan fail di yang
  pertama) dalam urutan deterministik — `boot()` melempar
  `ConfigValidationException` yang menampilkan seluruh laporan sekaligus.

### Secrets
- Port `SecretsProviderInterface` + adapter `FileSecretsProvider` (satu file per
  kunci di dalam satu direktori; grammar kunci anti-traversal; isi di-trim).
- Nilai konfigurasi `%secret:name%` diresolusi saat load; referensi tak
  terdefinisi menjadi pelanggaran validasi.

### Produksi
- `ConfigCompiler::export()` menulis file PHP murni (var_export, atomik
  tmp+rename); `CompiledConfigSource` memuatnya — boot produksi tanpa parsing
  sumber/overlay/secrets.

## Tabel Verifikasi

| Butir | Bukti | Status |
|-------|-------|--------|
| Implementasi | 16 kelas baru: Domain/Config (10: port, enum grammar, VO skema, validator collect-all, bag, exception), Application/Config (2: loader, compiler), Infrastructure/Config (4: file, env, secrets, compiled) + integrasi kernel `Application` | ✅ |
| PHPUnit penuh | 2203 tes / 19.166 asersi (5 skip kondisional) — termasuk 94 tes baru Config v2 (domain 36, sources+loader 25, application 9, mutation sweep 24) | ✅ |
| PHPStan level *max* + strict rules | 0 error | ✅ |
| PHPCS (Slevomat) / CS-Fixer / Rector | 0 pelanggaran / 0 diff / 0 perubahan | ✅ |
| Deptrac (hexagonal boundaries) | 0 error, 0 warning, 1.843 edge allowed | ✅ |
| SAST Semgrep (3 pass blocking: `src` ERROR, `src` WARNING, `tests`+`scripts` WARNING) | 0 temuan, exit 0 di ketiganya; 1 marker + 3 marker teardown teregistrasi §7 (total 61, terukur ulang) | ✅ |
| Mutation gate zona Config v2 | 671 mutan: **MSI 94,9% / covered 95,6%** (ratchet 85/90); 29 escape = triase equivalent/assert-guard/unreachable terdokumentasi | ✅ |
| Self-test CLI (`bin/zef --self-test`) | 501/501 PASSED | ✅ |
| Versi & autoloader | `ZefVersion 2.21.0`; classmap 776 kelas terverifikasi | ✅ |
