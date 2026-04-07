<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

// Set environment for tests
putenv('APP_ENV=test');
putenv('KERNEL_CLASS=App\Kernel');

