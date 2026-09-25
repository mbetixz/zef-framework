# Tutorial CQRS 101 — Zero to Hero dengan ZEF Framework

> PHP 8.4 · Hexagonal · PSR-15 · RoadRunner · tanpa satu pun dependensi baru.
> Seluruh langkah di dokumen ini diverifikasi end-to-end: scaffold → boot →
> scaffold modul/command → wiring → dispatch → HTTP 200.

Tutorial ini memandu Anda dari **nol** sampai **aplikasi CQRS yang berjalan**:
satu *command* (`PlaceOrder`) yang menulis, satu *query* (`ListOrders`) yang
membaca, keduanya diakses lewat HTTP dan siap dijalankan di atas RoadRunner.
Setiap langkah memakai alat bawaan framework — `bin/zef make:app`,
`make:module`, `make:command`, `make:query` — jadi Anda hampir tidak pernah
menulis *boilerplate* secara manual.

---

## Daftar isi

1. [CQRS dalam 5 menit](#1-cqrs-dalam-5-menit)
2. [Scaffold aplikasi standalone (`make:app`)](#2-scaffold-aplikasi-standalone-makeapp)
3. [Tur skeleton hexagonal](#3-tur-skeleton-hexagonal)
4. [Modul `Orders` (`make:module`)](#4-modul-orders-makemodule)
5. [Command `PlaceOrder` (`make:command`)](#5-command-placeorder-makecommand)
6. [Wiring: service + bus mapping](#6-wiring-service--bus-mapping)
7. [Query `ListOrders` (`make:query`)](#7-query-listorders-makequery)
8. [Uji: tinker, curl, RoadRunner](#8-uji-tinker-curl-roadrunner)
9. [Troubleshooting — tiga jebakan klasik](#9-troubleshooting--tiga-jebakan-klasik)

---

## 1. CQRS dalam 5 menit

CQRS (*Command Query Responsibility Segregation*) memisahkan jalur **tulis**
dan jalur **baca** aplikasi. Di ZEF keduanya adalah dua bus terpisah:

| Jalur | Bus | Kontrak handler | Aturan main |
|---|---|---|---|
| Tulis (side-effect) | `CommandBusInterface` → `dispatch(object $command)` | `CommandHandlerInterface::__invoke(object, CqrsContext): mixed` | boleh mengubah state, boleh memicu event |
| Baca (tanpa side-effect) | `QueryBusInterface` → `ask(object $query)` | `QueryHandlerInterface::__invoke(object, CqrsContext): mixed` | wajib idempoten, idealnya hanya membaca |

Dua aturan lifecycle yang wajib Anda ingat:

1. **Registrasi mapping `message → handler` hanya boleh terjadi sebelum bus
   *frozen***. Kernel memanggil `freeze()` pada ketiga bus (`event`, `command`,
   `query`) di tengah `Application::boot()` — tepat setelah fase
   *register* semua modul selesai (`ModuleRegistry::registerAll()`). Titik
   legal yang paling bersih adalah **`register()` hook milik modul**.
2. **Handler hidup di container.** Mapping yang Anda daftarkan ke bus hanyalah
   *resolver* — biasanya `$container->get('modul.command.nama')` — sehingga
   dependensi handler tetap dikelola container (lifetime, contextual binding,
   dekorasi).

Itu seluruh teori yang Anda butuhkan. Sisanya adalah latihan tangan.

---

## 2. Scaffold aplikasi standalone (`make:app`)

Mulai dari checkout framework ini (atau `composer install` milik Anda), lalu:

```bash
php bin/zef make:app ../shop --name=shop --address=0.0.0.0:8080
cd ../shop
composer install
```

`make:app` menghasilkan proyek **mandiri** di luar checkout framework:

```
shop/
├── composer.json          # path-repo menunjuk balik ke checkout framework
├── .env.example           # knob ZEF_* (ZEF_ENV, ZEF_DEBUG, ZEF_HTTP_ADDRESS, …)
├── .rr.yaml               # konfigurasi RoadRunner siap pakai
├── app/Bootstrap.php      # composition root — satu-satunya tempat wiring
├── modules/Shop/          # modul pertama (ConfigProvider + handler "/")
├── public/index.php       # entrypoint web SAPI
└── bin/
    ├── worker.php         # entrypoint worker RoadRunner
    └── zef                # wrapper ZEF Maker untuk proyek ini
```

Detail penting pada `composer.json` hasil scaffold:

- **`repositories` → `type: path`** menunjuk ke checkout framework dengan
  jalur relatif yang dihitung dari *leluhur bersama* terdekat — aman berapa
  pun kedalaman nesting checkout Anda.
- **`autoload`** memetakan `App\` → `app/`, `Zef\Module\` → `modules/`, dan
  `Zef\Plugin\` → `plugins/` — konsisten dengan namespace yang dihasilkan
  seluruh generator `make:*`.
- **`scripts.zef`** membungkus `php bin/zef`, jadi `composer zef -- list`
  langsung jalan.

Scaffold sudah **bootable**: `php bin/zef make:app` menjanjikan "boots, serves
and passes PHPStan" — dan langkah 8 akan membuktikannya.

---

## 3. Tur skeleton hexagonal

Buka `app/Bootstrap.php` — *composition root* proyek Anda:

```php
$app = new Application($debug, $logger);
$app->setTrustedHosts(['localhost', '127.0.0.1', '::1']);
$app->addProvider(new MiddlewareConfigProvider($debug)); // CSP, CORS, dsb.
$app->addProvider(new ShopConfigProvider());             // modul pertama
```

Ketiga konsep hexagonal yang perlu dikenali:

- **Modul** = `ConfigProvider` yang mendeklarasikan *service* (factory + deps
  + lifetime) dan *route* (method, path, handler, priority). Ini satu-satunya
  tempat modul "mendaftar diri".
- **Handler** = PSR-15 `RequestHandlerInterface` paling luar — menerima
  `ServerRequestInterface`, mengembalikan `ResponseInterface`. Tidak ada
  logika bisnis di sini selain menerjemahkan HTTP ↔ pesan CQRS.
- **Entry point ganda** = `public/index.php` (dev server `composer serve`) dan
  `bin/worker.php` (RoadRunner). Keduanya memanggil `Bootstrap::createApp()`
  yang sama — tidak ada perbedaan wiring untuk produksi.

Uji cepat sebelum melangkah:

```bash
composer serve &            # dev server di 0.0.0.0:8080
curl -s http://localhost:8080/
# {"app":"shop","status":"ok"}
```

---

## 4. Modul `Orders` (`make:module`)

CQRS di ZEF hidup di dalam modul. Buat modul `Orders` dengan wrapper maker
yang di-scaffold di `bin/zef` proyek:

```bash
composer zef -- make:module Orders
```

Hasilnya `modules/Orders/` berisi `ConfigProvider.php` + `HomeHandler.php`
(dengan route contoh `GET /orders`) dan namespace `Zef\Module\Orders`.
Sesuai petunjuk keluaran generator, daftarkan modul di composition root:

```php
// app/Bootstrap.php
use Zef\Module\Orders\ConfigProvider as OrdersConfigProvider;

$app->addProvider(new OrdersConfigProvider());
```

> Catatan: `make:module` menulis *namespace* `Zef\Module\Orders` — bukan
> `App\Module\Orders`. Ini disengaja agar seluruh generator, plugin, dan
> modul inti memakai konvensi yang sama; `composer.json` scaffold sudah
> memetakan prefix tersebut.

---

## 5. Command `PlaceOrder` (`make:command`)

```bash
composer zef -- make:command PlaceOrder --module=orders
```

Dua berkas muncul di `modules/Orders/Command/`:

- `PlaceOrderCommand.php` — pesan tulis, `public readonly` properties
  (pesan adalah data, bukan service):

  ```php
  final class PlaceOrderCommand
  {
      public function __construct(
          public readonly string $id,
      ) {}
  }
  ```

- `PlaceOrderCommandHandler.php` — handler bertanda `#[\Override]` pada
  `__invoke(object $command, CqrsContext $context): mixed`. Badannya sengaja
  berisi TODO: generator menghasilkan *placeholder yang lolos static analysis*,
  bukan use-case bohongan.

Implementasi handler (contoh minimal, tanpa database supaya tutorial tetap
mandiri):

```php
#[\Override]
public function __invoke(object $command, CqrsContext $context): mixed
{
    // Side-effect nyata (persist, publish event, dsb.) dilakukan di sini.
    return ['id' => $command->id, 'status' => 'placed'];
}
```

---

## 6. Wiring: service + bus mapping

Dua pendaftaran diperlukan, dan **urutannya tidak bisa ditukar**.

### 6a. Handler sebagai service container

Di `modules/Orders/ConfigProvider.php` — perhatikan **baris `use` wajib**
karena handler tinggal di sub-namespace `Command\`:

```php
use Zef\Module\Orders\Command\PlaceOrderCommandHandler;

'services' => [
    // …service bawaan scaffold…
    'orders.command.place_order' => [
        'factory'   => static fn (): PlaceOrderCommandHandler => new PlaceOrderCommandHandler(),
        'deps'      => [],
        'lifetime'  => ServiceLifetime::SINGLETON,
    ],
],
```

### 6b. Mapping command → handler di `register()` hook

Fase *register* adalah satu-satunya jendela di mana (a) service modul sudah
terdaftar di container dan (b) bus belum *frozen*. Buat
`modules/Orders/OrdersModule.php`:

```php
<?php

declare(strict_types=1);

namespace Zef\Module\Orders;

use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\Config\AbstractModule;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Module\Orders\Command\PlaceOrderCommand;

final readonly class OrdersModule extends AbstractModule
{
    public function __construct()
    {
        $provider = new ConfigProvider();
        parent::__construct(ModuleDefinition::fromArray(
            $provider->getModuleName(),
            $provider->getConfig(),
        ));
    }

    #[\Override]
    public function register(ModuleContext $context): void
    {
        $container = $context->container();
        $bus = $container->get(CommandBusInterface::class);
        $bus->register(
            PlaceOrderCommand::class,
            $container->get('orders.command.place_order'),
        );
    }
}
```

Lalu ganti pendaftaran modul di `app/Bootstrap.php` dari
`addProvider(new OrdersConfigProvider())` menjadi:

```php
$app->addModule(new OrdersModule());
```

> Kenapa `addModule`, bukan `addProvider`? `addModule` mendaftarkan definisi
> service yang sama **sekaligus** membuka hook lifecycle (`register`,
> `boot`, `start`, `shutdown`). Memakai keduanya akan mendaftarkan service id
> yang sama dua kali → error duplikat.

---

## 7. Query `ListOrders` (`make:query`)

Sisi baca mengikuti pola yang identik, hanya berbeda bus:

```bash
composer zef -- make:query ListOrders --module=orders
```

Wiring lengkapnya (kumpulan langkah yang sama, disederhanakan):

1. `use Zef\Module\Orders\Query\ListOrdersQueryHandler;` di ConfigProvider,
   plus service `orders.query.list_orders`.
2. Di `OrdersModule::register()`, tambahkan:

   ```php
   $queryBus = $container->get(QueryBusInterface::class);
   $queryBus->register(
       ListOrdersQuery::class,
       $container->get('orders.query.list_orders'),
   );
   ```

3. Implementasikan handler query untuk **hanya membaca**:

   ```php
   #[\Override]
   public function __invoke(object $query, CqrsContext $context): mixed
   {
       return ['orders' => [['id' => 'order-1', 'status' => 'placed']]];
   }
   ```

4. Ekspos lewat handler HTTP `GET /orders` yang memanggil
   `$app->getQueryBus()->ask(new ListOrdersQuery())` — atau langsung dari
   handler PSR-15 melalui dependensi `QueryBusInterface` yang di-inject
   sebagai `deps` service.

---

## 8. Uji: tinker, curl, RoadRunner

**Verifikasi cepat via REPL** — `tinker` sudah meng-inject `$app` dan
`$container`:

```bash
composer zef -- tinker -e 'echo json_encode($app->getCommandBus()->dispatch(
    new Zef\Module\Orders\Command\PlaceOrderCommand("order-42")));'
# {"id":"order-42","status":"placed"}
```

**Verifikasi lewat HTTP** — tambahkan handler `POST /orders` yang
men-dispatch command (polanya sama dengan handler `GET /orders` bawaan
scaffold, hanya beda method di ConfigProvider `routes`), lalu:

```bash
composer serve &
curl -s -X POST http://localhost:8080/orders \
     -d '{"id":"order-42"}'
# {"id":"order-42","status":"placed"}
```

**Preflight sebelum produksi** — dua tool v2.29.0:

```bash
php bin/zef doctor    # PHP, ekstensi, autoloader, RR bridge/binary, boot smoke
php bin/zef rr:init   # generate/refresh .rr.yaml dari knob ZEF_*
```

`doctor` keluar `0` ketika tidak ada temuan FAIL; `rr:init` collision-safe
(tanpa `--force` ia menolak menimpa `.rr.yaml` hasil suntingan tangan Anda).

**Jalankan di RoadRunner** (runtime produksi, worker persisten):

```bash
vendor/bin/rr serve -c .rr.yaml
curl -s http://localhost:8080/
```

Tidak ada perubahan kode yang diperlukan antara dev server dan RoadRunner —
keduanya mem-boot `Bootstrap::createApp()` yang sama.

---

## 9. Troubleshooting — tiga jebakan klasik

### 9a. `ServiceNotFoundException` saat wiring di `createApp()`

Mendaftarkan mapping bus dengan `$container->get('orders.command.…')`
**sebelum** `$app->boot()` pasti gagal: service milik modul baru terdaftar ke
container di fase *register* di dalam `boot()`. Solusi: pindahkan registrasi
ke `register()` hook modul (pola Bagian 6b) — di situ service sudah ada dan
bus masih mutable.

### 9b. `LogicException: … CommandBus is frozen`

Kernel mem-freeze ketiga bus setelah semua modul selesai *register*.
Registrasi setelah `boot()` (misalnya di `boot()`/`start()` hook, atau di
middleware) menabrak freeze. Semua mapping CQRS = fase `register()`. Jika
Anda butuh *late binding*, daftarkan closure yang menunda resolusi hingga
*dispatch*:

```php
$bus->register(PlaceOrderCommand::class,
    fn (object $c, CqrsContext $ctx): mixed
        => $container->get('orders.command.place_order')($c, $ctx));
```

### 9c. `Class "Zef\Module\Orders\PlaceOrderCommandHandler" not found`

Handler tinggal di sub-namespace `Command\` (atau `Query\`), sedangkan
`ConfigProvider` berada di namespace `Zef\Module\Orders`. Tanpa baris `use`,
referensi `PlaceOrderCommandHandler` di factory closure di-resolve
*namespace-relative* ke kelas yang tidak ada. Tambahkan `use` persis seperti
contoh Bagian 6a — petunjuk keluaran `make:command`/`make:query` (v2.29.0)
sudah mencantumkannya.

---

## Checklist kelulusan

- [ ] `make:app` + `composer install` → `composer serve` → `curl /` mengembalikan JSON.
- [ ] `make:module Orders` terdaftar di `module:list`.
- [ ] Command dan query ter-wiring lewat service id + `register()` hook.
- [ ] `dispatch(new PlaceOrderCommand(...))` mengembalikan hasil handler.
- [ ] `bin/zef doctor` lulus tanpa FAIL.
- [ ] `vendor/bin/rr serve -c .rr.yaml` menjawab endpoint yang sama dengan dev server.

Setelah semua tercentang, Anda sudah punya pipeline CQRS hexagonal lengkap:
**HTTP → handler PSR-15 → bus → handler modul → container-managed
dependensi**, siap diperluas dengan event sourcing, outbox, atau job queue
yang tersedia di framework.
