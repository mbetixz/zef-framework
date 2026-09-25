# Dokumentasi API — OpenAPI 3.1 (v2.20.0)

Modul OpenAPI menghasilkan dokumen **OpenAPI 3.1** yang valid langsung dari route
table Router. Anda tidak perlu menulis spesifikasi secara manual: setiap rute
terdaftar otomatis menjadi operasi. Untuk kasus yang lebih kompleks, Anda
menambahkan metadata lewat PHP 8.4 Attributes pada kelas handler.

Gunakan modul ini bila Anda ingin:

- menerbitkan `/openapi.json` untuk klien, gateway, atau generator SDK;
- menyajikan Swagger UI selama pengembangan;
- mengekspor koleksi Postman v2.1 untuk QA;
- memvalidasi dokumen OpenAPI di pipeline CI.

Seluruh kelas berada di namespace `Zef\Framework\OpenApi` (attribute di
`Zef\Framework\OpenApi\Attribute`) dan tidak membutuhkan dependensi tambahan.
Serializer YAML ditulis sendiri, sehingga `symfony/yaml` tidak diperlukan.

---

## 1. Mulai cepat — zero-config

Tanpa satu attribute pun, perintah berikut sudah menghasilkan dokumen lengkap:

```bash
php bin/zef openapi:generate                     # → openapi.json
php bin/zef openapi:generate --format=yaml       # → openapi.yaml
```

Generator mem-boot aplikasi, membaca seluruh route table, lalu:

- mengonversi pola rute `{id:int}` menjadi path template `/users/{id}`;
- menurunkan schema parameter path dari constraint rute:

  | Constraint | Schema |
  |------------|--------|
  | `int` | `integer` |
  | `uint` | `integer`, `minimum: 1` |
  | `uuid` | `string`, `format: uuid` |
  | `alpha` / `slug` / `hex` | `string` + `pattern` |
  | constraint kustom | `string` tanpa batasan |

- membentuk `operationId` deterministik. Nama rute dipakai bila ada dan cocok
  dengan charset `[A-Za-z0-9._-]` (maks. 128 karakter). Selain itu, generator
  menurunkan `method.handler.id` dengan sufiks anti-tabrakan;
- memberi setiap operasi response default `200` bila tidak ada `#[Response]`.

Referensi lengkap opsi CLI ada di [`CLI.md`](CLI.md).

---

## 2. Metadata deklaratif — 11 attribute

Generator me-resolve service id handler menjadi nama kelas lewat container, lalu
membaca attribute pada kelas tersebut. Bila resolusi gagal, dokumen tetap
dihasilkan tanpa enrichment.

| Attribute | Target | Fungsi |
|-----------|--------|--------|
| `#[OpenApi]` | kelas | Override `info` dokumen: `title`, `version`, `description`, `servers`, `termsOfService`, `contact`, `license`. Override pertama yang ditemui menang |
| `#[SecurityScheme]` | kelas (repeatable) | Mendefinisikan skema keamanan di `components.securitySchemes` (`apiKey`, `http`, `oauth2`, `openIdConnect`, `mutualTLS`) |
| `#[Security]` | kelas / method (repeatable) | Kebutuhan keamanan. Di kelas menjadi default seluruh operasi. Di method meng-override default kelas |
| `#[Tag]` | kelas / method (repeatable) | Mengelompokkan operasi |
| `#[Route]` | method (repeatable) | `summary`, `description`, `tags`, `operationId`, `deprecated` untuk kombinasi `method` + `path` tertentu |
| `#[Parameter]` | method (repeatable) | Parameter `query`/`header`/`path`/`cookie` beserta tipe, format, contoh |
| `#[RequestBody]` | method | Body request (kelas schema + media type). Maksimal satu per operasi. Duplikat menghasilkan error |
| `#[Response]` | method (repeatable) | Response per status code. Status duplikat: yang terakhir menang |
| `#[Deprecated]` | kelas / method | Menandai operasi sebagai deprecated |
| `#[Schema]` | kelas DTO | Nama komponen schema, deskripsi, status deprecated |
| `#[Property]` | properti / parameter constructor | Tipe, format, constraint (`minLength`, `maximum`, `pattern`, `enum`, …), `readOnly`/`writeOnly`, `nullable`, contoh |

### Di mana attribute method dibaca

- Handler PSR-15: attribute dibaca dari method `handle()` (fallback `__invoke()`).
- Controller multi-rute: attribute dibaca dari method publik mana pun yang
  `#[Route]`-nya cocok dengan method HTTP dan path rute. Path kosong cocok dengan
  path apa pun.

### Contoh handler

```php
use Zef\Framework\OpenApi\Attribute as OA;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecuritySchemeType;

#[OA\OpenApi(title: 'Katalog API', version: '1.0.0')]
#[OA\SecurityScheme(name: 'bearer', type: SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT')]
#[OA\Security(scheme: 'bearer')]
#[OA\Tag(name: 'produk', description: 'Manajemen produk')]
final class ShowProductHandler implements RequestHandlerInterface
{
    #[OA\Route(method: 'GET', path: '/products/{id:int}', summary: 'Ambil satu produk')]
    #[OA\Parameter(name: 'include', in: ParameterLocation::Query, type: SchemaType::String)]
    #[OA\Response(status: 200, description: 'Produk ditemukan', schema: ProductDto::class)]
    #[OA\Response(status: 404, description: 'Produk tidak ada')]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // ...
    }
}
```

