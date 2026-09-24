<?php

declare(strict_types=1);

// ZEF Framework v2.21.0 — Configuration System v2 (Kernel integration):
// fail-fast boot, container singleton identity, secrets wiring, post-boot guards.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Application;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\EnvConfigSource;
use Zef\Framework\Config\FileSecretsProvider;

/**
 * @internal
 */
final class ConfigV2ApplicationTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-configv2app-' . uniqid();
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        $files = glob($this->workspace . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file); // nosemgrep: php.lang.security.unlink-use
            }
        }
        @rmdir($this->workspace . '/secrets');
        @rmdir($this->workspace);
    }

    public function testEmptyApplicationExposesEmptyConfigBag(): void
    {
        $app = new Application();
        $config = $app->config();
        self::assertInstanceOf(Config::class, $config);
        self::assertSame([], $config->all());
        self::assertFalse($config->has('anything'));
        self::assertSame('fallback', $config->get('anything', 'fallback'));
    }

    public function testConfigBagIsCachedAcrossCalls(): void
    {
        $app = new Application();
        self::assertSame($app->config(), $app->config());
    }

    public function testSourcesReadableBeforeBoot(): void
    {
        $app = new Application();
        $app->registerConfigSource(new ConfigV2ArraySource(['app' => ['name' => 'toko'], 'port' => '8080']));
        self::assertSame('toko', $app->config()->string('app.name'));
        self::assertSame(8080, $app->config()->int('port'));
    }

    public function testBootFailsFastWithFullViolationReport(): void
    {
        $app = new Application();
        $app->registerConfigSource(new ConfigV2ArraySource(['port' => 0, 'typo' => true]));
        $app->setConfigSchema(new ConfigSchema([
            new ConfigKey('port', ConfigValueType::Int, min: 1),
            new ConfigKey('mode', ConfigValueType::String, required: true),
        ], false));

        try {
            $app->boot();
            self::fail('Schema violations must fail the boot.');
        } catch (ConfigValidationException $e) {
            // Collect-all: bounds violation, missing required AND strict-mode
            // unknown key all reported in ONE boot attempt.
            self::assertCount(3, $e->violations());
            self::assertSame('port', $e->violations()[0]->key);
            self::assertSame('must be >= 1', $e->violations()[0]->message);
            self::assertSame('mode', $e->violations()[1]->key);
            self::assertSame('typo', $e->violations()[2]->key);
            self::assertSame('unknown configuration key', $e->violations()[2]->message);
        }
    }

    public function testBootRegistersConfigSingletonInContainer(): void
    {
        $app = new Application();
        $app->registerConfigSource(new ConfigV2ArraySource(['app' => ['name' => 'toko']]));
        $app->boot();
        $fromContainer = $app->getContainer()->get(Config::class);
        self::assertInstanceOf(Config::class, $fromContainer);
        self::assertSame($app->config(), $fromContainer, 'Config::class wajib singleton — identitas bag yang sama');
        self::assertSame('toko', $fromContainer->string('app.name'));
    }

    public function testSecretsResolveDuringBoot(): void
    {
        mkdir($this->workspace . '/secrets');
        file_put_contents($this->workspace . '/secrets/db_pass', ' s3cr3t ');
        $app = new Application();
        $app->registerConfigSource(new ConfigV2ArraySource(['db' => ['pass' => '%secret:db_pass%']]));
        $app->registerSecretsProvider(new FileSecretsProvider($this->workspace . '/secrets'));
        $app->setConfigSchema(new ConfigSchema([
            new ConfigKey('db.pass', ConfigValueType::String, required: true),
        ]));
        $app->boot();
        self::assertSame('s3cr3t', $app->config()->string('db.pass'));
    }

    public function testUnresolvableSecretFailsBoot(): void
    {
        $app = new Application();
        $app->registerConfigSource(new ConfigV2ArraySource(['db' => ['pass' => '%secret:nope%']]));
        $app->registerSecretsProvider(new FileSecretsProvider($this->workspace));
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("references unknown secret 'nope'");
        $app->boot();
    }

    public function testEnvironmentOverlayWinsDuringBoot(): void
    {
        $backup = $_ENV;
        $_ENV['ZEF_APP__NAME'] = 'from-env';

        try {
            $app = new Application();
            // Overlay sources are registered explicitly — later wins.
            $app->registerConfigSource(new ConfigV2ArraySource(['app' => ['name' => 'from-file']]));
            $app->registerConfigSource(new EnvConfigSource());
            $app->boot();
            self::assertSame('from-env', $app->config()->string('app.name'));
        } finally {
            $_ENV = $backup;
        }
    }

    public function testRegistrationApisAreGuardedAfterBoot(): void
    {
        $app = new Application();
        $app->boot();

        try {
            $app->registerConfigSource(new ConfigV2ArraySource([]));
            self::fail('Config sources must be rejected after boot.');
        } catch (\LogicException $e) {
            self::assertSame('Cannot add config source after boot.', $e->getMessage());
        }

        try {
            $app->registerSecretsProvider(new FileSecretsProvider($this->workspace));
            self::fail('Secrets providers must be rejected after boot.');
        } catch (\LogicException $e) {
            self::assertSame('Cannot register a secrets provider after boot.', $e->getMessage());
        }

        try {
            $app->setConfigSchema(new ConfigSchema([]));
            self::fail('Schemas must be rejected after boot.');
        } catch (\LogicException $e) {
            self::assertSame('Cannot set the config schema after boot.', $e->getMessage());
        }
    }
}
