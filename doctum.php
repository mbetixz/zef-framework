<?php

/**
 * ZEF Framework — Doctum API documentation configuration (composer docs).
 *
 *   composer docs
 *
 * Renders the public API of the four hexagonal layers into build/api.
 */

declare(strict_types=1);

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->in([
        __DIR__ . '/src/Domain',
        __DIR__ . '/src/Application',
        __DIR__ . '/src/Infrastructure',
        __DIR__ . '/src/Adapters',
    ])
    ->name('*.php')
    ->notPath('Compat');

return new Doctum($iterator, [
    'title'            => 'ZEF Framework API',
    'build_dir'        => __DIR__ . '/build/api',
    'cache_dir'        => __DIR__ . '/build/api-cache',
    'source_dir'       => __DIR__ . '/src/',
    'remote_repository' => new GitHubRemoteRepository('mbetixz/zef-framework', __DIR__),
    'default_opened_level' => 2,
]);
