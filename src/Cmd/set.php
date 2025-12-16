<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class set extends KeyValCmd {
    public function type(): Type {
        return Type::STRING;
    }

    public function flags(): int {
        return self::WRITE;
    }
}
