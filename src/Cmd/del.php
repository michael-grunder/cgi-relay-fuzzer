<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class del extends KeysCmd {
    public function type(): Type {
        return Type::ANY;
    }

    public function flags(): int {
        return self::WRITE | self::DEL;
    }
}
