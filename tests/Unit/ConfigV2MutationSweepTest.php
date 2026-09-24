<?php

declare(strict_types=1);

// ZEF Framework v2.21.1 — Configuration System v2 mutation sweep: exact
// messages, boundaries, deterministic ordering and compiled-file pinning.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\CompiledConfigSource;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigCompiler;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSchemaValidator;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\ConfigViolation;
use Zef\Framework\Config\DottedPaths;
use Zef\Framework\Config\EnvConfigSource;
use Zef\Framework\Config\FileSecretsProvider;
use Zef\Framework\Config\PhpFileConfigSource;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * @internal
 */
final class ConfigV2MutationSweepTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-configv2sweep-' . uniqid();
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        $files = glob($this->workspace . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    @rmdir($file);

                    continue;
                }
                @unlink($file); // nosemgrep: php.lang.security.unlink-use
            }
        }
        @rmdir($this->workspace);
    }

    // ---- ConfigCompiler: pin the compiled file wire format ------------------

    public function testCompiledFileHasPinnedHeader(): void
    {
        $config = new ConfigLoader([new ConfigV2ArraySource(['a' => 1])])->load();
        $target = $this->workspace . '/compiled.php';
        new ConfigCompiler()->export($config, $target);
        $content = (string) file_get_contents($target);
        $expectedPrefix = "<?php\n\ndeclare(strict_types=1);\n\n"
            . '/* Compiled application configuration (ZEF Framework v' . ZefVersion::VERSION
            . '). Do not edit. Contains resolved secrets — keep out of version control, chmod 600. */'
            . "\n\n";
        self::assertStringStartsWith($expectedPrefix, $content, 'compiled header must be byte-exact');
        self::assertStringContainsString("'a' => 1,", $content);
        self::assertStringEndsWith(");\n", $content);
    }

    public function testCompilerRejectsDotBasenames(): void
    {
        $config = new ConfigLoader([new ConfigV2ArraySource(['a' => 1])])->load();
        foreach (['.', '..'] as $basename) {
            try {
                new ConfigCompiler()->export($config, $this->workspace . '/' . $basename);
                self::fail("Basename '{$basename}' must be rejected.");
            } catch (InvalidConfigurationException $e) {
                self::assertSame(
                    "Invalid config compile target '" . $this->workspace . '/' . $basename . "'.",
                    $e->getMessage(),
                );
            }
        }
    }

    public function testCompilerRejectsDirectoryTarget(): void
    {
        mkdir($this->workspace . '/adir');
        $config = new ConfigLoader([new ConfigV2ArraySource(['a' => 1])])->load();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Failed to publish compiled config');
        new ConfigCompiler()->export($config, $this->workspace . '/adir');
    }

    // ---- ConfigLoader: exact ctor messages + normalization --------------------

    public function testLoaderNonSourceMessageIsExact(): void
    {
        try {
            // @phpstan-ignore argument.type (deliberately wrong element type)
            new ConfigLoader(['not-a-source']);
            self::fail('Non-source element must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'Config sources must implement ConfigSourceInterface, got string.',
                $e->getMessage(),
            );
        }
    }

    public function testLoaderInvalidNameMessageIsExact(): void
    {
        $path = $this->writeSource('<?php return [];');

        try {
            new ConfigLoader([new PhpFileConfigSource($path, 'bad name!')]);
            self::fail('Invalid source name must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid config source name 'bad name!'.", $e->getMessage());
        }
    }

    public function testLoaderNormalizesStringKeyedSourceArrays(): void
    {
        $source = new ConfigV2ArraySource(['app' => ['name' => 'toko']], 'keyed');
        // @phpstan-ignore argument.type (deliberately string-keyed source array)
        $loader = new ConfigLoader(['weird-key' => $source]);
        self::assertSame(['keyed'], $loader->sourceNames());
        self::assertSame(['app' => ['name' => 'toko']], $loader->raw());
    }

    public function testSecretViolationsAreReportedInSortedTreeOrder(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('zulu', ConfigValueType::String),
            new ConfigKey('alpha', ConfigValueType::String),
        ]);
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["zulu" => "%secret:no-z%", "alpha" => "%secret:no-a%"];',
            'order.php',
        ));

        try {
            new ConfigLoader([$source], new FileSecretsProvider($this->workspace), $schema)->load();
            self::fail('Unresolved secrets must fail the load.');
        } catch (ConfigValidationException $e) {
            self::assertSame(['alpha', 'zulu'], array_map(
                static fn (ConfigViolation $v): string => $v->key,
                $e->violations(),
            ), 'secret violations must follow sorted tree order, not source order');
        }
    }

    public function testApplyDefaultsDoesNotInjectNullsForMissingDefaults(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('port', ConfigValueType::Int, default: 5432),
            new ConfigKey('req.name', ConfigValueType::String, required: true),
        ]);
        $source = new PhpFileConfigSource($this->writeSource(
            '<?php return ["req" => ["name" => "x"]];',
            'nodefault.php',
        ));
        $config = new ConfigLoader([$source], null, $schema)->load();
        self::assertTrue($config->has('port'));
        self::assertFalse($config->has('req.missing_child'));
        self::assertSame(['port', 'req.name'], $config->keys());
    }

    // ---- ConfigKey: boundaries + exact messages --------------------------------

    public function testMinMaxBoundaryEqualsIsAccepted(): void
    {
        new ConfigKey('k', ConfigValueType::Int, default: 5, min: 5, max: 5);
        new ConfigKey('k', ConfigValueType::Float, min: 1.5, max: 1.5);
        $this->expectNotToPerformAssertions();
    }

    public function testDefaultTypeMismatchMessageIsExact(): void
    {
        try {
            new ConfigKey('k', ConfigValueType::Int, default: 'abc');
            self::fail('Non-numeric default must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                "Config key 'k' declares a default that does not match its type: string('abc').",
                $e->getMessage(),
            );
        }
    }

    public function testOutOfBoundsDefaultAcceptsExactBoundaries(): void
    {
        new ConfigKey('k', ConfigValueType::Int, default: 5, min: 5);
        new ConfigKey('k2', ConfigValueType::Int, default: 10, max: 10);
        $this->expectNotToPerformAssertions();
    }

    // ---- ConfigValueType: describe/coerce/accept every arm ----------------------

    public function testDescribeCoversEveryArm(): void
    {
        self::assertSame('null', ConfigValueType::describe(null));
        self::assertSame('bool(true)', ConfigValueType::describe(true));
        self::assertSame('bool(false)', ConfigValueType::describe(false));
        self::assertSame('int(5)', ConfigValueType::describe(5));
        self::assertSame('float(1.5)', ConfigValueType::describe(1.5));
        self::assertSame("string('abc')", ConfigValueType::describe('abc'));
        self::assertSame('array(1)', ConfigValueType::describe(['x' => 1]));
        self::assertSame('object(stdClass)', ConfigValueType::describe(new \stdClass()));
    }

    public function testCoerceCoversEveryArm(): void
    {
        self::assertSame(7, ConfigValueType::Int->coerce('7'));
        self::assertSame(7.5, ConfigValueType::Float->coerce('7.5'));
        self::assertTrue(ConfigValueType::Bool->coerce('true'));
        self::assertSame('s', ConfigValueType::String->coerce('s'));
        self::assertSame(['a'], ConfigValueType::Array->coerce(['a']));
        self::assertSame(ConfigV2Mode::Read, ConfigValueType::Enum->coerce('read', ConfigV2Mode::class));
    }

    public function testRenderStringTruncatesAtExactly65Characters(): void
    {
        $exact65 = str_repeat('z', 65);
        self::assertSame(
            "string('" . str_repeat('z', 61) . "...')",
            ConfigValueType::describe($exact65),
            '65 chars must truncate (boundary 64 keeps full, 65 truncates)',
        );
    }

    // ---- ConfigSchemaValidator: boundary values are accepted ---------------------

    public function testBoundsAcceptExactBoundaryValues(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(
            new ConfigKey('port', ConfigValueType::Int, min: 1, max: 65535),
            new ConfigKey('ratio', ConfigValueType::Float, min: 0.5, max: 1.5),
            new ConfigKey('optional', ConfigValueType::Int),
        );
        // Exact boundaries + complete-but-optional-missing must be clean.
        self::assertSame(
            [],
            $validator->validate(['port' => 1, 'ratio' => 1.5], $schema),
        );
        self::assertSame(
            [],
            $validator->validate(['port' => 65535, 'ratio' => 0.5, 'optional' => 9], $schema),
        );
    }

    // ---- ConfigSchema: constructor normalizes keyed arrays ------------------------

    public function testSchemaNormalizesKeyedArraysToKeys(): void
    {
        $a = new ConfigKey('a', ConfigValueType::String);
        $b = new ConfigKey('b', ConfigValueType::Int);
        $schema = new ConfigSchema(['k1' => $a, 'k2' => $b]);
        self::assertSame([$a, $b], $schema->keys, 'schema keys must be a list regardless of input keys');
    }

    // ---- Config bag: unit enum accessor guard --------------------------------------

    public function testEnumAccessorRejectsUnitEnumWithInvalidArgument(): void
    {
        $config = new Config(['mode' => 'read']);

        try {
            // @phpstan-ignore argument.type (deliberately non-enum class)
            $config->enum('mode', ConfigV2Unit::class);
            self::fail('Unit enum must be rejected with InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires a backed enum class', $e->getMessage());
        }
    }

    // ---- DottedPaths: int keys render as strings in leaf paths ---------------------

    public function testLeafPathsStringifyIntegerKeys(): void
    {
        self::assertSame(['7'], DottedPaths::leafPaths([7 => 'x']));
        self::assertSame(
            ['a.0', 'a.1'],
            DottedPaths::leafPaths(['a' => ['x', 'y']]),
        );
    }

    // ---- EnvConfigSource: prefix grammar boundaries + value shapes -------------------

    public function testEnvPrefixGrammarBoundaries(): void
    {
        // Lowercase anywhere: rejected (caret anchored).
        try {
            new EnvConfigSource('fooBAR');
            self::fail('Prefix must be anchored to uppercase start.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid environment prefix 'fooBAR'.", $e->getMessage());
        }

        // Missing terminator: rejected (dollar anchored).
        try {
            new EnvConfigSource('ZEFweird');
            self::fail('Prefix must be anchored to uppercase end.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid environment prefix 'ZEFweird'.", $e->getMessage());
        }
        self::assertInstanceOf(EnvConfigSource::class, new EnvConfigSource('ZEF'));
    }

    public function testEnvSourceReadsProcessEnvironmentEnumeration(): void
    {
        putenv('ZEF_SWEEP_ENVONLY=from-process-env');

        try {
            $values = new EnvConfigSource()->load();
            self::assertSame('from-process-env', $values['sweep_envonly'] ?? null);
        } finally {
            putenv('ZEF_SWEEP_ENVONLY');
        }
    }

    public function testEnvSourceSkipsNonScalarValues(): void
    {
        $backup = $_ENV;
        $_ENV['ZEF_ARRAY_VALUE'] = ['not', 'scalar'];

        try {
            $values = new EnvConfigSource()->load();
            self::assertArrayNotHasKey('array.value', $values);
        } finally {
            $_ENV = $backup;
        }
    }

    // ---- FileSecretsProvider: grammar guard wins over filesystem ---------------------

    public function testSecretGrammarRejectsKeysEvenWhenFileExists(): void
    {
        file_put_contents($this->workspace . '/UPPER', 'should-not-leak');
        file_put_contents($this->workspace . '/a..b', 'traversal-looking');
        $provider = new FileSecretsProvider($this->workspace);
        self::assertNull($provider->get('UPPER'), 'uppercase key violates grammar even with file present');
        self::assertNull($provider->get('a..b'), 'double-dot key violates grammar even with file present');
    }

    // ---- Sources: parse-error code is exactly zero + directory guard ------------------

    public function testPhpFileParseErrorHasZeroCode(): void
    {
        $path = $this->writeSource('<?php return [unterminated', 'broken.php');
        $source = new PhpFileConfigSource($path);

        try {
            $source->load();
            self::fail('Parse error must be wrapped.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(0, $e->getCode(), 'wrapped exceptions keep code 0');
            self::assertSame("Config source file '{$path}' failed to load", substr($e->getMessage(), 0, strlen("Config source file '{$path}' failed to load")));
        }
    }

    public function testCompiledSourceRejectsDirectoryPath(): void
    {
        mkdir($this->workspace . '/cdir');
        $source = new CompiledConfigSource($this->workspace . '/cdir');

        try {
            $source->load();
            self::fail('Directory must fail is_file check.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(
                "Compiled config file '" . $this->workspace . "/cdir' does not exist or is not readable.",
                $e->getMessage(),
            );
        }
    }

    private function writeSource(string $body, string $name = 'source.php'): string
    {
        $path = $this->workspace . '/' . $name;
        file_put_contents($path, $body);

        return $path;
    }
}
