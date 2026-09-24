<?php

declare(strict_types=1);

// ZEF Framework v2.21.1 — Configuration System v2 review hardening:
// empty-string rejection hints, the resilient secrets decorator and the
// compiled-config security guarantees (permission mask + warning header).

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigCompiler;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSchemaValidator;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\ResilientSecretsProvider;
use Zef\Framework\Config\SecretsProviderInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * @internal
 */
final class ConfigV2HardeningTest extends TestCase
{
    // ---- ConfigCompiler security ----------------------------------------------

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-configv2harden-' . uniqid();
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
        @rmdir($this->workspace);
    }
    // ---- Empty-string rejection hint -----------------------------------------

    public function testValidatorHintsOnEmptyStringForNonStringKeys(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('k.string', ConfigValueType::String),
            new ConfigKey('k.int', ConfigValueType::Int),
            new ConfigKey('k.float', ConfigValueType::Float),
            new ConfigKey('k.bool', ConfigValueType::Bool),
            new ConfigKey('k.array', ConfigValueType::Array),
        ]);
        $violations = new ConfigSchemaValidator()->validate([
            'k' => ['string' => '', 'int' => '', 'float' => '', 'bool' => '', 'array' => ''],
        ], $schema);
        self::assertCount(4, $violations);
        $hintedKeys = [];
        foreach ($violations as $violation) {
            self::assertStringContainsString(
                ' (empty strings only satisfy string keys; remove the empty'
                . ' environment variable or set a concrete value)',
                $violation->message,
                "violation for '{$violation->key}' must carry the empty-string hint",
            );
            $hintedKeys[] = $violation->key;
        }
        self::assertSame(['k.int', 'k.float', 'k.bool', 'k.array'], $hintedKeys, 'violations follow schema declaration order');
    }

    public function testValidatorDoesNotHintOnNonEmptyValues(): void
    {
        $schema = new ConfigSchema([new ConfigKey('k.int', ConfigValueType::Int)]);
        $violations = new ConfigSchemaValidator()->validate(['k' => ['int' => '12abc']], $schema);
        self::assertCount(1, $violations);
        self::assertSame("expects int, got string('12abc')", $violations[0]->message);
    }

    public function testRuntimeAccessorHintsOnEmptyString(): void
    {
        $config = new Config(['db' => ['port' => '']]);

        try {
            $config->int('db.port');
            self::fail('Empty string must not satisfy an int key.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Configuration key 'db.port' expects int, got string('')"
                . ' (empty strings only satisfy string keys; remove the empty'
                . ' environment variable or set a concrete value)',
                $e->getMessage(),
            );
        }
    }

    public function testStringAccessorAcceptsEmptyStringWithoutHint(): void
    {
        $config = new Config(['app' => ['name' => '']]);
        self::assertSame('', $config->string('app.name'));
    }

    public function testRejectionHintIsDeterministic(): void
    {
        self::assertSame('', ConfigValueType::Int->rejectionHint('12abc'));
        self::assertSame('', ConfigValueType::String->rejectionHint(''));
        self::assertSame(
            ' (empty strings only satisfy string keys; remove the empty'
            . ' environment variable or set a concrete value)',
            ConfigValueType::Enum->rejectionHint(''),
        );
    }

    // ---- ResilientSecretsProvider ---------------------------------------------

    public function testPassesThroughSuccessAndCachesIt(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public int $calls = 0;

            #[\Override]
            public function get(string $key): string
            {
                ++$this->calls;

                return 'value-' . $key;
            }
        };
        $provider = new ResilientSecretsProvider($inner);
        self::assertSame('value-db', $provider->get('db'));
        self::assertSame('value-db', $provider->get('db'));
        self::assertSame(2, $inner->calls, 'cache must not short-circuit the inner provider');
    }

    public function testUnknownKeyNullPassesThroughUncached(): void
    {
        $provider = new ResilientSecretsProvider(new class implements SecretsProviderInterface {
            #[\Override]
            public function get(string $key): ?string
            {
                return null;
            }
        });
        self::assertNull($provider->get('nope'));
        self::assertNull($provider->get('nope'));
    }

    public function testRetriesWithExponentialBackoffThenSucceeds(): void
    {
        $sleeps = [];
        $retries = [];
        $inner = new class implements SecretsProviderInterface {
            public static int $attempts = 0;

            #[\Override]
            public function get(string $key): string
            {
                if (self::$attempts < 2) {
                    ++self::$attempts;

                    throw new \RuntimeException('flaky ' . self::$attempts);
                }

                return 'recovered';
            }
        };
        $provider = new ResilientSecretsProvider(
            $inner,
            maxAttempts: 3,
            backoffSeconds: 0.01,
            sleeper: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            onRetry: static function (string $key, int $attempt, \Throwable $e) use (&$retries): void {
                $retries[] = [$key, $attempt, $e->getMessage()];
            },
        );
        self::assertSame('recovered', $provider->get('db'));
        self::assertSame([0.01, 0.02], $sleeps, 'backoff must double per attempt');
        self::assertSame(
            [
                ['db', 1, 'flaky 1'],
                ['db', 2, 'flaky 2'],
            ],
            $retries,
        );
    }

    public function testExhaustedRetriesRethrowWithoutCache(): void
    {
        $sleeps = [];
        $provider = new ResilientSecretsProvider(
            new class implements SecretsProviderInterface {
                #[\Override]
                public function get(string $key): ?string
                {
                    throw new \RuntimeException('provider unavailable');
                }
            },
            maxAttempts: 3,
            backoffSeconds: 0.01,
            sleeper: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        try {
            $provider->get('db');
            self::fail('Exhausted retries must rethrow.');
        } catch (\RuntimeException $e) {
            self::assertSame('provider unavailable', $e->getMessage());
        }
        self::assertSame([0.01, 0.02], $sleeps);
    }

    public function testStaleFallbackAfterSuccessThenOutage(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public static bool $fail = false;

            #[\Override]
            public function get(string $key): string
            {
                if (self::$fail) {
                    throw new \RuntimeException('outage');
                }

                return 'known-good';
            }
        };
        $provider = new ResilientSecretsProvider($inner, maxAttempts: 2, backoffSeconds: 0.0);
        self::assertSame('known-good', $provider->get('db'));
        $inner::$fail = true;
        self::assertSame('known-good', $provider->get('db'), 'stale value must ride out the outage');
    }

    public function testStaleFallbackDisabledRethrows(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public int $calls = 0;

            #[\Override]
            public function get(string $key): string
            {
                ++$this->calls;

                return $this->calls === 1 ? 'first' : throw new \RuntimeException('down');
            }
        };
        $provider = new ResilientSecretsProvider($inner, maxAttempts: 1, preferStaleOnFailure: false);
        self::assertSame('first', $provider->get('db'));

        try {
            $provider->get('db');
            self::fail('Stale fallback disabled must rethrow.');
        } catch (\RuntimeException $e) {
            self::assertSame('down', $e->getMessage());
        }
    }

    public function testConstructorRejectsInvalidPolicy(): void
    {
        $inner = new class implements SecretsProviderInterface {
            #[\Override]
            public function get(string $key): ?string
            {
                return null;
            }
        };

        try {
            new ResilientSecretsProvider($inner, maxAttempts: 0);
            self::fail('maxAttempts 0 must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Secrets retry policy needs at least one attempt.', $e->getMessage());
        }

        try {
            new ResilientSecretsProvider($inner, backoffSeconds: -0.5);
            self::fail('Negative backoff must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Secrets backoff must be a non-negative number of seconds.', $e->getMessage());
        }
    }

    public function testBackoffCapAtThirtySeconds(): void
    {
        $sleeps = [];
        $provider = new ResilientSecretsProvider(
            new class implements SecretsProviderInterface {
                #[\Override]
                public function get(string $key): ?string
                {
                    throw new \RuntimeException('down');
                }
            },
            maxAttempts: 3,
            backoffSeconds: 1_000_000.0,
            sleeper: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        try {
            $provider->get('db');
            self::fail('Expected rethrow.');
        } catch (\RuntimeException) {
        }
        self::assertSame([30.0, 30.0], $sleeps, 'backoff must cap at 30 seconds');
    }

    public function testDefaultSleeperUsesUsleepForPositiveDelay(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public int $calls = 0;

            #[\Override]
            public function get(string $key): string
            {
                if ($this->calls === 0) {
                    ++$this->calls;

                    throw new \RuntimeException('once');
                }

                return 'ok';
            }
        };
        $provider = new ResilientSecretsProvider($inner, maxAttempts: 2, backoffSeconds: 0.001);
        self::assertSame('ok', $provider->get('db'));
    }

    public function testDefaultPolicyRetriesExactlyThreeTimes(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public static int $calls = 0;

            #[\Override]
            public function get(string $key): ?string
            {
                ++self::$calls;

                throw new \RuntimeException('always');
            }
        };
        $provider = new ResilientSecretsProvider($inner, backoffSeconds: 0.0);
        try {
            $provider->get('db');
            self::fail('Expected rethrow after default retry budget.');
        } catch (\RuntimeException) {
        }
        self::assertSame(3, $inner::$calls, 'default maxAttempts must be exactly 3');
    }

    public function testDefaultPolicyPrefersStaleOnFailure(): void
    {
        $inner = new class implements SecretsProviderInterface {
            public static bool $fail = false;

            #[\Override]
            public function get(string $key): ?string
            {
                if (self::$fail) {
                    throw new \RuntimeException('outage');
                }

                return 'known-good';
            }
        };
        $provider = new ResilientSecretsProvider($inner, maxAttempts: 1, backoffSeconds: 0.0);
        self::assertSame('known-good', $provider->get('db'));
        $inner::$fail = true;
        self::assertSame('known-good', $provider->get('db'), 'default policy must prefer stale values');
    }

    public function testCompiledFileGetsRestrictiveDefaultMode(): void
    {
        $target = $this->workspace . '/compiled.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        self::assertSame('0600', substr(sprintf('%o', (int) fileperms($target)), -4));
    }

    public function testCompiledFileHonorsCustomMode(): void
    {
        $target = $this->workspace . '/compiled.php';
        new ConfigCompiler(0o660)->export(new Config(['a' => 1]), $target);
        self::assertSame('0660', substr(sprintf('%o', (int) fileperms($target)), -4));
    }

    public function testCompilerRejectsInvalidModes(): void
    {
        foreach ([0o7777, -1] as $mode) {
            try {
                new ConfigCompiler($mode);
                self::fail("Mode {$mode} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    'Config compile file mode must be a permission mask between 0 and 0777, got ' . $mode . '.',
                    $e->getMessage(),
                );
            }
        }
    }

    public function testCompiledHeaderCarriesVersionAndSecurityNotice(): void
    {
        $target = $this->workspace . '/compiled.php';
        new ConfigCompiler()->export(new Config(['a' => 1]), $target);
        $content = (string) file_get_contents($target);
        self::assertStringContainsString(
            '/* Compiled application configuration (ZEF Framework v' . ZefVersion::VERSION . ').',
            $content,
        );
        self::assertStringContainsString(
            'Contains resolved secrets — keep out of version control, chmod 600. */',
            $content,
        );
    }

    public function testCompilerAcceptsBoundaryModes(): void
    {
        $target = $this->workspace . '/open.php';
        new ConfigCompiler(0)->export(new Config(['a' => 1]), $target);
        new ConfigCompiler(0o777)->export(new Config(['a' => 1]), $target);
        self::assertSame('0777', substr(sprintf('%o', (int) fileperms($target)), -4));
    }

    public function testCompilerRejectsUnwritableDirectory(): void
    {
        $roDir = $this->workspace . '/ro';
        mkdir($roDir);
        chmod($roDir, 0o500);
        try {
            new ConfigCompiler()->export(new Config(['a' => 1]), $roDir . '/compiled.php');
            self::fail('Unwritable directory must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Config compile target directory is not writable: '{$roDir}'.",
                $e->getMessage(),
            );
        } finally {
            chmod($roDir, 0o755);
        }
    }

    public function testCompilerDetectsUnexportableAfterExportableArray(): void
    {
        $target = $this->workspace . '/compiled.php';
        try {
            new ConfigCompiler()->export(new Config(['ok' => ['b' => 1], 'bad' => new \stdClass()]), $target);
            self::fail('Unexportable value after an exportable array must still be detected.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Config value at 'bad' cannot be compiled (only scalars, nulls and arrays are exportable).",
                $e->getMessage(),
            );
        }
    }
}
