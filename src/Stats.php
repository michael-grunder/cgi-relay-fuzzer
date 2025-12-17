<?php

namespace Mgrunder\Fuzzer;

class Stats extends HttpRequest {
    public function __construct(string $host, int $port) {
        parent::__construct($host, $port, 'stats.php');
    }
}
