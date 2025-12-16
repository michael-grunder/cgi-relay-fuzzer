<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class sismember extends KeyMemCmd {
    public function type(): Type {
        return Type::SET;
    }

    public function flags(): int {
        return self::READ;
    }
}
