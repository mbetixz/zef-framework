# CHANGELOG v2.30.0 — Ecosystem Ports

**Tema**: mengisi port ekosistem yang tersisa (ObjectStorage, transport
pesan, antrean job tahan lama) **tanpa menambah satu paket runtime pun** —
kebijakan 3-paket (`spiral/roadrunner-http`, `nyholm/psr7`, `php`) dipertahankan.
Roadmap "CDN/storage adapters (S3, GCS, Local)" ✓ dan fondasi adapter broker
diselesaikan; referensi isu: #89 (type library — tidak disentuh di rilis ini),
#88 (tag catch-up — fase berikutnya).

## Fitur baru

### Object storage — port + 2 adapter (v2.30.0)

- **Port Domain `Storage\ObjectStorageInterface`** — enam operasi primitif
  (`put`/`get`/`delete`/`exists`/`stat`/`list`); kontrak eksplisit:
  `get`/`stat` melempar `ObjectNotFoundException` pada kunci absen,
  `delete` idempoten, `list` terurut leksikografis terbatas 1..1000.
- **VO `ObjectStat`** (key, sizeBytes, lastModifiedUnixNano, immutabel).
- **Hierarki exception**: `StorageException` (base, memetakan semua kegagalan
  backend — driver types tidak pernah bocor) + `ObjectNotFoundException`.
- **`StorageKeys`** — otoritas validasi tunggal kunci/prefiks: 1..1024 byte,
  tanpa karakter kontrol, tanpa backslash (portabilitas POSIX/Windows),
  tanpa slash awal/akhir, segmen `.`/`..`/kosong ditolak (traversal).
- **`Infrastructure\Storage\LocalStorage`** — filesystem adapter: root
  dibuat otomatis + dikanonikalisasi (`realpath`), tulis atomik
  (berkas sementara unik di direktori tujuan + `rename(2)`), batas ukuran
  objek (default 64 MiB), penghapusan idempoten, list rekursif terurut.
  Path-safety berlapis: grammar `StorageKeys` (lapis 1) + resolusi
  `root + '/' + key` yang matematis tak bisa lolos root (lapis 2).
- **`Infrastructure\Storage\S3CompatibleStorage`** — adapter kompatibel S3
  **dengan SigV4 in-house, tanpa SDK**:
  - AWS S3 (path-style, endpoint default `https://s3.{region}.amazonaws.com`);
  - MinIO / Ceph RGW / Cloudflare R2 lewat endpoint eksplisit;
  - Google Cloud Storage via XML-API interoperability (kredensial HMAC);
  - penanda tangan `SigV4` murni & deterministik (tanggal argumen eksplisit)
    — teruji terhadap **vektor resmi AWS** (GET Object & PUT Object);
  - encoding tunggal: kunci di-`rawurlencode` per segmen sekali dan dipakai
    bersama oleh URL & canonical URI (aturan "s3 tidak double-encode");
  - kueri kanonik `canonicalQuery()` publik → URL aktual byte-identik
    dengan bentuk yang ditandatangani;
  - `ListObjectsV2` di-parsing `ext-simplexml`, namespace-aware (AWS tanpa
    xmlns, MinIO/Ceph/R2 dengan xmlns 2006-03-01);
  - pemetaan respons: 2xx sukses, 404 → `ObjectNotFoundException`/`null`/
    void (idempoten), non-2xx lain → `StorageException` (status + cuplikan
    isi 256 byte, whitespace dinormalisasi).
- **Kabel HTTP**: `S3HttpTransport` (seam interface) + `CurlS3HttpTransport`
  (`ext-curl` wajib secara *lazy* — dijelaskan lewat `composer suggest`;
  timeout konek 5 dtk / total 30 dtk; penguraian header multi-nilai
  case-insensitive). Tanpa jaringan untuk pengujian: transport rekaman
  diinjeksikan lewat interface.

### Transport pesan — InMemoryMessageTransport (v2.30.0)

- **`Infrastructure\Message\InMemoryMessageTransport`** — implement
  `MessageTransportInterface` (port v2.8.0): FIFO in-process terbatas
  kapasitas (`OverflowException` saat penuh, mengikuti pola
  `InMemoryJobQueue`), id transport berurutan `mem-%06d` yang deterministik,
  hasil `accepted=true` mengikuti semantik broker (kirim sukses independen
  konsumen).
- **VO `ReceivedMessage`** + API konsumsi `receive()`/`drain()`/`size()` —
  peran bus uji (verifikasi publikasi) dan dev transport.
