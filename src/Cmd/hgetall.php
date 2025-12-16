<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class hgetall extends KeyCmd {
    public function type(): Type {
        return Type::HASH;
    }

    public function flags(): int {
        return self::READ;
    }
}
