# ZEF Framework — CHANGELOG v2.21.1

**Review hardening Configuration System v2** — paket pemantapan yang menjawab
laporan review PR #56: kueri pola di atas pohon konfigurasi (radix index),
decorator secrets yang menyerap kegagalan sementara, pesan penolakan
string kosong yang menjelaskan solusinya, dan jaminan keamanan file config
terkompilasi.

## Ringkasan

- **Kueri pola radix (`query()` / `subtree()` / `longestMatch()`)** —
  `src/Domain/Config/ConfigRadixTree.php` + `ConfigRadixNode.php` (2 kelas
  baru): indeks segmen yang dibangun SEKALI di constructor `Config` dan
  immutable sesudahnya. Lookup eksak tetap di jalur hash-map
  (`DottedPaths`) — indeks hanya melayani kueri pola:
  - `query('database.connections.*.host')` — pola `*` mencocokkan SATU
    segmen tersimpan, hasil terurut `path => nilai` (leaf, list, subtree
    array, atau empty array);
  - `subtree('database.connections.mysql')` — semua leaf di bawah prefix
    literal dalam bentuk path relatif; prefix kosong = seluruh pohon;
  - `longestMatch('services.paypal.timeout')` — resolusi template: kunci
    tersimpan `services.*.timeout` menjawab kunci konkret; kandidat
    paling spesifik menang (paling sedikit `*`), seri dipecahkan
    leksikografis; tanpa kandidat → `null`.
- **ResilientSecretsProvider** (`src/Infrastructure/Config/`, 1 kelas baru) —
  decorator `SecretsProviderInterface` yang menyerap kegagalan sementara:
  retry terbatas (`maxAttempts`, default 3) dengan backoff eksponensial
  (`backoffSeconds * 2^(attempt-1)`, cap 30 detik), hook `onRetry` yang HANYA
  menerima (kunci, attempt, cause — tanpa material rahasia), dan fallback
  nilai terakhir yang diketahui baik (`preferStaleOnFailure`, default aktif).
  Tanpa nilai tersimpan, exception asli diteruskan — fail-fast tetap terjaga.
  Semantik provider dalam (grammar kunci, null = tidak dikenal) didelegasikan
  utuh; null tidak pernah di-cache.
- **Penolakan string kosong dengan hint** — `ConfigValueType::rejectionHint()`
  menghasilkan panduan deterministik ketika nilai `''` ditolak kunci non-
  string: *"empty strings only satisfy string keys; remove the empty
  environment variable or set a concrete value"*. Hint ini menempel di pesan
  pelanggaran boot (`ConfigSchemaValidator`) maupun pengecualian runtime
  (`Config::typed()`). Perilaku inti TIDAK berubah — `''` tetap valid hanya
  untuk kunci string — yang baru adalah diagnostiknya. Kontrak juga kini
  dinyatakan eksplisit di docblock: default tinggal di skema
  (`ConfigKey::$default`), accessor sengaja tanpa default runtime.
- **Keamanan config terkompilasi** — `ConfigCompiler` kini:
  - menulis dengan mode `0600` (parameter constructor `$fileMode`, tervalidasi
    mask 0–0777), diterapkan pada file sementara SEBELUM rename sehingga tidak
    ada jendela world-readable;
  - membawa header versi dari `ZefVersion::VERSION` plus peringatan keamanan
    eksplisit: *"Contains resolved secrets — keep out of version control,
    chmod 600"*;
  - target yang disarankan `var/cache/config.php` didaftarkan di `.gitignore`.
- `Config` tetap `final readonly class` — indeks dibangun di constructor dan
  tersimpan sebagai properti readonly; kebijakan immutability (77 kelas
  `readonly`) tidak turun. `ConfigCompiler` ikut naik status menjadi
  `readonly class` setelah memperoleh properti readonly tunggal.

## Non-Tujuan (ditunda, terdokumentasi)

- **Schema versioning / migrasi kunci** — keputusan sadar: penamaan kunci
  adalah kontrak deployment; mekanisme migrasi otomatis menunda deteksi
  kesalahan ke runtime tanpa menambah keamanan. Dievaluasi kembali bila ada
  kebutuhan nyata.
- **Circuit breaker secrets** — retry terbatas + stale fallback sudah
  mencakup kasus praktis; circuit breaker penuh menambah state tanpa kasus
  pemakaian yang jelas di lingkup ini.

## Tabel Verifikasi

| Butir | Bukti | Status |
|-------|-------|--------|
| Implementasi | 3 kelas baru (`ConfigRadixTree`, `ConfigRadixNode`, `ResilientSecretsProvider`) + perubahan `Config`, `ConfigValueType`, `ConfigSchemaValidator`, `EnvConfigSource`, `ConfigCompiler`, `.gitignore` | ✅ |
| PHPUnit penuh | 2236 tes / 19.247 asersi (5 skip kondisional) — termasuk 39 metode uji baru (radix 16, hardening 23) | ✅ |
| PHPStan level *max* + strict rules | 0 error | ✅ |
| PHPCS (Slevomat) / CS-Fixer / Rector | 0 pelanggaran / 0 diff / 0 perubahan | ✅ |
| Deptrac (hexagonal boundaries) | 0 error, 0 warning | ✅ |
| Lint | 571 file PHP, 0 gagal | ✅ |
| SAST Semgrep (src ERROR, src WARNING, tests+scripts WARNING) | 0 temuan di ketiganya | ✅ |
| Mutation gate zona Config v2 | 1104 mutan: **MSI 90,5% / covered 92,5%** (ratchet 85/90); escape tertriase (aritmetika offset-invariant, usleep tak terobservasi, filename tmp acak) | ✅ |
| Per-zone ratchet | `d-container-config` 89,46 → **91,41**; `infra-a` 92,09 → **92,67** (naik, tidak ada regresi); gate PASSED | ✅ |
| Versi & autoloader | `ZefVersion 2.21.1`; classmap 788 entri terverifikasi | ✅ |