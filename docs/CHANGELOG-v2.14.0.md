# ZEF Framework — CHANGELOG v2.14.0

**Tema rilis:** Mutation deep-dive — ronde pertama perjalanan MSI 59.6% → 85/90.
Empat file test deep-dive baru (61 test / ~9.800 assertion) membunuh +308 mutan
tambahan, menaikkan coverage statement ke 91.11%, memperbaiki satu bug produksi
nyata di AuthenticationMiddleware, dan menaikkan gerbang mutasi ke 58/62.

## Ringkasan

| Area | v2.13.1 | v2.14.0 |
| --- | --- | --- |
| Mutation (global, 12 chunk terukur) | MSI 59.6% / covered 66.3% (5.246 killed dari 8.907) | **MSI 61.8% / covered 67.6% (5.504 killed)** |
| Cluster terburuk Adapters/Runtime+Security | MSI 37% / covered 42% | **MSI 53% / covered 57%** |
| Cluster Application/CQRS..Security (app-rest) | MSI 58% / covered 64% | **MSI 72% / covered 76%** |
| Cluster Infrastructure | MSI 54% / covered 66% | **MSI 64% / covered 69%** |
| Coverage statement (gate 90%) | 90.04% (5975/6636) | **91.11% (6028/6616)** |
| PHPUnit | 408 test / 1248 assertion | **469 test / 11485 assertion** (+5 skip kondisional ekstensi) |
| Mutation gate (`composer mutation`) | 55/60 | **58/62** |
| Versi konstanta | 2.13.0 | **2.14.0** |

## Apa yang berubah

### 1. Empat file test deep-dive baru (tests/Unit/)

- `MutationDeepRestTest` — matriks negatif untuk CsrfTokenManager (bentuk token
  default 43-char, batas secret/tokenBytes, tabel isValid), InMemoryRateLimiter
  (boundary maxKeys=1, kapasitas default tepat 10.000, sweep expired bucket),
  InMemoryLockStore (jam injektif: perpanjangan lease, kedaluwarsa, batas
  TTL 1..86400), JsonMessageSerializer (matriks deserialisasi + payload tak
  JSON-safe), CommandBus/CqrsBusTrait (urutan middleware luar-dalam, resolusi
  handler exact/interface/ambigu, replay idempoten tanpa re-fire event,
  hash kunci idempotensi), EventDispatcher (prioritas default 0 teramati via
  registrations(), dengar interface, tolak kelas tak dikenal).
- `MutationDeepInfraTest` — ApcuRateLimiter (matematika keputusan + 429,
  boundary; di-skip anggun bila APCu tak ada), AesGcmEncryptor (format kunci
  raw/hex/base64, tolak ukur salah, tolak payload tamper/kunci asing/versi
  salah), RotatingKeyRing (batas 1..16 kunci, indeks aktif, probe semua kunci
  saat decrypt, pesan galat ring), Env (clamp int, strict mode, bool/string/
  csv edge), PrometheusRenderer (ekspos format 0.0.4: TYPE, label escape,
  sanitasi nama, +Inf/NaN/-Inf, batas MAX_SERIES 4096).
- `MutationDeepRuntimeTest` — RoadRunnerRuntime (single-use, re-entrancy
  teramati via worker error sink, maxJobs stop + delegasi worker.stop(),
  default maxJobs tak terbatas, exit 2 untuk pelanggaran memory limit,
  exit 1 + reportWorkerFailure untuk pipe/handler rusak, validasi env
  ZEF_RUNTIME_CONTROL_PLANE case/trim), AuthenticationMiddleware (401
  anonim-unsafe, ekstraksi Bearer dengan batas tepat MAX_ID_BYTES, 403 untuk
  konteks terautentikasi, truncation operation/resource/replayId, atribut
  principal), TinkerSession (skip komentar/blank, exit/quit, persistensi
  state, render error, truncation 240 byte + ellipsis).
- `MutationDeepSecurityTest` — SecurityRuntimeMiddleware (validasi/regenerasi
  X-Request-ID, header rate-limit sukses + 429, fail-closed 503 saat store
  jatuh, origin allowlist, CSRF issue/enforce/kembali-terbit cookie basi,
  parsing cookie dengan spasi).

### 2. Bug produksi ditemukan pipeline

- **AuthenticationMiddleware::extractCredential()** menerima token Bearer
  hingga 512 byte, padahal `CredentialHandle::MAX_ID_BYTES` = 128. Token
  129-512 byte lolos guard middleware lalu meledak sebagai uncaught
  InvalidArgumentException di dalam CredentialHandle (500 pada input
  attacker). Diperbaiki: guard kini memakai konstanta domain
  (`strlen($token) > CredentialHandle::MAX_ID_BYTES`) sehingga token
  kepanjangan ditolak rapi sebagai 401. Ditemukan justru oleh test mutasi
  boundary.

### 3. Lingkungan QA (lokal, tidak mengubah kontrak CI)

- APCu + apc.enable_cli=1 diaktifkan pada PHP lokal via file ini sandbox —
  mengubah ~40 mutan ApcuRateLimiter dari "not covered" menjadi terevaluasi,
  plus trade-off terdokumentasi: 5 test guard "ekstensi harus absen" kini
  di-skip secara kondisional (semantik tetap benar).
- Skrip `scripts/infection_chunk.sh` (repo scripts/, di luar vendor) dipakai
  untuk baseline per-chunk karena limitasi sandbox ~10 menit/proses.

### 4. Gerbang mutasi dinaikkan berbasis baseline terukur

- `composer mutation` / `mutation:ci`: `--min-msi=58 --min-covered-msi=62`
  (dari 55/60) — margin ~4 poin di bawah baseline terukur 61.8/67.6.
- Roadmap tetap: deep-dive lanjutan (Domain 721 escape, Adapters/Http 399,
  Router+Kernel 321) menuju 85/90 pada rilis berikutnya.

## Terverifikasi (lokal, PHP 8.4.24 + PCOV + APCu)

| Tool | Hasil |
| --- | --- |
| `composer validate` | valid |
| `composer lint` | 349 file / 0 gagal |
| `composer test` | 469 test / 11485 assertion OK (+5 skip kondisional) |
| `composer stan` (level max + strict-rules) | 0 error |
| `composer deptrac` (+ `--fail-on-uncovered`) | 0 violations / 0 uncovered |
| `composer format:check` + `composer phpcs` | 0 / 0 |
| `composer rector:check` | 0 diff |
| `composer coverage:gate` | 91.11% ≥ 90% — PASSED |
| `composer mutation` (12 chunk + agregasi) | MSI 61.8% / covered 67.6% ≥ gate 58/62 — PASSED |
| `composer bench` | ~0.62 µs singleton (tidak berubah) |
