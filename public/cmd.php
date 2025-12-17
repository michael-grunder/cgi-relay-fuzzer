<?php

declare(strict_types=1);

use Mgrunder\Fuzzer\Http\Controller\CommandController;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This script cannot be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
$response = (new CommandController())($request);
$response->send();
