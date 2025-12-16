<?php

if (php_sapi_name() !== 'cli')
    header('Content-Type: application/json');

try {
    $redis = new \Redis(['host' => 'localhost', 'port' => 6379]);

    $clients = array_filter(
        $redis->client('list'),
        fn ($v) => $v['lib-name'] === 'relay'
    );

    echo json_encode($clients, JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]) . "\n";
}
