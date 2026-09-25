# Plugin ZEF — Kontrak Manifest & Registry

> Berlaku sejak v2.29.0. Dokumen ini membekukan kontrak minimum yang membuat
> sebuah paket PHP diakui sebagai *plugin* ZEF oleh CLI (`plugin:list`,
> `make:plugin`) dan oleh composition root, serta deskripsi registry index
> yang menjadi acuan distribusi.

---

## 1. Definisi

**Plugin ZEF** adalah paket kode yang:

1. hidup di direktori `plugins/<Nama>/` (in-repo) **atau** dikirim sebagai
   paket Composer yang mengekspos `ConfigProvider` pada namespace
   `Zef\Plugin\<Nama>\`,
2. menyediakan **satu `ConfigProvider`** yang mengimplementasikan
   `Zef\Framework\Config\ConfigProviderInterface`,
3. mendaftarkan seluruh service-nya lewat `getConfig()` (factory + deps +
   lifetime) dan seluruh route-nya lewat section `routes`,
4. **tidak pernah** mem-boot sendiri, membaca superglobal, atau menyimpan
   state global — semua dependensi dituntun lewat container.

Framework memperlakukan plugin dan modul dengan konfigurasi yang sama; kata
"plugin" menandakan **unit distribusi**, bukan tipe runtime berbeda.

---

## 2. Kontrak manifest

### 2a. Layout direktori

```
plugins/
└── Toko/
    ├── ConfigProvider.php     # WAJIB — manifest service + route
    ├── TokoHandler.php        # handler PSR-15 (bebas dinamai)
    ├── ProdukService.php      # service domain plugin
    └── ProdukDetailHandler.php
```

### 2b. Namespace & penamaan

| Elemen | Aturan | Contoh |
|---|---|---|
| Direktori plugin | `plugins/<Nama>/` — PascalCase | `plugins/Toko/` |
| Namespace | `Zef\Plugin\<Nama>\` | `Zef\Plugin\Toko` |
| Manifest class | `<Nama>\ConfigProvider`, `final`, tanpa state | `Toko\ConfigProvider` |
| Service id | `<kebab-module>.<role>.<name>` | `toko.service.produk` |
| Nama modul | `getModuleName(): string` — kebab-case unik | `toko` |

### 2c. Isi `getConfig()`

`ConfigProvider::getConfig(): array` mengembalikan dua section:

```php
final class ConfigProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'toko';
    }

    #[\Override]
    public function getConfig(): array
    {
        return [
            'services' => [
                'toko.service.produk' => [
                    'factory'  => static fn (): ProdukService => new ProdukService(),
                    'deps'     => [],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
                'toko.handler.index' => [
                    'factory'  => static fn (ContainerInterface $c, ProdukService $svc): TokoHandler
                        => new TokoHandler($svc),
                    'deps'     => ['toko.service.produk'],
                    'lifetime' => ServiceLifetime::SINGLETON,
                ],
            ],
            'routes' => [
                ['method' => 'GET', 'path' => '/toko', 'handler' => 'toko.handler.index', 'priority' => 100],
                ['method' => 'GET', 'path' => '/toko/produk/{id:int}', 'handler' => 'toko.handler.detail', 'priority' => 100],
            ],
        ];
    }
}
```

Aturan yang menegakkan kontrak ini:

- **`factory` adalah satu-satunya cara membuat service.** Closure menerima
  dependensi yang dideklarasikan di `deps` (id service container) — DI manual
  di dalam closure diperbolehkan untuk parameter non-container.
- **`lifetime`** opsional (`SINGLETON` default per modul; lihat
  `ServiceLifetime`). Handler HTTP sebaiknya SINGLETON bila stateless.
- **`deps`** diperiksa container saat resolusi; referensi lintas modul tunduk
  pada kebijakan `framework.container.max_cross_module_refs`.
- **`routes`** berisi `method`, `path` (dukung placeholder `{id:int}`),
  `handler` (service id), `priority` (angka lebih besar = lebih dulu cocok),
  `name` (opsional, untuk URL generation).
- **Config governance**: section tambahan di luar `services`/`routes` (mis.
  key konfigurasi plugin sendiri) mengikuti skema konfigurasi framework dan
  divalidasi saat boot (`ConfigurationGovernance`).

### 2d. Hook lifecycle (opsional)

Plugin yang butuh wiring imperatif — misalnya mendaftarkan mapping CQRS —
dipaketkan sebagai **Module**: class `final readonly` yang meng-extend
`Zef\Framework\Config\AbstractModule`, menyalin definisi dari ConfigProvider,
dan meng-override `register(ModuleContext $context)` (baca:
`docs/TUTORIAL-CQRS-101.md` Bagian 6b). Composition root lalu memanggil
`$app->addModule(new TokoModule())` alih-alih `addProvider`.

---

## 3. Registrasi di composition root

```php
// app/Bootstrap.php (atau src/Bootstrap.php pada framework checkout)
use Zef\Plugin\Toko\ConfigProvider as TokoConfigProvider;

