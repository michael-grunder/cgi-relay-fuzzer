<?php

declare(strict_types=1);

use Mgrunder\Fuzzer\Http\Controller\EndpointsController;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI === 'cli') {
    fprintf(STDERR, "This script is intended to be run from a web server.\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
$response = (new EndpointsController())($request);
$response->send();
