<?php

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$dir = __DIR__ . '/src';
$iterator = Finder::create()->files()->name('*.php')->in($dir);

return new Doctum($iterator, [
    'title' => 'ZEF Framework API',
    'build_dir' => __DIR__ . '/build/docs',
    'cache_dir' => __DIR__ . '/build/cache',
    'source_dir' => $dir,
    'default_opened_level' => 2,
]);