$app->addProvider(new TokoConfigProvider());
```

Aturan keamanan yang ditegakkan kernel:

- `addProvider()`/`addModule()` **ditolak setelah boot**
  (`LogicException`) — tidak ada registrasi nyasar saat runtime.
- Service id duplikat antar plugin → error resolusi eksplisit, bukan
  overwrite diam-diam.
- Route tabrakan (method+path sama, priority sama) → penolakan boot oleh
  router (ambiguity guard).

---

## 4. Registry index (CLI & distribusi)

### 4a. `bin/zef plugin:list`

`PluginLister` memindai `plugins/` pada root dan mencetak setiap plugin
beserta berkas PHP-nya — filesystem adalah *single source of truth*:

```text
$ php bin/zef plugin:list
Toko    ConfigProvider.php, ProdukDetailHandler.php, ProdukService.php, TokoHandler.php
```

### 4b. `bin/zef make:plugin`

Generator v2.16+ menghasilkan kerangka plugin yang **lulus kontrak di atas
sejak detik nol** (namespace, manifest, service id, handler):

```bash
php bin/zef make:plugin Katalog
# plugins/Katalog/{ConfigProvider,KatalogHandler,KatalogService}.php
```

### 4c. Kriteria masuk registry index

Registry index adalah daftar plugin yang **siap dipublikasikan** ke katalog
komunitas. Kriteria v2.29.0:

1. `plugin:list` menampilkan plugin tanpa berkas penyisip asing.
2. `getConfig()` lulus validasi konfigurasi saat boot (tanpa pelanggaran
   skema).
3. Tidak menambah dependensi `require` baru pada framework — dependensi
   opsional dideklarasikan lewat `suggest` + guard `class_exists()`/test
   skip (pola `ext-redis`).
4. Menyediakan set test sendiri; mutan di area intinya tercakup zone tes
   plugin.
5. README plugin menyebutkan module name, service id, dan route yang
   didaftarkan (agar tidak bentrok dengan plugin lain).

### 4d. Distribusi (panduan)

- **In-repo**: PR menambah `plugins/<Nama>/` + entri di README plugin.
- **Composer**: paket tipe `zef-plugin` (konvensi; belum ada installer
  khusus) yang mengekspos `Zef\Plugin\<Nama>\ConfigProvider` via PSR-4,
  lalu konsumen memanggil `addProvider()` manual di composition root.
- **Tidak ada auto-discovery runtime**: kejelasan wiring selalu menang —
  composition root adalah tempat satu-satunya yang tahu urutan boot.

---

## 5. Referensi implementasi

`plugins/Toko/` adalah plugin referensi yang memenuhi seluruh kontrak
bagian 2 — gunakan sebagai templat saat menulis plugin baru:

| Berkas | Peran |
|---|---|
| `ConfigProvider.php` | manifest: 3 service (1 service + 2 handler) + 2 route |
| `ProdukService.php` | service domain, SINGLETON, tanpa deps |
| `TokoHandler.php` | handler `GET /toko`, deps → `toko.service.produk` |
| `ProdukDetailHandler.php` | handler `GET /toko/produk/{id:int}` |

Uji cepat kontrak Anda sendiri:

```bash
php bin/zef plugin:list          # plugin terlihat
php bin/zef route:list           # route plugin terdaftar
php bin/zef config:show toko.*   # config plugin ter-agregasi
php bin/zef doctor               # boot smoke tetap hijau
```