### Schema dari DTO

`SchemaGenerator` mengubah kelas PHP menjadi schema:

- kelas yang mengimplementasikan `SchemaDefinitionInterface` menentukan schema-nya sendiri;
- backed/unit enum menjadi `enum:`;
- properti bertipe native dibaca beserta `#[Schema]`/`#[Property]`;
- union nullable menjadi base type `nullable`, union lain menjadi `oneOf`, intersection menjadi `allOf`;
- referensi ke kelas lain menjadi `$ref` komponen, termasuk DTO yang mereferensi dirinya sendiri.

```php
use Zef\Framework\OpenApi\Attribute\Property;
use Zef\Framework\OpenApi\Attribute\Schema;
use Zef\Framework\OpenApi\SchemaType;

#[Schema(name: 'Product', description: 'Produk katalog')]
final readonly class ProductDto
{
    public function __construct(
        #[Property(type: SchemaType::Integer, readOnly: true)]
        public int $id,
        #[Property(minLength: 1, maxLength: 120)]
        public string $name,
        public ?string $sku = null,
    ) {}
}
```

`SchemaGenerator::generateFromValidator()` menjembatani engine validasi v2.8.0:
`typeInt` → `integer`, `email`/`uuid` → `format`, `in()` → `enum`, `pattern()` →
`pattern`, `minLength`/`maxLength`/`min`/`max` → constraint, `required()` →
daftar `required`, `nullable()` → `nullable`.

---

## 3. Menyajikan spesifikasi lewat HTTP

Modul menyediakan dua handler PSR-15. Keduanya tidak terdaftar otomatis, jadi Anda
mendaftarkannya sendiri di `ConfigProvider` modul.

### `SpecHandler` — `/openapi.json`

Menyajikan dokumen yang sudah dibangun sebagai JSON:

- `ETag` berisi sha256 dari body;
- request dengan `If-None-Match` yang cocok mendapat `304 Not Modified` tanpa body;
- response `200` menyertakan `Cache-Control: public, max-age=300`.

### `DocsUiHandler` — Swagger UI

Menyajikan halaman Swagger UI (aset dari CDN `unpkg.com`) yang mengarah ke URL
spesifikasi. `specUrl` dan `title` di-escape untuk mencegah XSS. Handler ini
dirancang untuk development/staging. Dengan `enabled: false`, handler mengembalikan
`404` (`Documentation UI is disabled.`).

```php
use Zef\Framework\OpenApi\Http\DocsUiHandler;
use Zef\Framework\OpenApi\Http\SpecHandler;

'services' => [
    'docs.handler.spec' => [
        'factory' => static fn (): SpecHandler => new SpecHandler($spec, pretty: true),
        'deps' => [],
    ],
    'docs.handler.ui' => [
        'factory' => static fn (): DocsUiHandler => new DocsUiHandler(
            specUrl: '/openapi.json',
            title: 'Katalog API',
            enabled: getenv('ZEF_ENV') !== 'production',
        ),
        'deps' => [],
    ],
],
'routes' => [
    ['method' => 'GET', 'path' => '/openapi.json', 'handler' => 'docs.handler.spec', 'priority' => 100],
    ['method' => 'GET', 'path' => '/docs', 'handler' => 'docs.handler.ui', 'priority' => 100],
],
```

`$spec` adalah array hasil `RouteSpecExtractor::extract($routes)->build()`. Untuk
menghindari membangun ulang di setiap boot, simpan hasilnya dengan
`SpecificationCache` (di atas port Cache v2.9.0; payload rusak diperlakukan sebagai
miss).

---

## 4. Export Postman v2.1

`PostmanCollectionExporter` mengubah dokumen OpenAPI menjadi koleksi Postman v2.1
yang deterministik:

- satu folder per path, satu request per operasi;
- parameter path sebagai `:param`, parameter query sebagai daftar query;
- body raw JSON dibangun dari `example`, `default`, atau `enum` pada schema;
- auth koleksi (`bearer`/`basic`/`apiKey`) diambil dari `securitySchemes`.

```bash
php bin/zef openapi:generate --postman=build/zef.postman.json
```

---

## 5. Validasi dokumen

`OpenApiSpecValidator::validate()` mengembalikan daftar pesan error yang
deterministik. Daftar kosong berarti dokumen valid. Pemeriksaan mencakup:

- `openapi` berupa string semver, `info.title` dan `info.version` wajib;
- setiap path diawali `/` dan hanya memakai metode HTTP yang dikenal;
- `operationId` unik dan setiap response memiliki deskripsi;
- parameter valid (parameter `path` wajib `required`);
- setiap `$ref` dapat di-resolve;
- invariant per tipe security scheme.

```php
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\OpenApiSpecValidator;

$spec = (new JsonSpecificationSerializer())->deserialize(file_get_contents('openapi.json'));
$errors = (new OpenApiSpecValidator())->validate($spec);

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
```

---

## 6. Batasan keamanan

- `pattern` schema dibatasi 2048 karakter (selaras kebijakan ReDoS engine validasi).
- Nama properti maksimal 128 karakter. `operationId` dan nama `Tag` dibatasi
  charset dan panjangnya.
- `DocsUiHandler` memuat aset eksternal. Nonaktifkan di produksi.
