<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class hmset extends Cmd {
    public function type(): Type {
        return Type::HASH;
    }

    public function flags(): int {
        return self::WRITE;
    }

    public function args(): array {
        return [
            $this->cfg->randomKey($this->type()),
            $this->cfg->randomFields(),
        ];
    }
}