- Transport broker (Redis Streams/NATS/SQS/Kafka) tinggal mengimplementasi
  port yang sama — panduan kontributor 6 langkah ada di
  [`INTEGRATIONS.md`](INTEGRATIONS.md) §3.

### Job queue & idempotency — adapter PDO tahan lama (v2.30.0)

- **`Infrastructure\Job\PdoJobQueue`** — implement `JobQueueInterface`
  di atas `Database\ConnectionInterface` (pasangan tahan lama dari
  `InMemoryJobQueue`):
  - urutan `priority DESC → available_at ASC → seq ASC`; `seq` =
    `MAX(seq)+1` di dalam INSERT transaksi (portabel
    SQLite/MySQL/PostgreSQL), `UNIQUE(job_id)` sebagai pengaman balapan
    enqueue — yang kalah gagal keras, bukan diam-diam mengubah urutan;
  - klaim dequeue: SELECT kandidat + DELETE per `job_id` dalam satu
    transaksi; balapan yang kalah (affected rows 0) melanjutkan pemindaian
    (dibatasi 8 iterasi — antrean yang terus berbalapan tetap terminasi).
    `SELECT ... FOR UPDATE` sengaja dihindari (SQLite menolak);
  - transaksi ambien diikuti (persist agregat + enqueue atomik — pola
    `PdoEventStore`);
  - payload & header JSON (mixed round-trip; objek PHP = tanggung jawab
    pemanggil); payload rusak di penyimpanan melempar
    `JobExecutionException::corruptPayload()` (pola `EventJson`);
  - kapasitas opsional (`maxSize`) lewat pemeriksaan COUNT per enqueue;
  - `createSchema()` DDL portabel, aman dijalankan berulang.
- **`Infrastructure\Job\PdoJobIdempotencyStore`** — implement
  `JobIdempotencyStoreInterface`: nilai produsen di-cache selama TTL hidup,
  entri kedaluwarsa di-sapu lazy tanpa reaper latar, produsen yang gagal
  tidak pernah di-cache, balapan eksekusi-pertama diselesaikan oleh
  `UNIQUE(idem_key)` — yang kalah mengadopsi nilai pemenang (eksekusi
  at-least-once, efek exactly-once).

## Dokumentasi

- **`docs/INTEGRATIONS.md` (baru)** — peta resmi port × adapter first-party,
  panduan pakai object-storage/message/job, resep 6 langkah kontributor
  adapter broker, jembatan Cycle ORM (integrasi tingkat aplikasi — tanpa
  melanggar kebijakan 3-paket), dan matriks kompatibilitas adapter.
- README: baris fitur Runtime + baris rilis v2.30.0 + tabel CLI/perintah;
  docs/README index menambah INTEGRATIONS.md.
- `docs/ROADMAP.md`: `CDN/storage adapters (S3, GCS, Local)` → **[x]**;
  baris broker adapters mendapat catatan "dasar siap".

## Kualitas

- **PHPUnit**: +73 test (`EcosystemPortsV30Test`, deterministik 100%) —
  grammar kunci per-karakter, vektor SigV4 resmi AWS (GET + PUT),
  pemetaan respons S3 per operasi via transport rekaman, XML namespace
  aware/tanpa-namespace, LocalStorage round-trip biner + atomisitas +
  traversal, transport pesan FIFO/kapasitas/id deterministik,
  antrean PDO (urutan 3-kunci, penundaan, duplikat, kapasitas, transaksi
  ambien, simulasi balapan klaim via decorator `RaceLosingConnection`,
  payload korup), idempotensi (cache/TTL/sapu/kegagalan produsen/balapan
  kalah/korupsi). Total 2807 test.
- **Zona mutasi baru** (registry `scripts/f16_zones.tsv`): `d-storage`,
  `infra-storage`, `infra-job-pdo` — MSI ≥ 95 per zona, bukti
  `docs/mutation/evidence/`.
- PHPStan level max + strict-rules: 0 error (helper narrowing baris
  ala `RowCast` di kedua adapter Job; bentuk `curl_setopt_array` rapi);
  PHPCS/cs-fixer/rector/deptrac/lint bersih; coverage gate 95.27% (≥ 90);
  self-test 501/0; ratchet PHPStan 509=509; ratchet zona PASSED.

## Catatan upgrade

- Tidak ada perubahan API lama; semuanya bertambah (additive).
- `composer.json` **tidak berubah** (kebijakan 3-paket dipertahankan);
  dependensi opsional baru dinyatakan lewat `suggest`: `ext-curl`
  (transport S3), `ext-simplexml` (penguraian XML ListObjectsV2).
- Memakai S3 adapter di produksi = pastikan `ext-curl` + `ext-simplexml`
  tersedia di image worker RoadRunner.
