# ZEF Framework v2.14.8 — Edge-Case Matrix fase 6 (Domain inti)

Ronde 3 deep-dive menyerang zona terbesar yang tersisa: **`src/Domain` inti** —
lima chunk (Config, Security residual, Resource, Container/Autowiring residual,
dan core: Event/Message/CQRS/Cache/Job/Policy/Foundation/Exception/Observability
Domain). Baseline riil direproduksi per chunk (487 escape total), ditambang
menjadi kurikulum per kelas via `build/fase6/escapes-*.txt`, lalu dieksekusi dua
ronde: EdgeMatrixF6CoreTest/RestTest/MixedTest (ronde 1) + EdgeMatrixF6Round2Test
(pemburu residual berbasis diff persis).

Catatan lingkungan: sandbox reset lagi menghapus PHP lokal + vendor + build —
pulih penuh via `scripts/setup_php_env.sh` + ekstraksi arsip 7z v2.14.7 dalam
8 detik (ekstraksi 277MB), suite 981 test hijau sebelum satu baris pun ditulis —
bukti kedua nilai distribusi arsip penuh.

## Hasil terukur (Infection PCOV, threads=2, foreground)

| Chunk | MSI | Covered MSI | Escape |
|---|---:|---:|---:|
| dom-rescfg (`Domain/Config`) | 73 → **86** | 82 → **96** | 18 → **4** |
| dom-sec (`Domain/Security`) | 85 → **95** | 87 → **95** | 49 → **17** |
| dom-rest (`Domain/Resource`) | 64 → **89** | 73 → **90** | 121 → **50** |
| dom-core-a (Container + Autowiring) | 72 → **89** | 78 → **94** | 66 → **19** |
| dom-core-b (Event/Message/CQRS/Cache/Job/Policy/Foundation/Exception/Obs-Domain) | 59 → **93** | 66 → **94** | 233 → **42** |

Total: **487 → 132 escape (+509 kill)** pada 2.151 mutan chunk. Rata-rata MSI
chunk **90.4** — zona Domain kini setara zona HTTP/observability. Sisa escape
terinventarisasi jujur: self-consistent codec cursor (checksum dihitung codec
yang sama), ±1 pada wire-size yang tak pernah melewati batas struktur (568 <
8192), swap konstanta yang bernilai sama (operation vs idempotency keduanya
128), random jitter (mutant statistik), For_/loop-negation pada traversal radix
yang bermuara status sama, dan not-covered guard dedup/equivalent.

Gate mutasi `composer.json` naik 71.5/76 → **75/80** (estimasi global konservatif
dari +509 kill pada ~10k mutan total; full-run 12 chunk tetap pekerjaan ronde
akhir). PHPUnit kini **1131 test / 14.3k+ asersi** (+150 test / 993 asersi zona
Domain: 124 ronde 1 + 26 ronde 2).

## Kurikulum ronde 1 (tests/Unit/EdgeMatrixF6{Core,Rest,Mixed}Test.php — 124 test)

- **CorrelationContext (48 escape)**: grammar W3C ter-anchor penuh (traceid 32/
  spanid 16 hex lowercase, garbage prefix/suffix, all-zero ditolak); traceflags
  hanya `00`/`01` (mask `& 0xFE`); tracestate boundary 512/513 + multi-member
  dengan koma-opsional-spasi sah; token printable `[\x21-\x7E]` (spasi/DEL
  ditolak) 128/129; atribut 16/17, key grammar 64/65 + key numerik, value
  256/257 + non-scalar; **agregat 4096/4097 dibangun programatik** (15×257 +
  pengisi 241/242) — membunuh Assignment/PlusEqual; **biaya null=4 via crossing
  4091/4092+null** — membunuh IntegerNegation; redaksi sensitif mixed-case
  (`Authorization`, `user_token`) + non-string `[REDACTED]`; propagationBytes
  55/59 eksak.
- **CronExpression (36)**: trim split multi-whitespace; wildcard/range/list/
  step/`a/n`=a-max/n; sorted output `59,0`; pesan persis per-field; OR-semantika
  dom+dow dengan timestamp UTC deterministik (Selasa 13, Senin 19, Selasa 20);
  nextRunAfter **boundary nanodetik strictly-greater** (1 nano sebelum boundary
  mengembalikan boundary itu; boundary eksak → menit berikut); daily skip 12:00
  → 12:30 → besok.
- **RetryBackoffPolicy + RetryPolicy (47)**: guard inclusive (0 OK, -1 throw);
  eksponen `2**i` + cap min() eksak per index; round half-up 151.5→152 vs
  126.25→126 (RoundingFamily); env clamp 10/10000/60000 ±1 + default saat
  unset/non-numerik/whitespace; cap 0 dengan initial 0 tetap 0 (bunuh min ±1).
