<?php

namespace Mgrunder\Fuzzer\Cmd;

require_once __DIR__ . '/' . '../../vendor/autoload.php';

abstract class KeyNMemsCmd extends Cmd {
    public function args(): array {
        return [
            $this->cfg->randomKey($this->type()),
            ...$this->cfg->randomMembers()
        ];
    }
}
