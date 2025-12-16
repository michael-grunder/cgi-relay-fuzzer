<?php

if (php_sapi_name() === 'cli') {
    echo "This script cannot be run from the command line.";
    exit(1);
}

function panicError($fmt, ...$args) {
    echo json_encode([
        'status' => 'error',
        'error' => sprintf($fmt, ...$args)
    ], JSON_PRETTY_PRINT);
}

$class = $_GET['class'] ?? null;
$cmd = $_GET['cmd'] ?? null;
$args = $_GET['args'] ?? [];
if ( ! is_array($args))
    $args = [$args];

header('Content-Type: application/json');

if ($class === 'relay') {
    $client = new \Relay\Relay;
} else if ($class === 'redis') {
    $client = new \Redis;
} else {
    panicError("Unknown client: %s", var_export($class, true));
    exit(1);
}

try {
    $client->connect('localhost', 6379);

    $res = $client->$cmd(...$args);
    echo json_encode([
        'status' => 'ok',
        'pid' => getmypid(),
        'class' => $class,
        'result' => $res
    ], JSON_PRETTY_PRINT);
} catch (\Exception $ex) {
    panicError("Exception: %s", $ex->getMessage());
}
