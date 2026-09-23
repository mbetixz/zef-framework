# ZEF Framework — Official Documentation

Dokumentasi resmi **ZEF Framework** (`mbetixz/zef-framework`) — framework PHP 8.4
berarsitektur *Hexagonal (Ports & Adapters)* dengan worker RoadRunner.

> **API reference (generated):** <https://mbetixz.github.io/zef-framework/>
> — dibangun otomatis oleh Doctum pada setiap push ke `main`.

---

## Daftar Isi

| Dokumen | Isi | Untuk siapa |
|---------|-----|-------------|
| [`INSTALLATION.md`](INSTALLATION.md) | Persyaratan, instalasi (Composer & zero-composer), RoadRunner, Docker, Kubernetes, variabel lingkungan | Operator, developer baru |
| [`CLI.md`](CLI.md) | Referensi lengkap `bin/zef` — self-test, serve, route list, inspector, 10 generator `make:*`, tinker | Developer sehari-hari |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Pemecahan monolith → layer hexagonal, aturan arah dependensi, peta namespace → layer, autoloading ganda | Arsitek, reviewer |
| [`QUALITY.md`](QUALITY.md) | Seluruh gerbang kualitas: PHPUnit, coverage, **mutation testing (MSI per area)**, PHPStan, PHPCS, cs-fixer, Rector, Deptrac | Kontributor, release manager |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Pola produksi: RoadRunner (persistent worker), graceful shutdown, observabilitas, health probe, keamanan | SRE, DevOps |
| [`ROADMAP.md`](ROADMAP.md) | Rencana kerja dan status fitur | Product, kontributor |
| [`EDGE-CASE-MATRIX.md`](EDGE-CASE-MATRIX.md) | Kurikulum uji edge-case per fase kampanye mutasi | QA, kontributor |
| `CHANGELOG-v*.md` | Catatan rilis per versi (append-only) | Semua |

## Rujukan cepat

```bash
# tanpa Composer (zero-composer fallback)
php bin/zef --self-test            # 501 assertion self-test
php bin/zef --serve 0.0.0.0:8080   # server HTTP pengembangan

# dengan Composer
composer install
composer test                      # suite PHPUnit native
composer mutation                  # kampanye mutasi Infection (gate MSI)
composer stan                      # PHPStan level max + strict-rules
composer deptrac                   # konformansi arsitektur hexagonal
composer docs                      # bangun API reference -> build/api
```

## Struktur dokumentasi ini

```
docs/
├── README.md            ← Anda di sini (indeks)
├── INSTALLATION.md
├── CLI.md
├── ARCHITECTURE.md
├── QUALITY.md
├── DEPLOYMENT.md
├── ROADMAP.md
├── EDGE-CASE-MATRIX.md
├── security/
│   └── php-sast.md
├── CHANGELOG-v2.7.0.md … CHANGELOG-v2.16.0.md
└── CHANGELOG-v2.17.0.md   (rilis terbaru)
```

## Konvensi dokumen

- **Bahasa:** dokumen kanal `docs/` memakai Bahasa Indonesia (bahasa kerja proyek),
  dengan istilah teknis dipertahankan dalam bahasa Inggris.
- **Sumber kebenaran:** kode dan gate CI adalah sumber kebenaran; dokumen ini
  merangkum, tidak menggantikan. Setiap angka pada dokumen quality berasal dari
  eksekusi nyata dan dicatat beserta artefak buktinya.
- **Append-only:** `docs/CHANGELOG-v*.md` tidak pernah ditulis ulang; koreksi
  ditambahkan sebagai berkas versi baru.
