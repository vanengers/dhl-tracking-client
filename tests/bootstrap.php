<?php

declare(strict_types=1);

use GPWebshops\ShopHub\Dhl\Tests\Support\DotEnv;

require dirname(__DIR__) . '/vendor/autoload.php';

// .env is only used by the sandbox tests (--group sandbox); it is never required.
DotEnv::load(dirname(__DIR__) . '/.env');
