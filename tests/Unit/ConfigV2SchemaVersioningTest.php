<?php

declare(strict_types=1);

// ZEF Framework v2.23.0 — Configuration System v2 (issue #60 P4): schema
// versioning and the ordered migration ladder — version guards, in-order
// application, missing-hop and downgrade rejection, and the loader
// integration (migrate BEFORE secrets/validation/defaults).

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigMigrator;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\SecretsProviderInterface;
use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * @internal
 */
final class ConfigV2SchemaVersioningTest extends TestCase
{
    // ---- ConfigSchema version field -------------------------------------------

    public function testSchemaDefaultsToCurrentVersion(): void
    {
        $schema = new ConfigSchema([new ConfigKey('app.name', ConfigValueType::String)]);
        self::assertSame(ConfigSchema::CURRENT_VERSION, $schema->version);
        self::assertSame(1, $schema->version);
    }

    public function testSchemaAcceptsExplicitFutureVersion(): void
    {
        $schema = new ConfigSchema([new ConfigKey('app.name', ConfigValueType::String)], true, 2);
        self::assertSame(2, $schema->version);
    }

    public function testSchemaRejectsNonPositiveVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfigSchema([], true, 0);
    }

    // ---- ConfigMigrator ladder -------------------------------------------------

    public function testStepsApplyInRegistrationOrder(): void
    {
        $migrator = new ConfigMigrator();
        $trace = [];
        $migrator->to(2, static function (array $v) use (&$trace): array {
            $trace[] = 'first';
            $legacy = $v['legacy'] ?? 'default';
            $v['renamed'] = is_string($legacy) ? $legacy : 'default';
            unset($v['legacy']);

            return $v;
        });
        $migrator->to(2, static function (array $v) use (&$trace): array {
            $trace[] = 'second';
            $renamed = $v['renamed'] ?? '';
            $v['renamed'] = (is_string($renamed) ? $renamed : '') . '!';

            return $v;
        });

        $out = $migrator->migrate(['legacy' => 'value'], 1, 2);
        self::assertSame(['renamed' => 'value!'], $out);
        self::assertSame(['first', 'second'], $trace);
        self::assertSame([2 => 2], $migrator->stepCounts());
    }

    public function testMultiHopLadderWalksEveryVersion(): void
    {
        $migrator = new ConfigMigrator();
        $migrator->to(2, static function (array $v): array {
            $v['v'] = (is_int($n = $v['v'] ?? 0) ? $n : 0) + 1;

            return $v;
        });
        $migrator->to(3, static function (array $v): array {
            $v['v'] = (is_int($n = $v['v'] ?? 0) ? $n : 0) + 10;

            return $v;
        });

        self::assertSame(['v' => 11], $migrator->migrate(['v' => 0], 1, 3));
        self::assertSame(['v' => 11], $migrator->migrate(['v' => 0], 1, 3), 'pure steps are repeatable');
    }

    public function testSameVersionIsANoOp(): void
    {
        $migrator = new ConfigMigrator();
        self::assertSame(['unchanged' => true], $migrator->migrate(['unchanged' => true], 2, 2));
    }

    public function testMissingHopFailsFast(): void
    {
        $migrator = new ConfigMigrator();
        $migrator->to(2, static fn (array $v): array => $v);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('No registered config migration step to schema version 3');
        $migrator->migrate(['k' => 1], 1, 3);
    }

    public function testDowngradeIsRejected(): void
    {
        $migrator = new ConfigMigrator();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('downgrade migration is not supported');
        $migrator->migrate(['k' => 1], 3, 2);
    }

    public function testNonPositiveVersionsAreRejected(): void
    {
        $migrator = new ConfigMigrator();
        $this->expectException(\InvalidArgumentException::class);
        $migrator->migrate(['k' => 1], 0, 1);
    }

    public function testStepReturningNonArrayFailsFast(): void
    {
        $migrator = new ConfigMigrator();
        $step // @param array<array-key,mixed> $v
            = static function (array $v): string {
                return 'oops'; // deliberately wrong return shape
            };
        $migrator->to(2, $step); // @phpstan-ignore argument.type

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must return an array, got string');
        $migrator->migrate(['k' => 1], 1, 2);
    }

    public function testOriginVersionCannotHaveIncomingSteps(): void
    {
        $migrator = new ConfigMigrator();
        $this->expectException(\InvalidArgumentException::class);
        $migrator->to(1, static fn (array $v): array => $v);
    }

    // ---- ConfigLoader integration ----------------------------------------------

    public function testLoaderMigratesBeforeValidating(): void
    {
        $schema = new ConfigSchema([
            new ConfigKey('database.host', ConfigValueType::String),
        ], false, 2);
        $migrator = new ConfigMigrator();
        $migrator->to(2, static function (array $v): array {
            $host = $v['db_host'] ?? null;
            if (is_string($host)) {
                $database = $v['database'] ?? [];
                $v['database'] = [...(is_array($database) ? $database : []), 'host' => $host];
                unset($v['db_host']);
            }

            return $v;
        });

        $loader = new ConfigLoader(
            [new ConfigV2ArraySource(['db_host' => 'legacy.internal'], 'legacy')],
            null,
            $schema,
            $migrator,
            1,
        );
        $config = $loader->load();

        self::assertSame('legacy.internal', $config->string('database.host'));
    }

    public function testLoaderWithoutMigratorIsUnchanged(): void
    {
        $loader = new ConfigLoader([new ConfigV2ArraySource(['app' => ['name' => 'zef']], 'app')]);
        self::assertSame('zef', $loader->load()->string('app.name'));
    }

    public function testLoaderRejectsMigratorWithoutSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a schema');
        new ConfigLoader([new ConfigV2ArraySource([], 'app')], null, null, new ConfigMigrator());
    }

    public function testLoaderRejectsSourceVersionWithoutMigrator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a migrator');
        new ConfigLoader([new ConfigV2ArraySource([], 'app')], null, null, null, 1);
    }

    public function testLoaderRejectsNonPositiveSourceVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfigLoader(
            [new ConfigV2ArraySource([], 'app')],
            null,
            new ConfigSchema([]),
            new ConfigMigrator(),
            0,
        );
    }

    public function testLoaderRejectsDataNewerThanSchema(): void
    {
        $loader = new ConfigLoader(
            [new ConfigV2ArraySource([], 'app')],
            null,
            new ConfigSchema([], true, 1),
            new ConfigMigrator(),
            2,
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('downgrade migration is not supported');
        $loader->load();
    }

    public function testLoaderMissingHopSurfacesAsConfigError(): void
    {
        $loader = new ConfigLoader(
            [new ConfigV2ArraySource([], 'app')],
            null,
            new ConfigSchema([], true, 3),
            new ConfigMigrator(), // no steps registered
            1,
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('No registered config migration step to schema version 2');
        $loader->load();
    }

    public function testMigrationRunsBeforeSecretResolution(): void
    {
        $schema = new ConfigSchema([new ConfigKey('database.password', ConfigValueType::String)], false, 2);
        $migrator = new ConfigMigrator();
        $migrator->to(2, static function (array $v): array {
            $pass = $v['db_pass'] ?? null;
            if (is_string($pass)) {
                $database = $v['database'] ?? [];
                $v['database'] = [...(is_array($database) ? $database : []), 'password' => $pass];
                unset($v['db_pass']);
            }

            return $v;
        });
        $secrets = new class implements SecretsProviderInterface {
            #[\Override]
            public function get(string $key): ?string
            {
                return $key === 'database_password' ? 'resolved-secret' : null;
            }
        };

        $loader = new ConfigLoader(
            [new ConfigV2ArraySource(['db_pass' => '%secret:database_password%'], 'legacy')],
            $secrets,
            $schema,
            $migrator,
            1,
        );
        $config = $loader->load();

        // The step moved the secret REFERENCE; resolution then replaced it.
        self::assertSame('resolved-secret', $config->string('database.password'));
    }
}
