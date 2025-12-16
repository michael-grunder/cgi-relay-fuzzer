<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

abstract class KeysCmd extends Cmd {
    public function args(): array {
        return [
            $this->cfg->randomKeys($this->type())
        ];
    }
}
