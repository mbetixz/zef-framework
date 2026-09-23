# Referensi CLI — `bin/zef`

`bin/zef` adalah *composition root* CLI framework: ia mem-boot aplikasi demo hanya
ketika sebuah perintah benar-benar membutuhkan container (inspector), dan tetap
berjalan tanpa Composer.

```bash
php bin/zef                       # banner + versi
php bin/zef list                  # katalog command (+ --json)
php bin/zef --self-test           # suite diagnostik internal
php bin/zef --serve [addr]        # server pengembangan
```

---

## 1. Diagnostik & server

### `bin/zef --self-test [filter]`

Menjalankan suite diagnostik tanpa PHPUnit (`Zef\Test\CliRunner`). Filter bersifat
*case-insensitive* dan dicocokkan sebagai substring terhadap key maupun label suite.

```bash
php bin/zef --self-test          # seluruh suite: 501 assertion
php bin/zef --self-test=v280     # suite fitur v2.8.0
php bin/zef --self-test=v290     # suite autowiring v2.9.0
php bin/zef --self-test=v210     # suite enterprise v2.10.0
php bin/zef --self-test=v211     # suite radix-tree v2.11.0
php bin/zef --self-test=router   # apa pun yang key/label-nya mengandung "router"
```

Filter yang tidak cocok apa pun keluar dengan status **1** dan mencetak daftar key
yang tersedia — ini disengaja agar salah ketik tidak pernah tampak seperti "hijau".

### `bin/zef --serve [host:port]`

Menjalankan server pengembangan bawaan (`php -S`) dengan `public/index.php` sebagai
entrypoint. Alamat divalidasi terhadap pola `host:port`; alamat tidak valid ditolak
dengan status 1.

```bash
php bin/zef --serve                # default 0.0.0.0:8080
php bin/zef --serve 127.0.0.1:9000
```

---

## 2. Inspector (mem-boot aplikasi)

Ketiga perintah berikut memanggil `Bootstrap::createApp()` + `boot()` sehingga yang
dilaporkan adalah **keadaan runtime nyata**, bukan hasil parsing statis.

| Perintah | Keluaran |
|----------|----------|
| `bin/zef route:list` | tabel rute: METHOD, PATH, NAME, HANDLER, MODULE, PRIO + jumlah |
| `bin/zef module:list` | modul terdaftar dari `ModuleRegistry` pasca-boot |
| `bin/zef plugin:list` | plugin yang ditemukan di `plugins/` (sumber kebenaran: disk) |
| `bin/zef config:show [key]` | dump config teragregasi; lookup *dotted key* opsional |

`config:show` bersifat **JSON-safe**: `Closure` → `"<closure>"`, objek →
`"<object NamaKelas>"`, resource → `"<resource>"`. Lookup dotted key memakai sentinel
yang membedakan *key tidak ada* (**exit 1**) dari *nilai `null` tersimpan*
(**exit 0**) — perbedaan ini penting untuk skrip.

```bash
php bin/zef route:list
php bin/zef config:show middleware.services
php bin/zef config:show tidak.ada.key    # exit 1
```

---

## 3. Generator — `bin/zef make:*`

10 generator menghasilkan artefak kerja framework. Seluruhnya melewati
`ZefMaker` → `NamingRules` → `ScaffoldWriter`, sehingga aturan berikut berlaku
seragam:

- **Nama divalidasi**, termasuk penolakan *reserved word* PHP secara
  case-insensitive (`make:entity List` ditolak, bukan menghasilkan kode rusak).
- **Penulisan transaksional**: seluruh target dicek tabrakan terlebih dahulu, baru
  ditulis; satu tabrakan membatalkan seluruh batch tanpa berkas parsial.
- **Exit 1 + pesan stderr** untuk nama invalid, tabrakan, atau command tak dikenal.

| Command | Menghasilkan |
|---------|--------------|
| `make:module <name>` | `modules/<Pascal>/` — `ConfigProvider` + `HomeHandler` |
| `make:plugin <Name>` | `plugins/<Name>/` — `ConfigProvider` + `Service` + `Handler` |
| `make:handler <Name> [--module=] [--path=/uri]` | handler PSR-15 di dalam modul |
| `make:middleware <Name>` | middleware PSR-15 di `src/Middleware/` (target PSR-4 `Zef\Middleware\`) |
| `make:config <Name> [--module=]` | `ConfigProvider` modul — mekanisme config ZEF (bukan berkas lepas) |
| `make:command <Name> [--module=]` | pasangan CQRS `Command` + `CommandHandlerInterface` |
| `make:query <Name> [--module=]` | pasangan CQRS `Query` + `QueryHandlerInterface` |
| `make:entity <Name> [--module=]` | entitas Domain dengan identitas + `equals()` |
| `make:valueobject <Name> [--module=]` | `final readonly class` + validasi constructor |
| `make:service <Name> [--module=]` | service aplikasi + snippet wiring |

Contoh alur kerja:

```bash
php bin/zef make:module katalog
php bin/zef make:command PlaceOrder --module=katalog
php bin/zef make:valueobject Uang --module=katalog
composer dump-autoload            # atau perbarui classmap statis tanpa Composer
php bin/zef --self-test
```

> Setelah menambah kelas, autoloader harus diperbarui. Pada jalur zero-composer
> classmap statis `autoload/zef_autoload.php` perlu entri baru; dengan Composer
> cukup `composer dump-autoload`.

---

## 4. REPL — `bin/zef tinker`

REPL stateful dengan `$app` dan `$container` siap pakai. Mendukung eksekusi sekali
jalan, melewati boot, dan *override* produksi.

```bash
php bin/zef tinker                       # sesi interaktif
php bin/zef tinker -e '$app->getRouter()'
php bin/zef tinker -e='$container->get("cache")' --no-boot
php bin/zef tinker --force               # izinkan saat ZEF_ENV=production
```

| Opsi | Efek |
|------|------|
| `-e <expr>` / `-e=<expr>` | eksekusi satu ekspresi lalu keluar |
| `--no-boot` | jangan boot aplikasi (hanya container berdiri sendiri) |
| `--force` | mengizinkan REPL saat `ZEF_ENV=production` |

**Pengaman produksi:** saat `ZEF_ENV=production`, tinker **menolak** berjalan karena
REPL mengeksekusi kode arbitrer; `--force` adalah *override* eksplisit.

---

## 5. Keluar-kode

| Kode | Arti |
|------|------|
| `0` | sukses |
| `1` | command tak dikenal, nama/filter invalid, tabrakan scaffold, key config tidak ada, atau suite self-test tidak menemukan kecocokan |

Kontrak ini membuat `bin/zef` aman dipakai di pipeline CI: kegagalan tidak pernah
dilaporkan sebagai sukses.
