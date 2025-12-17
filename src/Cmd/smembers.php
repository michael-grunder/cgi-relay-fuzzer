<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

class smembers extends KeyCmd {
    public function type(): Type {
        return Type::SET;
    }

    public function flags(): int {
        return Cmd::READ;
    }

    public function args(): array {
        return [
            $this->cfg->randomKey($this->type())
        ];
    }

    protected function cannonicalize(mixed $res): mixed {
        return self::cannonicalizeArray($res);
    }
}
