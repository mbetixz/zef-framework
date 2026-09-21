# CHANGELOG — v2.14.2 (Mutation Deep-Dive Round 3, Edge-Case Matrix approach)

Pendekatan ronde 3: **katalog edge-case sebagai kurikulum, chunk Infection sebagai
ujian** (docs/EDGE-CASE-MATRIX.md). Setiap test baru mewakili skenario adversarial
nyata — bukan test tautologis pengejar mutan.

## Fase 1 — Tier Security & Validation (TUNTAS)

- File test baru `tests/Unit/EdgeMatrixSecValTest.php` (61 test) +
  `tests/Unit/EdgeMatrixSecDomTest.php` (27 test) = **88 test / 406 asersi**.
- Cakupan adversarial: boundary ±1 di seluruh guard (regex 2048/2049, rule 64/65,
  template 512/513, port/status/identifier, TTL TOTP 1..86400, seed RFC per
  algoritma), ReDoS guard, kebersihan error-handler (uji kebocoran `finally`),
  locale chain, silinder atribut keamanan (firewall kata terlarang + budget 4096
  byte), vektor resmi RFC 4226 (10) & RFC 6238 (3 algoritma), RFC 4648 Base32 +
  padding non-kanonik.
- Verifikasi chunk `domain-secval`: **MSI 64.4 → 84.0** (covered 71.6 → 87.0);
  +193 kill; escape 249 → 120; not-covered 98 → 33; waktu 3m27s (threads=2).

## Fase 2 — Tier Container (PARSIAL)

- File test baru `tests/Unit/EdgeMatrixRadixTreeTest.php` (10 test / 66 asersi):
  kompresi radix, kueri mid-edge, scope nearest-ancestor, stats, export/restore.
- Chunk `src/Domain/Container`: MSI 77.1% / covered 80.2%; escape
  NamespaceRadixTree 56 → 32.
- Container.php (68 escape), AutowireCompilerPass (75), ContainerResolver (40)
  **belum ditackle** — dipindah ke fase 2b ronde lanjutan.

## Perubahan gate

- `composer.json` mutation gate: `--min-msi=64 --min-covered-msi=68` →
  **`--min-msi=66 --min-covered-msi=71`** (estimasi global pasca-fase 1–2
  ≈ 68.8 / 75.3; margin aman ~3–4 poin).
- ZefVersion 2.14.1 → **2.14.2**; coverage statement 91.55 → **92.04%**.

## Regresi penuh (13 tool, hijau)

| Tool | Hasil |
|---|---|
| composer validate --strict | valid |
| audit (abandoned policy) | 0 unacknowledged |
| lint | 359 file, 0 gagal |
| self-test (bin/zef) | 501 PASSED / 0 FAILED |
| phpunit | 666 test / 12.302 asersi (+5 skip kondisional) |
| phpstan (max + strict) | 0 error |
| deptrac (fail-on-uncovered) | 0 |
| cs-fixer check (PER-CS2 + @PHP84Migration) | 0 dari 326 file |
| phpcs (Slevomat) | 0 |
| rector (agresif, dry-run) | 0 file |
| phpbench | 0.620µs singleton (stabil) |
| doctum docs | regenerate OK |
| coverage:gate 90% | **92.04% PASSED** |

## Temuan perilaku (dipin sebagai dokumentasi hidup, bukan bug)

1. PCRE2 memperlakukan NUL byte di pattern sebagai byte literal —
   `/^a<NUL>/` hanya match subjek `a<NUL>` (tidak truncate, tidak ValueError).
2. Guard ReDoS sengaja mengizinkan quantifier possessive (`(?<tag>x+)?+`) —
   aman dari backtracking by construction.
3. `SecurityPolicy::envPositiveInt()` mem-trim nilai sebelum `ctype_digit`
   sehingga `' 50 '` diterima sebagai 50.
4. Ekuivalen/defensif diinventarisasi: catch `ValueError` di `addCustom`
   (tidak terjangkau via API publik), `preg_last_error_msg` di jalur kustom.
