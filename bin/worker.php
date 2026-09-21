<?php

/**
 * ZEF Framework v2.7.0 — RoadRunner HTTP worker entrypoint.
 *
 * Requires the RoadRunner PHP bridge (not bundled, zero-composer fallback
 * keeps the framework free of hard dependencies):
 *
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 * .rr.yaml:
 *   server:
 *     command: "php bin/worker.php"
 *   http:
 *     address: 0.0.0.0:8080
 *
 * Run:
 *   rr serve
 */

declare(strict_types=1);

require __DIR__ . '/../autoload/zef_autoload.php';

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "ZEF Framework v" . \Zef\Framework\Foundation\ZefVersion::VERSION . " requires PHP >= 8.4\n");
    exit(1);
}

if (!class_exists(\Spiral\RoadRunner\Http\HttpWorker::class)) {
    fwrite(STDERR,
        "RoadRunner bridge not installed.\n"
        . "Run: composer require spiral/roadrunner-http nyholm/psr7\n"
    );
    exit(1);
}

$psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
$worker = new \Spiral\RoadRunner\Http\HttpWorker(
    \Spiral\RoadRunner\Worker::create(),
    $psr17,
    $psr17,
);

$debug  = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
$app    = \Zef\App\Bootstrap::createApp($debug);
$runtime = new \Zef\Framework\Runtime\RoadRunnerRuntime(
    $app,
    new \Zef\Framework\Runtime\RoadRunnerWorkerAdapter($worker),
    maxJobs: (int) (getenv('ZEF_WORKER_MAX_JOBS') ?: 0),
    memoryLimitBytes: (int) (getenv('ZEF_WORKER_MEMORY_LIMIT') ?: 0),
);

exit($runtime->run());
