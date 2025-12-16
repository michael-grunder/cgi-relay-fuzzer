<?php

namespace Mgrunder\Fuzzer;

require_once __DIR__ . '/' . '../vendor/autoload.php';

class HttpCmd extends HttpRequest {
    private string $cmd;

    public function __construct(string $host, int $port, string $cmd) {
        $this->cmd = $cmd;
        parent::__construct($host, $port, "www/cmd.php");
    }

    public function exec(array $args = []): array {
        $res = [];

        $args = [
            'class' => null,
            'cmd' => $this->cmd,
            'args' => $args
        ];

        foreach (['redis', 'relay'] as $class) {
            $args['class'] = $class;
            $res[] = parent::exec($args);
        }

        return $res;
    }
}
