<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class get extends KeyCmd {
    public function type(): Type {
        return Type::STRING;
    }

    public function flags(): int {
        return self::READ;
    }
}
