<?php

declare(strict_types=1);

use Infection\Config\InfectionConfig;

return InfectionConfig::make()
    ->withSourceDirectories(['src'])
    ->withTestFramework('phpunit')
    ->withLogs(['text' => 'build/infection.log']);
