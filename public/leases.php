<?php

declare(strict_types=1);

use Mgrunder\Fuzzer\Http\Controller\LeasesController;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
$response = (new LeasesController())($request);
$response->send();
