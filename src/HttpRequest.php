<?php

namespace Mgrunder\Fuzzer;

require_once __DIR__ . '/' . '../vendor/autoload.php';

class HttpRequest {
    private string $host;
    private int $port;
    private string $endpoint;
    private mixed $traceCallback = null;

    public function __construct(string $host, int $port, string $endpoint) {
        $this->host = $host;
        $this->port = $port;
        $this->endpoint = $endpoint;
    }

    private function buildUri(array $args): string {
        return sprintf("http://%s:%d/%s?%s", $this->host, $this->port,
                       $this->endpoint, http_build_query($args));
    }

    public function setTraceCallback(?callable $traceCallback): void {
        $this->traceCallback = $traceCallback;
    }

    private function trace(string $uri): void {
        if ($this->traceCallback === null) {
            return;
        }

        try {
            ($this->traceCallback)($uri);
        } catch (\Throwable) {
            // Swallow trace failures so they don't impact the request flow.
        }
    }

    public function exec(array $args): array {
        $uri = $this->buildUri($args);
        $this->trace($uri);

        $res = @file_get_contents($uri);
        if ( ! $res)
            throw new \Exception("Failed to execute '$uri'");

        $dec = @json_decode($res, true);
        if ($dec === null)
            throw new \Exception("Failed to decode JSON response from '$uri'");

        return $dec;
    }
}
