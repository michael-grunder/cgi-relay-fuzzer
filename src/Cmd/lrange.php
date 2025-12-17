<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class lrange extends Cmd {
    public function type(): Type {
        return Type::LIST;
    }

    public function flags(): int {
        return self::READ;
    }

    public function args(): array {
        return [$this->cfg->randomKey($this->type()), 0, -1];
    }
}
