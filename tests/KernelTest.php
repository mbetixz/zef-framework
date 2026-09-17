<?php

use PHPUnit\Framework\TestCase;
use Zef\App\Kernel;

class KernelTest extends TestCase
{
    public function test_kernel_version()
    {
        $kernel = new Kernel();
        $this->assertEquals('1.0.0', $kernel->getVersion());
    }
}
