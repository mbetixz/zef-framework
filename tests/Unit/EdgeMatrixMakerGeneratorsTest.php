<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\Generator\CommandGenerator;
use Zef\Framework\Console\Generator\ConfigGenerator;
use Zef\Framework\Console\Generator\EntityGenerator;
use Zef\Framework\Console\Generator\HandlerGenerator;
use Zef\Framework\Console\Generator\MiddlewareGenerator;
use Zef\Framework\Console\Generator\ModuleGenerator;
use Zef\Framework\Console\Generator\PluginGenerator;
use Zef\Framework\Console\Generator\QueryGenerator;
use Zef\Framework\Console\Generator\ServiceGenerator;
use Zef\Framework\Console\Generator\ValueObjectGenerator;
use Zef\Framework\Console\InvalidNameException;
use Zef\Framework\Console\ScaffoldWriter;

/**
 * v2.16.0 — ZEF Maker: kurikulum edge-case adversarial untuk 10 generator.
 * Setiap generator diuji di sandbox root terpisah; konten stub diverifikasi
 * sampai level token (namespace, service id, kontrak interface) supaya
 * mutan string/concat heredoc tidak bisa lolos.
 *
 * @internal
 */
final class EdgeMatrixMakerGeneratorsTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rmRecursive($root);
        }
        $this->roots = [];
    }

    // ------------------------------------------------------------------
    // ModuleGenerator
    // ------------------------------------------------------------------

    /** ModuleGenerator — stub provider+handler memuat token kontrak eksak. */
    public function testModuleScaffoldCreatesProviderAndHandler(): void
    {
        $root = $this->sandbox(false);
        $io = $this->io();
        $code = new ModuleGenerator($root, $io, $this->writer($io))->generate('payment');
        self::assertSame(0, $code);
        $provider = $this->read($root . '/modules/Payment/ConfigProvider.php');
        self::assertStringContainsString('namespace Zef\Module\Payment;', $provider);
        self::assertStringContainsString("return 'payment';", $provider);
        self::assertStringContainsString("'payment.handler.home'", $provider);
        self::assertStringContainsString("'path' => '/payment'", $provider);
        self::assertStringContainsString("'name' => 'payment.home'", $provider);
        $handler = $this->read($root . '/modules/Payment/HomeHandler.php');
        self::assertStringContainsString('implements RequestHandlerInterface', $handler);
        self::assertStringContainsString("'module' => 'payment'", $handler);
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString('Register the module in src/Bootstrap.php', $guidance);
        self::assertStringContainsString('addProvider(new PaymentConfigProvider())', $guidance);
    }

    /** ModuleGenerator — direktori eksisting ditolak (exit 1, tanpa tulis ulang). */
    public function testModuleScaffoldRefusesExistingDirectory(): void
    {
        $root = $this->sandbox(false);
        $io = $this->io();
        $gen = new ModuleGenerator($root, $io, $this->writer($io));
        self::assertSame(0, $gen->generate('payment'));
        self::assertTrue(mkdir($root . '/modules/Other', 0o777, true));
        self::assertSame(1, $gen->generate('payment'));
        self::assertStringContainsString('Module directory already exists', $io->errLog()[0]);
    }

    /** ModuleGenerator — nama invalid melempar exception (bukan exit code). */
    public function testModuleScaffoldRejectsInvalidName(): void
    {
        $root = $this->sandbox(false);
        $gen = new ModuleGenerator($root, $this->io(), $this->writer($this->io()));
        $this->expectException(InvalidNameException::class);
        $gen->generate('9bad');
    }

    // ------------------------------------------------------------------
    // HandlerGenerator (+ resolveModuleDir)
    // ------------------------------------------------------------------

    /** HandlerGenerator — service id snake + route kebab dari nama kelas. */
    public function testHandlerScaffoldDerivesServiceIdAndRoute(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $code = new HandlerGenerator($root, $io, $this->writer($io))->generate('HandleOrder');
        self::assertSame(0, $code);
        $content = $this->read($root . '/modules/Core/HandleOrderHandler.php');
        self::assertStringContainsString('namespace Zef\Module\Core;', $content);
        self::assertStringContainsString('final class HandleOrderHandler implements RequestHandlerInterface', $content);
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString("'core.handler.handle_order'", $guidance);
        self::assertStringContainsString("'path' => '/core/handle-order'", $guidance);
    }

    /** HandlerGenerator — --module case-insensitive ke dir asli; --path menang atas default. */
    public function testHandlerRespectsModuleAndPathFlags(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $code = new HandlerGenerator($root, $io, $this->writer($io))->generate('HandleOrder', [
            'ignored', '--module=TOKO', '--path=/orders',
        ]);
        self::assertSame(0, $code);
        $this->read($root . '/modules/Toko/HandleOrderHandler.php');
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString("'toko.handler.handle_order'", $guidance, 'service id pakai nilai flag');
        self::assertStringContainsString("'path' => '/orders'", $guidance, '--path menang atas route default');
    }

    /** HandlerGenerator — modul tak ada: exit 1 + pesan arahan; nama modul invalid: exception. */
    public function testHandlerFailsForUnknownOrInvalidModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $gen = new HandlerGenerator($root, $io, $this->writer($io));
        self::assertSame(1, $gen->generate('HandleOrder', ['--module=ghost']));
        self::assertStringContainsString("Module 'ghost' does not exist", $io->errLog()[0]);
        self::assertStringContainsString('make:module', $io->errLog()[0]);

        $this->expectException(InvalidNameException::class);
        $gen->generate('HandleOrder', ['--module=9bad']);
    }

    /** HandlerGenerator::resolveModuleDir — kontrak statis: cocok case-insensitive, null jika tiada. */
    public function testResolveModuleDirContract(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/MixedCase', 0o777, true);
        self::assertSame($root . '/modules/MixedCase', HandlerGenerator::resolveModuleDir($root, 'mixedcase'));
        self::assertSame($root . '/modules/MixedCase', HandlerGenerator::resolveModuleDir($root, 'MIXEDCASE'));
        self::assertNull(HandlerGenerator::resolveModuleDir($root, 'missing'));
        rmdir($root . '/modules/MixedCase');
        rmdir($root . '/modules');
        self::assertNull(HandlerGenerator::resolveModuleDir($root, 'core'), 'modules/ hilang → null, bukan error');
    }

    // ------------------------------------------------------------------
    // MiddlewareGenerator
    // ------------------------------------------------------------------

    /** MiddlewareGenerator — v2.16.0: target src/Middleware (bukan app/ lagi). */
    public function testMiddlewareScaffoldGoesToSrcMiddleware(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $code = new MiddlewareGenerator($root, $io, $this->writer($io))->generate('Compression');
        self::assertSame(0, $code);
        $content = $this->read($root . '/src/Middleware/CompressionMiddleware.php');
        self::assertStringContainsString('namespace Zef\Middleware;', $content);
        self::assertStringContainsString('final class CompressionMiddleware implements MiddlewareInterface', $content);
        self::assertStringContainsString("'middleware.compression'", implode("\n", $io->outLog()));
        self::assertFileDoesNotExist($root . '/app/Middleware/CompressionMiddleware.php');
    }

    /** MiddlewareGenerator — nama invalid ditolak sebelum menyentuh disk. */
    public function testMiddlewareRejectsInvalidName(): void
    {
        $root = $this->sandbox();
        $gen = new MiddlewareGenerator($root, $this->io(), $this->writer($this->io()));
        $this->expectException(InvalidNameException::class);
        $gen->generate('');
    }

    // ------------------------------------------------------------------
    // PluginGenerator
    // ------------------------------------------------------------------

    /** PluginGenerator — 3 file, kontrak provider, singleton service, handler DI. */
    public function testPluginScaffoldCreatesFullPlugin(): void
    {
        $root = $this->sandbox(false);
        $io = $this->io();
        $code = new PluginGenerator($root, $io, $this->writer($io))->generate('Catalog');
        self::assertSame(0, $code);
        $base = $root . '/plugins/Catalog';
        $provider = $this->read($base . '/ConfigProvider.php');
        self::assertStringContainsString('namespace Zef\Plugin\Catalog;', $provider);
        self::assertStringContainsString("return 'catalog';", $provider);
        self::assertStringContainsString("'catalog.service.catalog'", $provider);
        self::assertStringContainsString('ServiceLifetime::SINGLETON', $provider);
        self::assertStringContainsString("'deps'    => ['catalog.service.catalog']", $provider);
        self::assertStringContainsString("'path' => '/catalog'", $provider);
        self::assertStringContainsString('new CatalogHandler($svc)', $provider, 'factory handler menyuntik service');
        self::assertStringContainsString('describe()', $this->read($base . '/CatalogService.php'));
        $handler = $this->read($base . '/CatalogHandler.php');
        self::assertStringContainsString('private readonly CatalogService $service', $handler);
        self::assertStringContainsString('$this->service->describe()', $handler);
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString('Register the plugin in src/Bootstrap.php', $guidance);
        self::assertStringContainsString('addProvider(new CatalogConfigProvider())', $guidance);
    }

    /** PluginGenerator — nama Pascal dari input camel/kebab konsisten (kebab untuk module name). */
    public function testPluginScaffoldDerivesKebabModuleName(): void
    {
        $root = $this->sandbox(false);
        $io = $this->io();
        self::assertSame(0, new PluginGenerator($root, $io, $this->writer($io))->generate('FastCart'));
        self::assertStringContainsString("return 'fast-cart';", $this->read($root . '/plugins/FastCart/ConfigProvider.php'));
    }

    /** PluginGenerator — direktori eksisting ditolak. */
    public function testPluginScaffoldRefusesExistingDirectory(): void
    {
        $root = $this->sandbox(false);
        $io = $this->io();
        $gen = new PluginGenerator($root, $io, $this->writer($io));
        self::assertSame(0, $gen->generate('Catalog'));
        self::assertSame(1, $gen->generate('Catalog'));
        self::assertStringContainsString('Plugin directory already exists', $io->errLog()[0]);
    }

    // ------------------------------------------------------------------
    // ConfigGenerator
    // ------------------------------------------------------------------

    /** ConfigGenerator — provider config di dalam modul; kunci teragregasi berlapis. */
    public function testConfigScaffoldInsideModule(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Health', 0o777, true);
        $io = $this->io();
        $code = new ConfigGenerator($root, $io, $this->writer($io))->generate('Payment', ['--module=health']);
        self::assertSame(0, $code);
        $content = $this->read($root . '/modules/Health/PaymentConfigProvider.php');
        self::assertStringContainsString('namespace Zef\Module\Health;', $content);
        self::assertStringContainsString('implements ConfigProviderInterface', $content);
        self::assertStringContainsString("'health' => [", $content);
        self::assertStringContainsString("'payment' => [", $content);
        self::assertStringContainsString("\$aggregator->get('health.payment')", implode("\n", $io->outLog()), 'next steps menunjukkan akses dotted');
    }

    /** ConfigGenerator — default modul core; modul tak dikenal ditolak. */
    public function testConfigDefaultsToCoreAndRejectsUnknown(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        self::assertSame(0, new ConfigGenerator($root, $io, $this->writer($io))->generate('Payment'));
        self::assertFileExists($root . '/modules/Core/PaymentConfigProvider.php');
        self::assertSame(1, new ConfigGenerator($root, $io, $this->writer($io))->generate('Other', ['--module=ghost']));
        self::assertStringContainsString("Module 'ghost' does not exist", $io->errLog()[0]);
    }

    // ------------------------------------------------------------------
    // CommandGenerator / QueryGenerator
    // ------------------------------------------------------------------

    /** CommandGenerator — pasangan Command+Handler di subdir Command/, kontrak interface. */
    public function testCommandScaffoldCreatesCommandAndHandler(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $code = new CommandGenerator($root, $io, $this->writer($io))->generate('PlaceOrder', ['--module=toko']);
        self::assertSame(0, $code);
        $command = $this->read($root . '/modules/Toko/Command/PlaceOrderCommand.php');
        self::assertStringContainsString('namespace Zef\Module\Toko\Command;', $command);
        self::assertStringContainsString('final class PlaceOrderCommand', $command);
        self::assertStringContainsString('public readonly string $id', $command);
        self::assertStringContainsString("'toko.command.place_order'", $command, 'service id tercantum di docblock command');
        $handler = $this->read($root . '/modules/Toko/Command/PlaceOrderCommandHandler.php');
        self::assertStringContainsString('implements CommandHandlerInterface', $handler);
        self::assertStringContainsString('public function __invoke(object $command, CqrsContext $context): mixed', $handler);
        self::assertStringContainsString('getCommandBus()->dispatch', $io->outLog()[count($io->outLog()) - 1]);
    }

    /** CommandGenerator — modul tak dikenal ditolak sebelum menulis file. */
    public function testCommandRejectsUnknownModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        self::assertSame(1, new CommandGenerator($root, $io, $this->writer($io))->generate('PlaceOrder', ['--module=ghost']));
        self::assertSame("Module 'ghost' does not exist (modules/ghost). Create it first with make:module.", $io->errLog()[0]);
        self::assertFileDoesNotExist($root . '/modules/ghost');
    }

    /** QueryGenerator — pasangan Query+Handler di subdir Query/, kontrak interface. */
    public function testQueryScaffoldCreatesQueryAndHandler(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $code = new QueryGenerator($root, $io, $this->writer($io))->generate('FindOrder', ['--module=toko']);
        self::assertSame(0, $code);
        $query = $this->read($root . '/modules/Toko/Query/FindOrderQuery.php');
        self::assertStringContainsString('namespace Zef\Module\Toko\Query;', $query);
        self::assertStringContainsString('final class FindOrderQuery', $query);
        self::assertStringContainsString("'toko.query.find_order'", $query, 'service id tercantum di docblock query');
        $handler = $this->read($root . '/modules/Toko/Query/FindOrderQueryHandler.php');
        self::assertStringContainsString('implements QueryHandlerInterface', $handler);
        self::assertStringContainsString('getQueryBus()->ask', $io->outLog()[count($io->outLog()) - 1]);
    }

    /** QueryGenerator — modul tak dikenal: exit 1 + pesan di stderr. */
    public function testQueryRejectsUnknownModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $gen = new QueryGenerator($root, $io, $this->writer($io));
        self::assertSame(1, $gen->generate('FindOrder', ['--module=ghost']));
        self::assertSame("Module 'ghost' does not exist (modules/ghost). Create it first with make:module.", $io->errLog()[0]);
    }

    // ------------------------------------------------------------------
    // EntityGenerator / ValueObjectGenerator
    // ------------------------------------------------------------------

    /** EntityGenerator — entitas Domain/ dengan identity-based equals(). */
    public function testEntityScaffoldCreatesIdentityEntity(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $code = new EntityGenerator($root, $io, $this->writer($io))->generate('Invoice', ['--module=toko']);
        self::assertSame(0, $code);
        $content = $this->read($root . '/modules/Toko/Domain/Invoice.php');
        self::assertStringContainsString('namespace Zef\Module\Toko\Domain;', $content);
        self::assertStringContainsString('final class Invoice', $content);
        self::assertStringContainsString('private readonly string $id', $content);
        self::assertStringContainsString('?\DateTimeImmutable $createdAt = null', $content);
        self::assertStringContainsString('$this->id === $other->id', $content);
        self::assertStringContainsString('public function equals(self $other): bool', $content);
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString('Entities carry behaviour', $guidance);
        self::assertStringContainsString('repository port', $guidance);
    }

    /** EntityGenerator — modul tak dikenal ditolak. */
    public function testEntityRejectsUnknownModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        self::assertSame(1, new EntityGenerator($root, $io, $this->writer($io))->generate('Invoice', ['--module=ghost']));
        self::assertStringContainsString("Module 'ghost' does not exist", $io->errLog()[0]);
    }

    /** ValueObjectGenerator — readonly VO dengan validasi constructor + toString. */
    public function testValueObjectScaffoldCreatesValidatedVo(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $code = new ValueObjectGenerator($root, $io, $this->writer($io))->generate('Email', ['--module=toko']);
        self::assertSame(0, $code);
        $content = $this->read($root . '/modules/Toko/Domain/Email.php');
        self::assertStringContainsString('namespace Zef\Module\Toko\Domain;', $content, 'namespace wajib memuat nama modul');
        self::assertStringContainsString('final readonly class Email', $content);
        self::assertStringContainsString("throw new \\InvalidArgumentException('Email value must not be empty.')", $content);
        self::assertStringContainsString('public function equals(self $other): bool', $content);
        self::assertStringContainsString('public function __toString(): string', $content);
        $guidance = implode("\n", $io->outLog());
        self::assertStringContainsString('Value objects are immutable', $guidance);
        self::assertStringContainsString('`withX()` clones', $guidance);
    }

    /** ValueObjectGenerator — modul tak dikenal: exit 1 + pesan di stderr. */
    public function testValueObjectRejectsUnknownModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $gen = new ValueObjectGenerator($root, $io, $this->writer($io));
        self::assertSame(1, $gen->generate('Email', ['--module=ghost']));
        self::assertSame("Module 'ghost' does not exist (modules/ghost). Create it first with make:module.", $io->errLog()[0]);
    }

    // ------------------------------------------------------------------
    // ServiceGenerator
    // ------------------------------------------------------------------

    /** ServiceGenerator — suffix otomatis TANPA double; wiring snippet sesuai module. */
    public function testServiceScaffoldSuffixAndWiring(): void
    {
        $root = $this->sandbox(false);
        mkdir($root . '/modules/Toko', 0o777, true);
        $io = $this->io();
        $gen = new ServiceGenerator($root, $io, $this->writer($io));
        self::assertSame(0, $gen->generate('Inventory', ['--module=toko']));
        self::assertFileExists($root . '/modules/Toko/InventoryService.php');
        self::assertSame(0, $gen->generate('AuditService', ['--module=toko']));
        self::assertFileExists($root . '/modules/Toko/AuditService.php', 'nama sudah ber-Suffix tidak didobel');
        self::assertFileDoesNotExist($root . '/modules/Toko/AuditServiceService.php');
        $wiring = implode("\n", $io->outLog());
        self::assertStringContainsString("'toko.service.inventory'", $wiring);
    }

    /** ServiceGenerator — modul tak dikenal: exit 1 + pesan di stderr. */
    public function testServiceRejectsUnknownModule(): void
    {
        $root = $this->sandbox();
        $io = $this->io();
        $gen = new ServiceGenerator($root, $io, $this->writer($io));
        self::assertSame(1, $gen->generate('Inventory', ['--module=ghost']));
        self::assertSame("Module 'ghost' does not exist (modules/ghost). Create it first with make:module.", $io->errLog()[0]);
    }

    private function io(): ConsoleIO
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        return new ConsoleIO($out, $err);
    }

    /** Sandbox dengan modul siap pakai (default: Core). */
    private function sandbox(bool $withCore = true): string
    {
        $root = sys_get_temp_dir() . '/zef-gen-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($root, 0o777, true));
        if ($withCore) {
            self::assertTrue(mkdir($root . '/modules/Core', 0o777, true));
        }
        $this->roots[] = $root;

        return $root;
    }

    private function rmRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rmRecursive($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }

    private function writer(ConsoleIO $io): ScaffoldWriter
    {
        return new ScaffoldWriter($io);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        return $content;
    }
}
