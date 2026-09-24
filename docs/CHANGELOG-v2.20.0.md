# ZEF Framework — CHANGELOG v2.20.0

**Tema paket besar #3: OpenAPI 3.1 Documentation Module** — spesifikasi OpenAPI 3.1
valid dihasilkan otomatis dari route table + validasi engine + PHP 8.4 Attributes,
lengkap dengan serializer JSON/YAML, endpoint serving dengan ETag, CLI generator,
export Postman v2.1, dan validator struktural dokumen.

## Ringkasan

- **41 kelas/enum baru** dalam empat layer hexagonal:
  - `src/Domain/OpenApi/` (19): `OpenApiVersion`, `SchemaType`, `ParameterLocation`,
    `SecuritySchemeType`, `MediaType` (enum), `Contact`, `License`, `Info`, `Server`,
    `Tag`, `Schema`, `Parameter`, `RequestBody`, `Response`, `SecurityRequirement`,
    `SecurityScheme`, `Operation` (VO readonly), `OpenApiException`,
    `SpecificationException`, `SchemaDefinitionException`, kontrak
    `SpecificationBuilderInterface` + `SchemaDefinitionInterface`, dan 11 PHP 8.4
    Attributes (`#[OpenApi]`, `#[Route]`, `#[Schema]`, `#[Property]`, `#[Parameter]`,
    `#[RequestBody]`, `#[Response]`, `#[SecurityScheme]`, `#[Security]`, `#[Tag]`,
    `#[Deprecated]`) di `Attribute/`.
  - `src/Application/OpenApi/` (4): `SpecificationBuilder` (deterministik: paths
    terurut, metode kanonik, komponen terurut), `SchemaGenerator` (PHP types →
    schemas, DTO + attributes, backed/unit enums, union→oneOf, intersection→allOf,
    deteksi referensi melingkar → `$ref`, bridge Validator v2.8.0 → schema),
    `RouteSpecExtractor` (route table Router → Operations dengan enrichment
    attributes handler), `PostmanCollectionExporter` (dokumen OpenAPI → koleksi
    Postman v2.1 deterministik).
  - `src/Infrastructure/OpenApi/` (4): `JsonSpecificationSerializer` (stabil,
    UNESCAPED, roundtrip), `YamlSpecificationSerializer` (emitter YAML sendiri —
    dependency-free, quoting adversarial: numeric/boolean look-alikes, special
    chars, newline escape), `SpecificationCache` (di atas port Cache v2.9.0,
    payload rusak = miss), `OpenApiSpecValidator` (validasi struktural: semver,
    info, paths, responses, duplikasi operationId, resolvability `$ref`,
    invariants security scheme).
  - `src/Adapters/OpenApi/` (3): `Http/SpecHandler` (PSR-15, ETag sha256 +
    If-None-Match → 304), `Http/DocsUiHandler` (Swagger UI via CDN, escaping
    HTML, disable → 404), `Console/GenerateSpecCommand` (backend CLI).
- **128 tes baru** (7 file + fixture) — total suite 1.960 tes / 18.647 asersi.

## Fitur

### Generator spesifikasi (route table → OpenAPI 3.1)
- `bin/zef openapi:generate` — boot aplikasi, baca seluruh route table Router,
  konversi pola `{id:int}` → path template `/users/{id}`, dan turunkan schema
  parameter path dari constraint terdaftar (`int`/`uint` → integer,
  `uuid` → format uuid, `alpha`/`slug`/`hex` → pattern, constraint kustom →
  string tanpa batasan).
- operationId deterministik: nama route bila ada (divalidasi charset), else
  `method.handler.id` dengan sufiks anti-tabrakan; nama route tak-latin fallback
  otomatis.
- Tanpa atribut sekalipun, tiap operasi tetap terdokumentasi (default response
  200) — zero-config untuk kasus sederhana.

### Metadata deklaratif (full-control untuk kasus kompleks)
- `#[OpenApi]` pada kelas API meng-override Info dokumen; `#[SecurityScheme]`
  mendefinisikan skema keamanan; `#[Tag]` mengelompokkan; `#[Security]`
  (kelas = default seluruh operasi, method = override).
- Atribut method dibaca dari `handle()` (konvensi PSR-15) atau dari method
  publik mana pun yang `#[Route]`-nya cocok (controller multi-route);
  `#[Parameter]`, `#[RequestBody]` (maks. satu — duplikat = error jelas),
  `#[Response]` (repeatable, status duplikat = last-wins), `#[Deprecated]`.
