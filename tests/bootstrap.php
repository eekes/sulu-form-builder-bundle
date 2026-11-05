<?php

declare(strict_types=1);

require \dirname(__DIR__).'/vendor/autoload.php';

// The test kernel keeps everything it writes inside tests/Application/var.
(new Symfony\Component\Filesystem\Filesystem())->remove(__DIR__.'/Application/var');
