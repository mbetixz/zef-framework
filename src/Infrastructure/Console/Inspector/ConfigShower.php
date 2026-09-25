<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef config:show [key]` — dumps the aggregated
 * configuration of the (already booted) app. Provider configs routinely
 * contain Closures (factories), so the JSON encoder replaces non-serialisable
 * values with deterministic placeholders instead of throwing.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Console\ConsoleIO;

final readonly class ConfigShower
{
    /** Sentinel distinguishes "key missing" from a legitimately stored null. */
    private const string MISSING = '__zef_config_missing__';

    public function __construct(
        private ConfigAggregator $aggregator,
        private ConsoleIO $io,
    ) {}

    public function run(?string $key): int
    {
        if ($key === null) {
            $this->io->out($this->encode($this->aggregator->all()));

            return 0;
        }

        $value = $this->aggregator->get($key, self::MISSING);
        if ($value === self::MISSING) {
            $this->io->err("Config key '{$key}' is not set.");

            return 1;
        }

        $this->io->out($this->encode($value));

        return 0;
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $this->jsonSafe($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function jsonSafe(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return '<closure>';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // (string) is a no-op post PHP array-key normalisation; kept for
                // JSON key stability. @infection-ignore-all
                $out[(string) $k] = $this->jsonSafe($v);
            }

            return $out;
        }
        if (is_object($value)) {
            return '<object ' . $value::class . '>';
        }
        if (is_resource($value)) {
            return '<resource>';
        }

        return $value;
    }
}