- **Resource (121)**: FilterSpec bentuk nested+flat+prefix kustom, cap 32 kedua
  bentuk, key 72/73, truncation value 256/257, grammar operator `_in` split
  trim-skip-empty, equals bool→'1'/'0' + float 5.0→'5' + 5.5→'5.5', NEQ null
  match, LIKE case-insensitive + numerik, GT/GTE/LT/LTE numerik-vs-leksikal;
  SortSpec `'-'`/`'+'`/double-prefix/ltrim-rantai, dup first-wins, cap 8, field
  64/65, fallback defaultFields + defaultDesc; Cursor roundtrip salt, pesan
  per-arm (format/encoding/version/offset/checksum), MAX_OFFSET 2^62, URL-safe
  tanpa padding; PageRequest scalarToInt anchor (spasi/13-digit/negatif),
  clamp `PHP_INT_MAX - limit`, fallback page/per-page, defaultLimit clamp;
  PageSlice hasNext strictly-less + meta shape persis (total/next/prev hadir-
  tidak-hadir).
- **ServiceDefinition (14)**: blok penuh — guard deps/tags/lifetime/shared,
  fromArray koalesensi lifetime/shared/lazy + array_values deps kunci non-
  kontigu.
- **NamespaceRadixTree residual (29)**: mid-edge traversal (`App\Http` di edge
  `Http\Api`), mismatch di posisi mana pun, idsUnderPrefix sorted SORT_STRING,
  scopeOf longest-prefix, stats exact (nodes 6/edges 5/rawSegments 11/rasio
  2.2 + pohon desimal-berulang 12/7), ksort annotations & children pasca-seal
  (urutan kunci via exportArray), fromArray cast string + default 0/true.
- **Autowiring residual**: AutowireMetadata argument plan dep/literal + index
  negatif/unknown; Target class/interface/enum; Value grammar 190/191; Inject
  whitespace; AutowireResult transitive DFS.
- **Security residual (49)**: SecurityPolicy default exact (semua 14 properti),
  guard inclusive masing-masing, SameSite=None+Secure, origin butuh whitelist,
  dedup origin list-ketat, env trim (cookie/header/samesite/limit), envPositiveInt
  non-digit/0/-5, matrix CSRF (1 tanpa secret → RuntimeException, 0 → off,
  '' → default), warning via logger mock + **error_log diarahkan ke tmpfile
  (pola $previous)**; Base32 vektor RFC 4648 penuh + padding-bit kanonik (`MF`
  ditolak) + anchor (1A/A1); Totp vektor RFC 4226 counter 0..9 + **counter
  ≥ 2^32 (999456/108930/166590)** untuk mask/shift 64-bit, RFC 6238 8-digit,
  window ±1 eksak, grammar kode, secret 7/8 byte; Distributed VO bounded semua
  field + guard konjungtif (AuthenticationResult 4 kombinasi,
  SecurityAdmissionDecision ALLOW/DENY×failure×retry).
- **ModuleDefinition (13)**: trim nama validasi (promoted property tetap asli),
  guard registry key int + instance + id-mismatch pesan persis, dedup dependensi
  non-kontigu (`['Api','api','Billing']` → `['api','billing']` list ketat),
  routes array_values kunci 3/7, koalesensi dependencies-vs-requires kedua arah,
  continue-vs-break via entri kedua tanpa factory, alias/dep non-string throw.

## Kurikulum ronde 2 (tests/Unit/EdgeMatrixF6Round2Test.php — 26 test)

Diburu dari diff escape pasca-ronde 1, pola yang terbukti: **truncation dengan
karakter pemembeda** (`250×'v'+'TAIL-abcd'` — substr ±1 tak terlihat pada input
seragam); **crossing tepat scalarByteLength** (bool 4/5, int 20, float 24 di
4091/4092/4075/4076/4071/4072 + biaya — membunuh ±1 dan IntegerNegation kedua);
pesan eksepsi persis (Concat/ConcatOperandRemoval di PageRequest/Cursor);
kursor racik `v1.-1.0` + encode offset > 2^62 (min_range/max_range); cap flat
33; field 65 whitelisted (regex `{1,64}` + guard); LIKE bool-tolak/numerik-terima;
`'10' > '9'` numerik-vs-leksikal; defaultDesc false tanpa argumen; defaults tak
ditambahkan saat keys ada; truncation raw 512-vs-513 (name di ekor raw); field
65 di tengah list tak menghentikan; sort 2 baris; ksort annotations/children;
fromArray cast string numerik; key konteks 128 byte sah di Cqrs/Message/Job
(>= mutant); create() 32-hex; withDeadlineMs(1) sah; priority/attempt default.

## Regresi & lingkungan

- PHPUnit **1131 test / 14.3k+ assertion** (11 skip terdokumentasi), PHPStan
  level max+strict **0** (ignores terkontrol di test invalid-input disengaja),
  cs-fixer + rector diapply ke 4 file test baru, phpcs 0.
- Test adversarial baru bebas efek-samping proses: env dipulihkan via pola
  `putenv`/finally, `error_log` diarahkan tmpfile dengan `ini_set` ke nilai
  `$previous` (pelajaran fase 3), tanpa sinyal/proses anak.
- vektor kripto di-hardcode dari hasil hitung lokal (RFC 4226 + counter 2^32)
  — deterministik, tanpa dependensi jaringan.