- Resolusi handler service-id → class via container di composition root
  (CLI); resolver gagal = dokumen tetap dihasilkan tanpa enrichment.

### Schema generation dari kode
- `SchemaGenerator::generateFromClass()` — `SchemaDefinitionInterface` menang,
  lalu backed/unit enum (string/int/case-name → `enum:`), lalu refleksi
  `#[Schema]`/`#[Property]` + tipe native; union nullable collapse ke
  nullable base; union lain → `oneOf` (dedupe); intersection → `allOf`;
  referensi kelas → `$ref` komponen dengan deteksi siklus (tree DTO
  mereferensi dirinya aman).
- `generateFromValidator()` — bridge v2.8.0 validation engine: `typeInt` →
  integer, `email`/`uuid` → format, `in()` → enum, `pattern()` → pattern
  OpenAPI (delimiter PHP di-strip konservatif), `minLength`/`maxLength`/
  `min`/`max` → constraints, `required()` → daftar required, `nullable()` →
  nullable. Menambah readback additive `FieldRules::definitions()`.

### Serialisasi & distribusi
- JSON: `UNESCAPED_SLASHES|UNESCAPED_UNICODE`, pretty opsional, error
  encoding/desimal → `SpecificationException` (bukan data korup).
- YAML: emitter sendiri (symfony/yaml bukan dependensi runtime), indentasi
  2 spasi, list item terindentasi, quoting minimal-deterministik untuk
  look-alike (`null`/`true`/`123`/`1.50`/`yes`/`no`/`on`/`off`/`y`/`n`/`~`),
  karakter spesial awalan, `: `, ` #`, `"`, kontrol chars, multi-line
  double-quoted escape; batas kedalaman 512.
- Postman v2.1: folder per path, request per operasi, `:param` untuk path,
  query list, body raw JSON dari example/default/enum-precise, auth koleksi
  (bearer/basic/apiKey) dari securitySchemes.
- Serving: `SpecHandler` ETag (sha256 body) + `If-None-Match` → 304,
  `Cache-Control: public, max-age=300`; `DocsUiHandler` Swagger UI (dev-only,
  disabled → 404).

### Validasi dokumen
- `OpenApiSpecValidator::validate()` — daftar error deterministik: semver
  `openapi`, info wajib, path root `/`, metode dikenal, operationId unik,
  response berdeskripsi, parameter valid (path wajib required), `$ref`
  resolvable (walk depth-bounded), invariants security scheme per tipe.

## Keamanan
- Pattern schema dibatasi 2048 karakter (sejajar kebijakan ReDoS engine
  validasi); property name ≤ 128; operationId/`Tag` dibatasi charset/panjang.
- `DocsUiHandler` meng-escape `specUrl`/`title` (anti-XSS) dan dirancang
  dev-only; `SpecHandler` hanya membaca dokumen yang dibangun di memori.
- CLI menolak direktori output yang tidak ada dan gagal dengan pesan jelas
  (tanpa stacktrace) bila file tidak dapat ditulis.

## Kualitas
- PHPUnit 1.960 tes hijau; PHPStan level max + strict-rules **0 error**;
  PHPCS/PHP-CS-Fixer (PER-CS2.0 + Symfony + PHP84) bersih; Rector bersih;
  Deptrac 0 pelanggaran (semua layer baru mematuhi ruleset).
- Infection namespace OpenAPI: gate `--min-msi=85 --min-covered-msi=90`.
- Classmap zero-composer (`autoload/zef_autoload.php`) diperbarui otomatis
  (`scripts/dev/update_classmap.php`, sekarang juga memindai `tests/` dan
  `enum`).

## Catatan migrasi / kompatibilitas
- `ZefVersion::VERSION` → `2.20.0`.
- Additive di `Domain/Validation`: `FieldRules::definitions()` +
  metadata `params` per rule (perilaku `validate()` tidak berubah).
- Perbaikan classmap: entri EventSourcing yang sebelumnya hilang kini ikut
  terdaftar (perbaikan hygien; autoloader composer sebelumnya menutupi).
- Runtime validation middleware & security enforcement direncanakan pada
  paket berikutnya (lihat ROADMAP).
