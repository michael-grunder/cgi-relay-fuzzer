<?php

namespace Mgrunder\Fuzzer;

class Clients extends HttpRequest {
    private \Redis $redis;

    public function __construct(string $host, int $port) {
        $this->redis = new \Redis(['host' => 'localhost', 'port' => 6379]);

        parent::__construct($host, $port, 'clients.php');
    }

    public function clients(): array {
        return $this->exec([]);
    }

    public function ids(): array {
        $res = [];

        $all = $this->clients();

        foreach ($all as $client) {
            $res[] = $client['id'];
        }

        return $res;
    }

    public function kill(?int $num): int {
        $total = 0;

        $ids   = array_flip($this->ids());
        if ( ! $ids)
            return 0;

        $num ??= rand(1, count($ids));
        $ids   = array_rand($ids, $num);
        if ( ! is_array($ids))
            $ids = [$ids];

        foreach ($ids as $id) {
            $total += $this->redis->rawCommand('CLIENT', 'KILL', 'ID', $id);
        }

        return $total;
    }
}
