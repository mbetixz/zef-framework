<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Infrastructure layer: outbound adapters)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Strictly validated connection configuration value object.
 *
 * Every field is checked in {@see self::fromArray()} so misconfiguration
 * surfaces at construction time with an exact message instead of deep
 * inside a driver connect call. Supported drivers: mysql, pgsql, sqlite.
 */
final class ConnectionConfig
{
    public const array DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const int DEFAULT_MYSQL_PORT = 3306;
    private const int DEFAULT_PGSQL_PORT = 5432;

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        public readonly string $driver,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly string $dbname,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly ?string $charset,
        public readonly array $options,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $driver = $config['driver'] ?? null;
        if (!is_string($driver) || !in_array($driver, self::DRIVERS, true)) {
            throw new ConnectionException(
                "Unknown database driver '" . (is_scalar($driver) ? (string) $driver : get_debug_type($driver))
                . "' (allowed: " . implode(', ', self::DRIVERS) . ').',
            );
        }

        $dbname = $config['dbname'] ?? null;
        if (!is_string($dbname) || $dbname === '') {
            throw new ConnectionException("Database name (dbname) must be a non-empty string for driver '{$driver}'.");
        }
        if ($driver === 'sqlite' && $dbname !== ':memory:' && preg_match('/^[\x20-\x7E]{1,4096}$/', $dbname) !== 1) {
            throw new ConnectionException("SQLite database path must be ':memory:' or printable ASCII (got non-printable input).");
        }

        $host = null;
        $port = null;
        if ($driver !== 'sqlite') {
            $host = $config['host'] ?? null;
            if (!is_string($host) || $host === '' || strlen($host) > 255 || preg_match('/^\S+$/', $host) !== 1) {
                throw new ConnectionException("Host must be a non-empty string without whitespace for driver '{$driver}'.");
            }
            $port = self::normalizePort($config['port'] ?? null, $driver);
        }

        $user = $config['user'] ?? null;
        if ($user !== null && !is_string($user)) {
            throw new ConnectionException('User must be a string or null.');
        }
        $password = $config['password'] ?? null;
        if ($password !== null && !is_string($password)) {
            throw new ConnectionException('Password must be a string or null.');
        }

        $charset = $config['charset'] ?? null;
        if ($charset !== null && !is_string($charset)) {
            throw new ConnectionException('Charset must be a string or null.');
        }
        if ($charset === null && $driver === 'mysql') {
            $charset = 'utf8mb4';
        }

        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw new ConnectionException('Options must be an array.');
        }
        $options = self::normalizeOptions($options);

        return new self($driver, $host, $port, $dbname, $user, $password, $charset, $options);
    }

    public function dsn(): string
    {
        return match ($this->driver) {
            'mysql' => 'mysql:host=' . $this->host . ';port=' . $this->port
                . ';dbname=' . $this->dbname . ($this->charset !== null ? ';charset=' . $this->charset : ''),
            'pgsql' => 'pgsql:host=' . $this->host . ';port=' . $this->port
                . ';dbname=' . $this->dbname . ($this->charset !== null ? ';options=\'--client_encoding=' . $this->charset . '\'' : ''),
            'sqlite' => 'sqlite:' . $this->dbname,
            default => throw new ConnectionException("Unsupported database driver '{$this->driver}'."),
        };
    }

    private static function normalizePort(mixed $port, string $driver): int
    {
        if ($port === null) {
            return $driver === 'mysql' ? self::DEFAULT_MYSQL_PORT : self::DEFAULT_PGSQL_PORT;
        }
        if (is_int($port)) {
            $value = $port;
        } elseif (is_string($port) && preg_match('/^\d{1,5}$/', $port) === 1) {
            $value = (int) $port;
        } else {
            throw new ConnectionException('Port must be an int or a numeric string (1-65535).');
        }
        if ($value < 1 || $value > 65535) {
            throw new ConnectionException("Port {$value} is out of range (1-65535).");
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function normalizeOptions(array $options): array
    {
        $known = ['persistent', 'timeout'];
        $normalized = [];
        foreach ($options as $key => $value) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                throw new ConnectionException(
                    "Unknown connection option '" . (is_string($key) ? $key : get_debug_type($key))
                    . "' (allowed: " . implode(', ', $known) . ').',
                );
            }
            if ($key === 'persistent' && !is_bool($value)) {
                throw new ConnectionException('Option persistent must be a bool.');
            }
            if ($key === 'timeout' && (!is_int($value) && !is_float($value))) {
                throw new ConnectionException('Option timeout must be an int or float.');
            }
            if ($key === 'timeout' && (is_float($value) ? $value : (float) $value) <= 0.0) {
                throw new ConnectionException('Option timeout must be greater than zero.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
