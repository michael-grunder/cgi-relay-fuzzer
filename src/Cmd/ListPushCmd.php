<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

abstract class ListPushCmd extends KeyNMemsCmd {
    public function type(): Type {
        return Type::LIST;
    }

    public function flags(): int {
        return self::WRITE;
    }
}
