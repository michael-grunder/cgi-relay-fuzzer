<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class FlushCmd extends EmptyCmd {
    public function type(): Type {
        return Type::ANY;
    }

    public function flags(): int {
        return self::WRITE | self::FLUSH;
    }
}
